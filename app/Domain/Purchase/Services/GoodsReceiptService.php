<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods received notes (§03) — the only place purchase stock becomes real.
 *
 * Posting writes one stock movement per line through StockLedgerService, which
 * owns valuation layers and the stock_balances cache. This service owns:
 *   · the receipt may only be raised against an approved/partial PO, or as a
 *     direct (PO-less) receipt when the goods arrived without paperwork;
 *   · a line may never receive more than the order still owes (over-receipt is
 *     refused with the numbers, not silently accepted);
 *   · PO line `qty_received` and the order status (partially_received /
 *     received) follow from the receipts themselves;
 *   · posting is idempotent per receipt (movement idempotency key = GRN id +
 *     line id) so a double-click cannot double the stock;
 *   · a posted receipt is immutable: cancelling is only possible while draft.
 */
class GoodsReceiptService
{
    public function __construct(
        protected TenantContext $context,
        protected StockLedgerService $stock,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{purchase_order_id?:?int,supplier_id:int,warehouse_id:int,branch_id?:?int,
     *               received_date:string,challan_no?:?string,notes?:?string,
     *               lines:array<int, array{purchase_order_line_id?:?int,product_id:int,qty_received:float,unit_cost?:float,batch_no?:?string,remarks?:?string}>}  $data
     */
    public function create(array $data, ?int $actorId = null): GoodsReceipt
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for goods receipts.');

        $supplier = Supplier::query()->whereKey($data['supplier_id'] ?? 0)->firstOrFail();
        $supplier->assertOrderable();

        $warehouse = Warehouse::query()->whereKey($data['warehouse_id'] ?? 0)->firstOrFail();

        $order = isset($data['purchase_order_id']) && $data['purchase_order_id']
            ? PurchaseOrder::query()->with('lines')->findOrFail($data['purchase_order_id'])
            : null;

        if ($order !== null) {
            if (! $order->isOpen()) {
                throw new RuntimeException("Purchase order {$order->code} is {$order->status} — nothing can be received against it.");
            }

            if ((int) $order->supplier_id !== (int) $supplier->id) {
                throw new RuntimeException("Purchase order {$order->code} belongs to a different supplier.");
            }
        }

        $lines = $data['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('A goods receipt needs at least one line.');
        }

        return DB::transaction(function () use ($data, $lines, $supplier, $warehouse, $order, $companyId, $actorId) {
            $receipt = GoodsReceipt::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $warehouse->branch_id ?? $this->context->branchId(),
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'code' => $data['code'] ?? $this->nextCode($companyId),
                'challan_no' => $data['challan_no'] ?? null,
                'received_date' => $data['received_date'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'received_by' => $actorId,
            ]);

            foreach (array_values($lines) as $index => $line) {
                $receipt->lines()->create($this->linePayload($line, $order, $index));
            }

            $this->recalculate($receipt);

            $this->audit->record([
                'action' => 'purchase.receipt_created',
                'entity_type' => 'goods_receipt',
                'entity_id' => $receipt->id,
                'branch_id' => $receipt->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $receipt->code,
                    'supplier' => $supplier->name,
                    'po' => $order?->code,
                    'lines' => count($lines),
                    'total' => (float) $receipt->total,
                ],
            ]);

            return $receipt->refresh()->load('lines');
        });
    }

    /**
     * Post the receipt: stock in, PO quantities forward.
     *
     * @return array{receipt:GoodsReceipt, movements:int, value:float}
     */
    public function post(GoodsReceipt $receipt, ?int $actorId = null): array
    {
        if ($receipt->status !== 'draft') {
            throw new RuntimeException("Only a draft receipt can be posted (this one is {$receipt->status}).");
        }

        $receipt->loadMissing('lines', 'order');

        if ($receipt->lines->isEmpty()) {
            throw new RuntimeException('Nothing to post — the receipt has no lines.');
        }

        $actor = $actorId === null ? null : \App\Domain\Foundation\User::query()->find($actorId);

        return DB::transaction(function () use ($receipt, $actorId, $actor) {
            $order = $receipt->order;
            $value = 0.0;
            $movements = 0;

            foreach ($receipt->lines as $line) {
                $product = Product::query()->findOrFail($line->product_id);

                if ($order !== null && $line->purchase_order_line_id !== null) {
                    /** @var PurchaseOrderLine $orderLine */
                    $orderLine = $order->lines->firstWhere('id', $line->purchase_order_line_id)
                        ?? PurchaseOrderLine::query()->findOrFail($line->purchase_order_line_id);

                    $outstanding = $orderLine->outstandingQty();
                    $qty = (float) $line->qty_received;

                    if ($qty > $outstanding + 1e-9) {
                        throw new RuntimeException(sprintf(
                            'Over-receipt on "%s": %s received against %s still outstanding on %s.',
                            $orderLine->description,
                            number_format($qty, 4),
                            number_format($outstanding, 4),
                            $order->code,
                        ));
                    }

                    $orderLine->forceFill(['qty_received' => (float) $orderLine->qty_received + $qty])->save();
                }

                $this->stock->post([
                    'product_id' => $product->id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
                    'qty' => (float) $line->qty_received,
                    'unit_cost' => (float) $line->unit_cost,
                    'branch_id' => $receipt->branch_id,
                    // §04-38: whatever the receiving desk wrote on the line about
                    // the batch and its dates travels with the movement, so the
                    // register is filled by the receipt itself.
                    'batch_no' => $line->batch_no,
                    'manufactured_on' => $line->manufactured_on?->toDateString(),
                    'expires_on' => $line->expires_on?->toDateString(),
                    'source_type' => 'goods_receipt',
                    'source_id' => $receipt->id,
                    'source_event' => 'posted',
                    'idempotency_key' => "grn:{$receipt->id}:line:{$line->id}",
                    'narration' => "Received on {$receipt->code}".($receipt->challan_no ? " (challan {$receipt->challan_no})" : ''),
                ], $actor);

                $movements++;
                $value += (float) $line->qty_received * (float) $line->unit_cost;
            }

            $receipt->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actorId,
            ])->save();

            if ($order !== null) {
                $this->syncOrderStatus($order->refresh()->load('lines'), $actorId);
            }

            $this->audit->record([
                'action' => 'purchase.receipt_posted',
                'entity_type' => 'goods_receipt',
                'entity_id' => $receipt->id,
                'branch_id' => $receipt->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $receipt->code,
                    'movements' => $movements,
                    'value' => round($value, 4),
                ],
            ]);

            return ['receipt' => $receipt->refresh(), 'movements' => $movements, 'value' => round($value, 4)];
        });
    }

    /** Draft receipts may be cancelled; posted ones must be returned instead. */
    public function cancel(GoodsReceipt $receipt, string $reason, ?int $actorId = null): GoodsReceipt
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Cancelling a goods receipt requires a reason.');
        }

        if (! $receipt->isDraft()) {
            throw new RuntimeException('This receipt is already posted — stock is on hand, so raise a purchase return instead.');
        }

        $receipt->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();

        $this->audit->record([
            'action' => 'purchase.receipt_cancelled',
            'entity_type' => 'goods_receipt',
            'entity_id' => $receipt->id,
            'branch_id' => $receipt->branch_id,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);

        return $receipt;
    }

    public function recalculate(GoodsReceipt $receipt): GoodsReceipt
    {
        $receipt->loadMissing('lines');

        $subtotal = 0.0;

        foreach ($receipt->lines as $line) {
            $lineTotal = round((float) $line->qty_received * (float) $line->unit_cost, 4);
            $line->forceFill(['line_total' => $lineTotal])->save();
            $subtotal += $lineTotal;
        }

        $receipt->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();

        return $receipt->refresh();
    }

    public function nextCode(int $companyId): string
    {
        $last = GoodsReceipt::query()->where('company_id', $companyId)->orderByDesc('id')->value('code');
        $next = $last !== null && preg_match('/GRN-(\d+)$/', (string) $last, $m) === 1 ? ((int) $m[1]) + 1 : 1;

        return sprintf('GRN-%05d', $next);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Order status follows the received quantities: nothing received keeps it
     * approved, something but not everything makes it partial, everything
     * closes it.
     */
    protected function syncOrderStatus(PurchaseOrder $order, ?int $actorId): void
    {
        $ordered = 0.0;
        $received = 0.0;

        foreach ($order->lines as $line) {
            $ordered += (float) $line->qty_ordered;
            $received += (float) $line->qty_received;
        }

        $status = match (true) {
            $received <= 0 => 'approved',
            $received + 1e-9 >= $ordered => 'received',
            default => 'partially_received',
        };

        if ($order->status === $status) {
            return;
        }

        $before = $order->status;
        $order->forceFill(['status' => $status])->save();

        $this->audit->record([
            'action' => 'purchase.order_progressed',
            'entity_type' => 'purchase_order',
            'entity_id' => $order->id,
            'branch_id' => $order->branch_id,
            'actor_id' => $actorId,
            'before' => ['status' => $before],
            'after' => ['status' => $status, 'ordered' => $ordered, 'received' => $received],
        ]);
    }

    /** @param array<string, mixed> $line */
    protected function linePayload(array $line, ?PurchaseOrder $order, int $sortOrder): array
    {
        $qty = (float) ($line['qty_received'] ?? 0);

        if ($qty <= 0) {
            throw new RuntimeException('Every receipt line needs a quantity greater than zero.');
        }

        $productId = $line['product_id'] ?? null;

        if ($productId === null && isset($line['purchase_order_line_id']) && $order !== null) {
            $productId = $order->lines->firstWhere('id', $line['purchase_order_line_id'])?->product_id;
        }

        if ($productId === null) {
            throw new RuntimeException('Every receipt line must name the product that arrived.');
        }

        $unitCost = $line['unit_cost'] ?? null;

        if ($unitCost === null && $order !== null && isset($line['purchase_order_line_id'])) {
            $unitCost = $order->lines->firstWhere('id', $line['purchase_order_line_id'])?->unit_price;
        }

        return [
            'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
            'product_id' => $productId,
            'qty_received' => $qty,
            'unit_cost' => (float) ($unitCost ?? 0),
            'line_total' => 0,
            'batch_no' => $line['batch_no'] ?? null,
            'manufactured_on' => $line['manufactured_on'] ?? null,
            'expires_on' => $line['expires_on'] ?? null,
            'remarks' => $line['remarks'] ?? null,
            'sort_order' => $line['sort_order'] ?? $sortOrder,
        ];
    }
}
