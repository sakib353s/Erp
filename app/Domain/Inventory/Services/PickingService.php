<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\PickList;
use App\Domain\Inventory\PickListLine;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\PutawayList;
use App\Domain\Inventory\PutawayListLine;
use App\Domain\Inventory\StockBatch;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §04-44 — pick lists and putaway lists.
 *
 * Two documents in the same shape, pointing opposite ways:
 *
 *  · a PICK list is written *for* an order: it names the bin to walk to, how
 *    much to take, and — for batch-tracked goods under FEFO — which batch the
 *    shelf should give up first;
 *
 *  · a PUTAWAY list is written *from* a receipt: goods arrived, and somebody has
 *    to decide where they live. The decision is written back as a bin
 *    assignment, because directions are the only thing bin rows store.
 *
 * What neither document does is move stock. Quantities live in one ledger per
 * product per warehouse; a pick or putaway list that wrote its own numbers would
 * be a second truth about the same shelf, and cancelling one after a dispatch
 * would double-count. Completion says where the goods *are* — on the dock or in
 * the rack — and dispatch remains the moment the ledger changes.
 */
class PickingService
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $orders,
        protected WarehouseService $warehouses,
        protected SettingService $settings,
    ) {}

    /* ---------------------------------------------------------------- reads --- */

    /**
     * The pick register, filtered.
     *
     * @param  array{q?: string|null, status?: string|null, warehouse_id?: int|null, assigned_to?: int|null}  $filters
     */
    public function pickLists(array $filters = []): Builder
    {
        $query = PickList::query()
            ->with(['warehouse:id,name', 'order:id,order_no,status', 'assignee:id,name'])
            ->withCount('lines')
            ->recentFirst();

        return $this->applyRegisterFilters($query, $filters, isPick: true);
    }

    /** @param array{q?: string|null, status?: string|null, warehouse_id?: int|null, assigned_to?: int|null} $filters */
    public function putawayLists(array $filters = []): Builder
    {
        $query = PutawayList::query()
            ->with(['warehouse:id,name', 'receipt:id,code,challan_no', 'assignee:id,name'])
            ->withCount('lines')
            ->recentFirst();

        return $this->applyRegisterFilters($query, $filters, isPick: false);
    }

    /**
     * The two registers share a shape, so they share the filters — and only the
     * text search differs, because the number a picker remembers is the order's
     * and the number a receiver remembers is the challan's.
     *
     * @param array{q?: string|null, status?: string|null, warehouse_id?: int|null, assigned_to?: int|null} $filters
     */
    protected function applyRegisterFilters(Builder $query, array $filters, bool $isPick): Builder
    {
        $query->where('company_id', $this->context->companyId());

        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $query->where(function (Builder $inner) use ($q, $isPick) {
                $inner->where('code', 'like', "%{$q}%");

                if ($isPick) {
                    $inner->orWhereHas('order', fn (Builder $order) => $order->where('order_no', 'like', "%{$q}%"));
                } else {
                    $inner->orWhereHas('receipt', fn (Builder $receipt) => $receipt
                        ->where('code', 'like', "%{$q}%")
                        ->orWhere('challan_no', 'like', "%{$q}%"));
                }
            });
        }

        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', $status);
        }

        if (($warehouseId = $filters['warehouse_id'] ?? null) !== null && $warehouseId !== '') {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        if (($assignedTo = $filters['assigned_to'] ?? null) !== null && $assignedTo !== '') {
            $query->where('assigned_to', (int) $assignedTo);
        }

        return $query;
    }

    /**
     * Orders that still have something to pick: confirmed or later, and at least
     * one line whose delivered quantity has not caught up with what was ordered.
     */
    public function ordersToPick(?int $warehouseId = null): \Illuminate\Support\Collection
    {
        return SalesOrder::query()
            ->where('company_id', $this->context->companyId())
            ->whereIn('status', ['confirmed', 'processing', 'ready_to_ship'])
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->whereHas('lines', fn (Builder $q) => $q->whereRaw('qty > delivered_qty'))
            ->with(['customer:id,name'])
            ->withCount('lines')
            ->orderBy('order_date')
            ->limit(300)
            ->get();
    }

    /**
     * Posted receipts nobody has put away yet. A receipt whose putaway list was
     * cancelled comes back here — abandoning a walk does not mean the goods
     * walked themselves into the racks.
     */
    public function receiptsToPutAway(?int $warehouseId = null): \Illuminate\Support\Collection
    {
        $companyId = $this->context->companyId();

        $spokenFor = PutawayList::query()
            ->where('company_id', $companyId)
            ->whereNotNull('goods_receipt_id')
            ->where('status', '!=', PutawayList::STATUS_CANCELLED)
            ->select('goods_receipt_id');

        return GoodsReceipt::query()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotIn('id', $spokenFor)
            ->with(['supplier:id,name'])
            ->withCount('lines')
            ->orderByDesc('received_date')
            ->limit(300)
            ->get();
    }

    /* --------------------------------------------------------------- create --- */

    /**
     * @param  array{warehouse_id: int, sales_order_id?: int|null, notes?: string|null,
     *               lines?: array<int, array{product_id: int, quantity: float}>}  $payload
     */
    public function createPickList(array $payload, User $actor): PickList
    {
        $companyId = $this->context->companyId() ?? throw new RuntimeException('No company context.');
        $warehouse = $this->warehouseOrFail((int) $payload['warehouse_id']);

        $order = null;
        $lines = [];

        if (($orderId = $payload['sales_order_id'] ?? null) !== null && $orderId !== '') {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->with('lines')
                ->findOrFail((int) $orderId);

            $this->assertOrderCanBePicked($order);
            $this->assertNoOpenPickList($order);

            $lines = $order->lines
                ->map(fn ($line) => [
                    'product_id' => (int) $line->product_id,
                    'quantity' => round((float) $line->qty - (float) $line->delivered_qty, 4),
                ])
                ->filter(fn (array $line) => $line['quantity'] > 0.00005)
                ->values()
                ->all();

            if ($lines === []) {
                throw new RuntimeException("Order {$order->order_no} has nothing left to pick — every line is already delivered.");
            }
        } else {
            $lines = $this->cleanManualLines($payload['lines'] ?? [], $companyId);

            if ($lines === []) {
                throw new RuntimeException('A manual pick list needs at least one product and a quantity.');
            }
        }

        return DB::transaction(function () use ($companyId, $warehouse, $order, $lines, $payload, $actor) {
            $list = PickList::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'sales_order_id' => $order?->id,
                'code' => $this->nextCode('pick_list', $actor),
                'status' => PickList::STATUS_DRAFT,
                'created_by' => $actor->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            $lineNo = 0;

            foreach ($lines as $line) {
                $lineNo++;

                PickListLine::create([
                    'pick_list_id' => $list->id,
                    'product_id' => $line['product_id'],
                    'warehouse_bin_id' => $this->primaryBinId($line['product_id'], $warehouse->id),
                    'stock_batch_id' => $this->suggestedBatchId($line['product_id'], $warehouse->id),
                    'quantity' => $line['quantity'],
                    'line_no' => $lineNo,
                ]);
            }

            $this->audit->record([
                'action' => 'inventory.pick_list_created',
                'entity_type' => 'pick_list',
                'entity_id' => $list->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $list->code,
                    'warehouse_id' => $warehouse->id,
                    'sales_order_id' => $order?->id,
                    'lines' => $lineNo,
                    'source' => $order !== null ? 'order' : 'manual',
                ],
            ]);

            return $list->load('lines');
        });
    }

    /**
     * @param  array{goods_receipt_id?: int|null, warehouse_id?: int|null, notes?: string|null,
     *               lines?: array<int, array{product_id: int, quantity: float}>}  $payload
     */
    public function createPutawayList(array $payload, User $actor): PutawayList
    {
        $companyId = $this->context->companyId() ?? throw new RuntimeException('No company context.');

        $receipt = null;
        $warehouseId = $payload['warehouse_id'] ?? null;
        $lines = [];

        if (($receiptId = $payload['goods_receipt_id'] ?? null) !== null && $receiptId !== '') {
            $receipt = GoodsReceipt::query()
                ->where('company_id', $companyId)
                ->with('lines')
                ->findOrFail((int) $receiptId);

            if (! $receipt->isPosted()) {
                throw new RuntimeException("Receipt {$receipt->code} has not been posted, so its goods are not stock yet — there is nothing to put away.");
            }

            $this->assertReceiptHasNoPutawayList($receipt);

            $warehouseId = $receipt->warehouse_id;

            $lines = $receipt->lines
                ->map(fn ($line) => [
                    'product_id' => (int) $line->product_id,
                    'quantity' => round((float) $line->qty_received, 4),
                    'batch_no' => $line->batch_no,
                ])
                ->filter(fn (array $line) => $line['quantity'] > 0.00005)
                ->values()
                ->all();

            if ($lines === []) {
                throw new RuntimeException("Receipt {$receipt->code} has no received quantities to put away.");
            }
        } else {
            $lines = $this->cleanManualLines($payload['lines'] ?? [], $companyId);

            if ($lines === []) {
                throw new RuntimeException('A manual putaway list needs at least one product and a quantity.');
            }
        }

        $warehouse = $this->warehouseOrFail((int) $warehouseId);

        return DB::transaction(function () use ($companyId, $warehouse, $receipt, $lines, $payload, $actor) {
            $list = PutawayList::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'goods_receipt_id' => $receipt?->id,
                'code' => $this->nextCode('putaway_list', $actor),
                'status' => PutawayList::STATUS_DRAFT,
                'created_by' => $actor->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            $lineNo = 0;

            foreach ($lines as $line) {
                $lineNo++;

                PutawayListLine::create([
                    'putaway_list_id' => $list->id,
                    'product_id' => $line['product_id'],
                    'warehouse_bin_id' => $this->primaryBinId($line['product_id'], $warehouse->id),
                    'stock_batch_id' => $this->batchIdForReceiptLine($line, $warehouse->id),
                    'quantity' => $line['quantity'],
                    'line_no' => $lineNo,
                ]);
            }

            $this->audit->record([
                'action' => 'inventory.putaway_list_created',
                'entity_type' => 'putaway_list',
                'entity_id' => $list->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $list->code,
                    'warehouse_id' => $warehouse->id,
                    'goods_receipt_id' => $receipt?->id,
                    'lines' => $lineNo,
                    'source' => $receipt !== null ? 'receipt' : 'manual',
                ],
            ]);

            return $list->load('lines');
        });
    }

    /* ---------------------------------------------------------------- picks --- */

    /** Hand the walk to somebody (or take it back with a null user). */
    public function assign(PickList $list, ?User $assignee, User $actor): PickList
    {
        $this->assertPickOpen($list, 'hand out');

        $list->forceFill([
            'assigned_to' => $assignee?->id,
            'status' => $assignee === null ? PickList::STATUS_DRAFT : PickList::STATUS_ASSIGNED,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.pick_list_assigned',
            'entity_type' => 'pick_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'after' => ['code' => $list->code, 'assigned_to' => $assignee?->id],
        ]);

        return $list->refresh();
    }

    /**
     * Write down what actually came off the shelf.
     *
     * A line left out of `$rows` keeps the quantity it had — an empty box means
     * "not finished", never "picked nothing". Nobody can pick more than the
     * sheet asked for: that would be stock leaving the building on an
     * instruction nobody gave.
     *
     * @param  array<int|string, float|array{qty?: float|string|null, note?: string|null}>  $rows  line id => quantity (or array with qty/note)
     */
    public function recordPick(PickList $list, array $rows, User $actor): PickList
    {
        $this->assertPickOpen($list, 'pick');

        return DB::transaction(function () use ($list, $rows, $actor) {
            $list->loadMissing('lines');
            $changed = [];

            foreach ($list->lines as $line) {
                if (! array_key_exists($line->id, $rows)) {
                    continue;
                }

                $row = $rows[$line->id];
                $qty = is_array($row) ? ($row['qty'] ?? null) : $row;
                $note = is_array($row) ? ($row['note'] ?? null) : null;

                if ($qty === null || $qty === '') {
                    if ($note !== null) {
                        $line->forceFill(['note' => $note])->save();
                    }

                    continue;
                }

                if (! is_numeric($qty)) {
                    throw new RuntimeException("Line {$line->line_no}: '{$qty}' is not a quantity.");
                }

                $qty = round((float) $qty, 4);

                if ($qty < 0) {
                    throw new RuntimeException("Line {$line->line_no}: a picked quantity cannot be negative.");
                }

                if ($qty > (float) $line->quantity + 0.00005) {
                    throw new RuntimeException(sprintf(
                        'Line %d: the sheet asks for %s, so %s cannot be picked — write down what came off the shelf, not more.',
                        $line->line_no,
                        number_format((float) $line->quantity, 4),
                        number_format($qty, 4),
                    ));
                }

                $moved = abs($qty - (float) $line->picked_quantity) > 0.00005;

                if (! $moved && ($note === null || $note === '')) {
                    continue;
                }

                $line->forceFill([
                    'picked_quantity' => $qty,
                    'note' => $note !== null && $note !== '' ? $note : $line->note,
                ])->save();

                if ($moved) {
                    $changed[] = ['line' => $line->line_no, 'product_id' => $line->product_id, 'picked' => $qty];
                }
            }

            if ($changed !== [] && $list->status !== PickList::STATUS_PICKING) {
                $list->forceFill([
                    'status' => PickList::STATUS_PICKING,
                    'started_at' => $list->started_at ?? now(),
                ])->save();
            }

            if ($changed !== []) {
                $this->audit->record([
                    'action' => 'inventory.pick_recorded',
                    'entity_type' => 'pick_list',
                    'entity_id' => $list->id,
                    'branch_id' => $list->branch_id,
                    'actor_id' => $actor->id,
                    'after' => ['code' => $list->code, 'lines' => $changed],
                ]);
            }

            return $list->refresh()->load('lines');
        });
    }

    /**
     * The walk is done: the goods are off the shelf.
     *
     * Nothing moves in the ledger here — dispatch is still the moment that
     * happens. What does change is the order: a pick that finished for an order
     * is the physical proof the order is ready to ship, so the order is moved to
     * ready_to_ship when the machine allows it. If the order has already gone
     * further (or was cancelled), the pick still completes — the work happened,
     * and pretending otherwise would be a lie about the warehouse.
     */
    public function complete(PickList $list, User $actor): PickList
    {
        $this->assertPickOpen($list, 'complete');

        $list->loadMissing('lines');

        if ($list->pickedQty() <= 0.00005) {
            throw new RuntimeException('Nothing has been picked yet — there is no walk to finish.');
        }

        $order = $list->sales_order_id ? SalesOrder::query()->find($list->sales_order_id) : null;
        $orderAdvanced = false;
        $orderNote = null;

        if ($order !== null) {
            try {
                $this->orders->transition($order, 'ready_to_ship');
                $orderAdvanced = true;
            } catch (RuntimeException $e) {
                // The goods are still off the shelf; only the paper could not move.
                $orderNote = $e->getMessage();
            }
        }

        $list->forceFill([
            'status' => PickList::STATUS_PICKED,
            'completed_at' => now(),
        ])->save();

        $this->audit->record([
            'action' => 'inventory.pick_completed',
            'entity_type' => 'pick_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'code' => $list->code,
                'picked_qty' => $list->pickedQty(),
                'lines_short' => $list->pendingLines(),
                'order_id' => $order?->id,
                'order_advanced' => $orderAdvanced,
                'order_note' => $orderNote,
            ],
            'reason' => $orderNote,
        ]);

        return $list->refresh()->load('lines');
    }

    public function cancelPick(PickList $list, string $reason, User $actor): PickList
    {
        $this->assertPickOpen($list, 'cancel');

        if (trim($reason) === '') {
            throw new RuntimeException('Say why the walk is being abandoned — the goods may still be waiting.');
        }

        $list->forceFill([
            'status' => PickList::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.pick_list_cancelled',
            'entity_type' => 'pick_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'after' => ['code' => $list->code, 'picked_qty' => $list->pickedQty()],
        ]);

        return $list->refresh();
    }

    /* ------------------------------------------------------------- putaway ---- */

    public function assignPutaway(PutawayList $list, ?User $assignee, User $actor): PutawayList
    {
        $this->assertPutawayOpen($list, 'hand out');

        $list->forceFill([
            'assigned_to' => $assignee?->id,
            'status' => $assignee === null ? PutawayList::STATUS_DRAFT : PutawayList::STATUS_ASSIGNED,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.putaway_list_assigned',
            'entity_type' => 'putaway_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'after' => ['code' => $list->code, 'assigned_to' => $assignee?->id],
        ]);

        return $list->refresh();
    }

    /**
     * Record where the goods went, and teach the system the one thing bin rows
     * are for: directions. Placing into a bin the product did not live in writes
     * a bin assignment — and if the product had no home in this warehouse yet,
     * the first place it lands becomes its pick face.
     *
     * @param  array<int|string, array{bin_id?: int|string|null, qty?: float|string|null, note?: string|null}>  $rows
     */
    public function recordPlacement(PutawayList $list, array $rows, User $actor): PutawayList
    {
        $this->assertPutawayOpen($list, 'put away');

        $warehouseId = (int) $list->warehouse_id;

        return DB::transaction(function () use ($list, $rows, $warehouseId, $actor) {
            $list->loadMissing('lines');
            $changed = [];

            foreach ($list->lines as $line) {
                if (! array_key_exists($line->id, $rows)) {
                    continue;
                }

                $row = $rows[$line->id];
                $qty = $row['qty'] ?? null;
                $binId = $row['bin_id'] ?? null;
                $note = $row['note'] ?? null;

                if (($qty === null || $qty === '') && ($note === null || $note === '')) {
                    continue;
                }

                if ($qty !== null && $qty !== '') {
                    if (! is_numeric($qty)) {
                        throw new RuntimeException("Line {$line->line_no}: '{$qty}' is not a quantity.");
                    }

                    $qty = round((float) $qty, 4);

                    if ($qty < 0) {
                        throw new RuntimeException("Line {$line->line_no}: a placed quantity cannot be negative.");
                    }

                    $already = (float) $line->placed_quantity;
                    $total = round($already + $qty, 4);

                    if ($total > (float) $line->quantity + 0.00005) {
                        throw new RuntimeException(sprintf(
                            'Line %d: %s arrived, %s is already placed, and %s more would put away more than the receipt brought in.',
                            $line->line_no,
                            number_format((float) $line->quantity, 4),
                            number_format($already, 4),
                            number_format($qty, 4),
                        ));
                    }

                    if ($qty > 0.00005) {
                        if ($binId === null || $binId === '') {
                            throw new RuntimeException("Line {$line->line_no}: say which bin the goods went into — the map cannot be updated from a quantity alone.");
                        }

                        $bin = $this->binOrFail((int) $binId, $warehouseId, $line->line_no);
                        $this->learnHome($line->product_id, $bin, $list, $actor);

                        $line->forceFill([
                            'placed_quantity' => $total,
                            'placed_bin_id' => $bin->id,
                        ]);

                        $changed[] = [
                            'line' => $line->line_no,
                            'product_id' => $line->product_id,
                            'placed' => $qty,
                            'bin_id' => $bin->id,
                        ];
                    }
                }

                if ($note !== null && $note !== '') {
                    $line->note = $note;
                }

                $line->save();
            }

            if ($changed !== [] && $list->status !== PutawayList::STATUS_PUTTING_AWAY) {
                $list->forceFill([
                    'status' => PutawayList::STATUS_PUTTING_AWAY,
                    'started_at' => $list->started_at ?? now(),
                ])->save();
            }

            if ($changed !== []) {
                $this->audit->record([
                    'action' => 'inventory.putaway_recorded',
                    'entity_type' => 'putaway_list',
                    'entity_id' => $list->id,
                    'branch_id' => $list->branch_id,
                    'actor_id' => $actor->id,
                    'after' => ['code' => $list->code, 'lines' => $changed],
                ]);
            }

            return $list->refresh()->load('lines');
        });
    }

    /**
     * Everything is in its place. Unlike a pick, a putaway is not finished while
     * goods are still sitting on the dock — half-put-away is not a state, it is
     * an unfinished job, so completion names the lines that are still there.
     */
    public function completePutaway(PutawayList $list, User $actor): PutawayList
    {
        $this->assertPutawayOpen($list, 'complete');

        $list->loadMissing('lines');

        if ($list->placedQty() <= 0.00005) {
            throw new RuntimeException('Nothing has been placed yet — there is nothing to finish.');
        }

        if ($list->pendingLines() > 0) {
            throw new RuntimeException(sprintf(
                '%d line(s) still have goods waiting to be put away. A putaway is finished when the dock is empty.',
                $list->pendingLines(),
            ));
        }

        $list->forceFill([
            'status' => PutawayList::STATUS_PUT_AWAY,
            'completed_at' => now(),
        ])->save();

        $this->audit->record([
            'action' => 'inventory.putaway_completed',
            'entity_type' => 'putaway_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'after' => ['code' => $list->code, 'placed_qty' => $list->placedQty(), 'lines' => $list->lines->count()],
        ]);

        return $list->refresh()->load('lines');
    }

    public function cancelPutaway(PutawayList $list, string $reason, User $actor): PutawayList
    {
        $this->assertPutawayOpen($list, 'cancel');

        if (trim($reason) === '') {
            throw new RuntimeException('Say why the putaway is being abandoned — the goods may still be on the dock.');
        }

        $list->forceFill([
            'status' => PutawayList::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.putaway_list_cancelled',
            'entity_type' => 'putaway_list',
            'entity_id' => $list->id,
            'branch_id' => $list->branch_id,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'after' => ['code' => $list->code, 'placed_qty' => $list->placedQty()],
        ]);

        return $list->refresh();
    }

    /* --------------------------------------------------------------- shared --- */

    /**
     * The bin a picker should walk to: the product's pick face in this
     * warehouse, when there is one. Nothing is invented — a product nobody has
     * placed yet gets a line that says so, which is the honest answer and the
     * one that gets a bin recorded.
     */
    protected function primaryBinId(int $productId, int $warehouseId): ?int
    {
        $binId = ProductBinAssignment::query()
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->whereIn('warehouse_bin_id', WarehouseBin::query()
                ->where('warehouse_id', $warehouseId)
                ->select('id'))
            ->orderBy('id')
            ->value('warehouse_bin_id');

        return $binId === null ? null : (int) $binId;
    }

    /**
     * The batch the shelf should give up first, for batch-tracked goods under
     * FEFO. Expired stock is never suggested — it should not be picked at all —
     * and a warehouse with only expired stock gets no hint, which is itself a
     * signal to go and look at the expiry desk.
     */
    protected function suggestedBatchId(int $productId, int $warehouseId): ?int
    {
        if (! $this->settings->getBool('inventory', 'fefo_picking', true)) {
            return null;
        }

        if (! Product::query()->whereKey($productId)->where('track_batch', true)->exists()) {
            return null;
        }

        $batchId = $this->batchQuery($productId, $warehouseId)->value('id');

        return $batchId === null ? null : (int) $batchId;
    }

    /** The batch a batch-tracked receipt line created, when it can be found. */
    protected function batchIdForReceiptLine(array $line, int $warehouseId): ?int
    {
        $batchNo = $line['batch_no'] ?? null;

        if ($batchNo === null || trim((string) $batchNo) === '') {
            return null;
        }

        $batchId = StockBatch::query()
            ->where('product_id', $line['product_id'])
            ->where('warehouse_id', $warehouseId)
            ->where('batch_no', $batchNo)
            ->value('id');

        return $batchId === null ? null : (int) $batchId;
    }

    protected function batchQuery(int $productId, int $warehouseId): Builder
    {
        return StockBatch::query()
            ->withStock()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where(function (Builder $q) {
                $q->whereNull('expires_on')
                    ->orWhereDate('expires_on', '>=', now()->toDateString());
            })
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->orderBy('id');
    }

    /**
     * Write the lesson of a putaway into the bin map. A product whose home is
     * already known keeps that home: one pallet landing in a second rack does not
     * move the pick face. Should the service refuse, the placement still stands —
     * the goods are where they are; only the map could not be updated.
     */
    protected function learnHome(int $productId, WarehouseBin $bin, PutawayList $list, User $actor): void
    {
        $hasPrimary = ProductBinAssignment::query()
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->whereIn('warehouse_bin_id', WarehouseBin::query()
                ->where('warehouse_id', $bin->warehouse_id)
                ->select('id'))
            ->exists();

        try {
            $this->warehouses->assignProduct(
                $bin,
                $productId,
                ! $hasPrimary,
                'Put away from '.$list->code,
                $actor,
            );
        } catch (RuntimeException) {
            // The map could not take the note; the goods are still placed.
        }
    }

    /**
     * Manual lines, cleaned: rows the form left blank are dropped rather than
     * rejected, and the same product twice is added up instead of creating two
     * walks to the same shelf.
     *
     * @param  array<int|string, array{product_id?: int|string|null, quantity?: float|string|null}>  $rows
     * @return array<int, array{product_id: int, quantity: float}>
     */
    protected function cleanManualLines(array $rows, int $companyId): array
    {
        $clean = [];

        foreach ($rows as $index => $row) {
            $productId = $row['product_id'] ?? null;
            $quantity = $row['quantity'] ?? null;

            if (($productId === null || $productId === '') && ($quantity === null || $quantity === '')) {
                continue;
            }

            if ($productId === null || $productId === '') {
                throw new RuntimeException('Row '.($index + 1).' has a quantity but no product.');
            }

            if ($quantity === null || $quantity === '' || ! is_numeric($quantity) || (float) $quantity <= 0) {
                throw new RuntimeException('Row '.($index + 1).' needs a quantity greater than zero.');
            }

            $product = Product::query()
                ->where('company_id', $companyId)
                ->find((int) $productId);

            if ($product === null) {
                throw new RuntimeException('Row '.($index + 1).': that product is not in this company.');
            }

            if (isset($clean[$product->id])) {
                $clean[$product->id]['quantity'] = round($clean[$product->id]['quantity'] + (float) $quantity, 4);

                continue;
            }

            $clean[$product->id] = ['product_id' => (int) $product->id, 'quantity' => round((float) $quantity, 4)];
        }

        return array_values($clean);
    }

    protected function warehouseOrFail(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('company_id', $this->context->companyId())
            ->find($warehouseId);

        if ($warehouse === null) {
            throw new RuntimeException('Pick a warehouse of this company to work in.');
        }

        return $warehouse;
    }

    protected function binOrFail(int $binId, int $warehouseId, int $lineNo): WarehouseBin
    {
        $bin = WarehouseBin::query()
            ->where('id', $binId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($bin === null) {
            throw new RuntimeException("Line {$lineNo}: that bin is not in this warehouse.");
        }

        if (! $bin->is_active) {
            throw new RuntimeException("Line {$lineNo}: bin {$bin->code} is switched off, so nothing should be put into it.");
        }

        return $bin;
    }

    protected function assertOrderCanBePicked(SalesOrder $order): void
    {
        $allowed = ['confirmed', 'processing', 'ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery'];

        if (! in_array($order->status, $allowed, true)) {
            throw new RuntimeException("Order {$order->order_no} is {$order->status} — there is nothing to pick for it.");
        }
    }

    /**
     * One open walk per order. A second list for the same order is not a second
     * job, it is the same job written twice — and two pickers would walk it.
     */
    protected function assertNoOpenPickList(SalesOrder $order): void
    {
        $existing = PickList::query()
            ->where('sales_order_id', $order->id)
            ->whereIn('status', PickList::OPEN_STATUSES)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException("Order {$order->order_no} already has pick list {$existing->code} open. Finish or cancel that one first.");
        }
    }

    protected function assertReceiptHasNoPutawayList(GoodsReceipt $receipt): void
    {
        $existing = PutawayList::query()
            ->where('goods_receipt_id', $receipt->id)
            ->where('status', '!=', PutawayList::STATUS_CANCELLED)
            ->first();

        if ($existing !== null) {
            throw new RuntimeException("Receipt {$receipt->code} has already been put away on list {$existing->code}.");
        }
    }

    protected function assertPickOpen(PickList $list, string $verb): void
    {
        if ($list->isCancelled()) {
            throw new RuntimeException("Pick list {$list->code} was cancelled, so it cannot be {$verb}d.");
        }

        if ($list->isPicked()) {
            throw new RuntimeException("Pick list {$list->code} is already finished — a finished walk is a record, not a to-do list.");
        }
    }

    protected function assertPutawayOpen(PutawayList $list, string $verb): void
    {
        if ($list->isCancelled()) {
            throw new RuntimeException("Putaway list {$list->code} was cancelled, so it cannot be {$verb}d.");
        }

        if ($list->isPutAway()) {
            throw new RuntimeException("Putaway list {$list->code} is already finished — a finished list is a record, not a to-do list.");
        }
    }

    /**
     * The numbers the registers open with. They are counted, never estimated:
     * an open walk with no bin is work waiting on somebody to say where the
     * product lives, and that is different from work waiting on a picker.
     *
     * @return array{open: int, unassigned: int, no_bin: int, done_week: int, orders_waiting: int}
     */
    public function pickStats(): array
    {
        $companyId = $this->context->companyId();
        $openIds = PickList::query()->where('company_id', $companyId)->open()->pluck('id');

        return [
            'open' => $openIds->count(),
            'unassigned' => PickList::query()->where('company_id', $companyId)->open()->whereNull('assigned_to')->count(),
            'no_bin' => $openIds->isEmpty()
                ? 0
                : PickListLine::query()->whereIn('pick_list_id', $openIds)->whereNull('warehouse_bin_id')->count(),
            'done_week' => PickList::query()
                ->where('company_id', $companyId)
                ->where('status', PickList::STATUS_PICKED)
                ->where('completed_at', '>=', now()->subDays(7))
                ->count(),
            'orders_waiting' => $this->ordersToPick()->count(),
        ];
    }

    /** @return array{open: int, unassigned: int, no_bin: int, done_week: int, receipts_waiting: int} */
    public function putawayStats(): array
    {
        $companyId = $this->context->companyId();
        $openIds = PutawayList::query()->where('company_id', $companyId)->open()->pluck('id');

        return [
            'open' => $openIds->count(),
            'unassigned' => PutawayList::query()->where('company_id', $companyId)->open()->whereNull('assigned_to')->count(),
            'no_bin' => $openIds->isEmpty()
                ? 0
                : PutawayListLine::query()->whereIn('putaway_list_id', $openIds)->whereNull('warehouse_bin_id')->count(),
            'done_week' => PutawayList::query()
                ->where('company_id', $companyId)
                ->where('status', PutawayList::STATUS_PUT_AWAY)
                ->where('completed_at', '>=', now()->subDays(7))
                ->count(),
            'receipts_waiting' => $this->receiptsToPutAway()->count(),
        ];
    }

    /** Numbers come from the seeded rule for the document type, never from here. */
    protected function nextCode(string $documentTypeCode, User $actor): string
    {
        $type = DocumentType::query()->where('code', $documentTypeCode)->first();

        if ($type === null) {
            throw new RuntimeException("Document type [{$documentTypeCode}] is not seeded, so documents cannot be numbered.");
        }

        return $this->numbering->allocate($type->id, $actor->default_branch_id ?? 0);
    }
}
