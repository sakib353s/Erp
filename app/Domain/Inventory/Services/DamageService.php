<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockDamageEntry;
use App\Domain\Inventory\StockDamageEntryLine;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockWriteoff;
use App\Domain\Inventory\StockWriteoffLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Damage & loss (§04-46…04-49, 04-51).
 *
 * Two statements, two documents:
 *
 *  · a damage/loss ENTRY says what is true about goods we hold. Damage moves
 *    units out of sellable stock into the damaged compartment (no value
 *    changes — they are still ours, worth the same, just unsellable); a loss
 *    takes them out of the company and books the cost. Both are valued from
 *    the valuation layers at that moment, never estimated.
 *
 *  · a WRITE-OFF is the decision that value leaves the company. It is raised
 *    against a compartment, approved by a second person, and only then does
 *    stock move and the cost post to the books. A rejected write-off changes
 *    nothing at all.
 *
 * Nothing here writes stock_movements directly — every quantity goes through
 * StockLedgerService, so the balances can always be rebuilt from the ledger.
 */
class DamageService
{
    public function __construct(
        protected StockLedgerService $ledger,
        protected ValuationService $valuation,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected JournalPostingService $journal,
        protected PostingRuleResolver $rules,
    ) {}

    /* ------------------------------------------------------------ entries ---- */

    /**
     * Record damage or loss. Returns the entry with its lines and value.
     *
     * @param  array{
     *   warehouse_id: int,
     *   entry_date: string,
     *   reason_code?: string|null,
     *   reason: string,
     *   lines: array<int, array{product_id: int, qty: float, narration?: string|null}>,
     * } $payload
     */
    public function recordEntry(string $kind, array $payload, User $actor): StockDamageEntry
    {
        if (! in_array($kind, StockDamageEntry::KINDS, true)) {
            throw new RuntimeException("Unknown damage/loss kind [{$kind}].");
        }

        $lines = array_values(array_filter(
            $payload['lines'] ?? [],
            fn ($line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw new RuntimeException('An entry needs at least one line with a quantity above zero.');
        }

        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw new RuntimeException('Say why the stock was damaged or lost — the reason is what the report is built from.');
        }

        $companyId = $this->companyId($actor);
        $warehouse = $this->warehouse($payload['warehouse_id'] ?? 0, $companyId);
        $entryDate = (string) ($payload['entry_date'] ?? now()->toDateString());

        return DB::transaction(function () use ($kind, $payload, $lines, $warehouse, $entryDate, $actor, $companyId) {
            $entry = StockDamageEntry::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id ?? $actor->default_branch_id,
                'warehouse_id' => $warehouse->id,
                'code' => $this->nextCode('stock_damage_entry', $actor),
                'kind' => $kind,
                'entry_date' => $entryDate,
                'reason_code' => $payload['reason_code'] ?? null,
                'reason' => $payload['reason'],
                'status' => StockDamageEntry::STATUS_RECORDED,
                'total_value' => 0,
                'created_by' => $actor->id,
            ]);

            $lineNo = 0;
            $total = 0.0;

            foreach ($lines as $line) {
                $lineNo++;
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);

                if (! $product->is_stocked) {
                    throw new RuntimeException(sprintf('%s is a service item — it cannot be damaged or lost.', $product->sku));
                }

                $qty = (float) $line['qty'];
                $this->assertSellable($product, $warehouse, $qty, $kind);

                $unitCost = $this->unitCost($product, $warehouse);
                $value = round($unitCost * $qty, 4);

                StockDamageEntryLine::create([
                    'stock_damage_entry_id' => $entry->id,
                    'product_id' => $product->id,
                    'qty' => number_format($qty, 4, '.', ''),
                    'unit_cost' => number_format($unitCost, 4, '.', ''),
                    'value' => number_format($value, 4, '.', ''),
                    'narration' => $line['narration'] ?? null,
                    'line_no' => $lineNo,
                ]);

                if ($kind === StockDamageEntry::KIND_DAMAGE) {
                    // Out of sellable stock, into the damaged compartment. Same
                    // cost, same layers — the goods are still ours.
                    $this->ledger->post([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'movement_type' => StockMovement::TYPE_DAMAGE_IN,
                        'state' => StockMovement::STATE_DAMAGED,
                        'qty' => $qty,
                        'unit_cost' => $unitCost,
                        'source_type' => 'stock_damage_entry',
                        'source_id' => $entry->id,
                        'source_event' => 'damage_recorded',
                        'idempotency_key' => sprintf('dmg:%d:%d', $entry->id, $lineNo),
                        'occurred_at' => $entryDate,
                        'narration' => $payload['reason'],
                    ], $actor);
                } else {
                    // Gone from the company: the layers are consumed, so the
                    // cost that disappeared is real and can be posted.
                    $this->ledger->post([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'movement_type' => StockMovement::TYPE_WRITE_OFF,
                        'state' => StockMovement::STATE_ON_HAND,
                        'qty' => $qty,
                        'source_type' => 'stock_damage_entry',
                        'source_id' => $entry->id,
                        'source_event' => 'loss_recorded',
                        'idempotency_key' => sprintf('loss:%d:%d', $entry->id, $lineNo),
                        'occurred_at' => $entryDate,
                        'narration' => $payload['reason'],
                    ], $actor);
                }

                $total += $value;
            }

            $entry->forceFill(['total_value' => number_format(round($total, 4), 4, '.', '')])->save();

            if ($kind === StockDamageEntry::KIND_LOSS) {
                $entry->forceFill(['journal_entry_id' => $this->postLoss($entry, $actor)->id])->save();
            }

            $this->audit->record([
                'action' => $kind === StockDamageEntry::KIND_LOSS
                    ? 'inventory.loss_recorded'
                    : 'inventory.damage_recorded',
                'entity_type' => 'stock_damage_entry',
                'entity_id' => $entry->id,
                'branch_id' => $entry->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $entry->code,
                    'kind' => $kind,
                    'warehouse_id' => $warehouse->id,
                    'reason_code' => $entry->reason_code,
                    'line_count' => $lineNo,
                    'value' => round($total, 4),
                ],
            ]);

            return $entry->refresh()->load('lines.product', 'warehouse');
        });
    }

    /* ---------------------------------------------------------- write-offs ---- */

    /**
     * Raise a write-off against a compartment. Nothing moves until a second
     * person approves it: the document is the request, not the act.
     *
     * @param  array{
     *   warehouse_id: int,
     *   writeoff_date: string,
     *   source_state?: string,
     *   reason: string,
     *   lines: array<int, array{product_id: int, qty: float, narration?: string|null}>,
     * } $payload
     */
    public function raiseWriteoff(array $payload, User $actor): StockWriteoff
    {
        $lines = array_values(array_filter(
            $payload['lines'] ?? [],
            fn ($line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($lines === []) {
            throw new RuntimeException('A write-off needs at least one line with a quantity above zero.');
        }

        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw new RuntimeException('A write-off needs a reason: it is the record of why value left the company.');
        }

        $source = (string) ($payload['source_state'] ?? StockMovement::STATE_DAMAGED);

        if (! array_key_exists($source, StockWriteoff::SOURCE_STATES)) {
            throw new RuntimeException("Stock cannot be written off from compartment [{$source}].");
        }

        $companyId = $this->companyId($actor);
        $warehouse = $this->warehouse($payload['warehouse_id'] ?? 0, $companyId);

        return DB::transaction(function () use ($payload, $lines, $source, $warehouse, $actor, $companyId) {
            $writeoff = StockWriteoff::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id ?? $actor->default_branch_id,
                'warehouse_id' => $warehouse->id,
                'code' => $this->nextCode('stock_write_off', $actor),
                'writeoff_date' => $payload['writeoff_date'] ?? now()->toDateString(),
                'source_state' => $source,
                'reason' => $payload['reason'],
                'status' => StockWriteoff::STATUS_PENDING,
                'total_value' => 0,
                'created_by' => $actor->id,
            ]);

            $lineNo = 0;
            $total = 0.0;

            foreach ($lines as $line) {
                $lineNo++;
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);

                if (! $product->is_stocked) {
                    throw new RuntimeException(sprintf('%s is a service item — there is no stock to write off.', $product->sku));
                }

                $qty = (float) $line['qty'];

                if ($source === StockMovement::STATE_ON_HAND) {
                    $this->assertSellable($product, $warehouse, $qty, StockDamageEntry::KIND_LOSS);
                } else {
                    $this->assertHeld($product, $warehouse, $source, $qty);
                }

                $unitCost = $this->unitCost($product, $warehouse);
                $value = round($unitCost * $qty, 4);

                StockWriteoffLine::create([
                    'stock_writeoff_id' => $writeoff->id,
                    'product_id' => $product->id,
                    'qty' => number_format($qty, 4, '.', ''),
                    'unit_cost' => number_format($unitCost, 4, '.', ''),
                    'value' => number_format($value, 4, '.', ''),
                    'narration' => $line['narration'] ?? null,
                    'line_no' => $lineNo,
                ]);

                $total += $value;
            }

            $writeoff->forceFill(['total_value' => number_format(round($total, 4), 4, '.', '')])->save();

            $this->audit->record([
                'action' => 'inventory.writeoff_raised',
                'entity_type' => 'stock_writeoff',
                'entity_id' => $writeoff->id,
                'branch_id' => $writeoff->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $writeoff->code,
                    'source_state' => $source,
                    'line_count' => $lineNo,
                    'value' => round($total, 4),
                ],
            ]);

            return $writeoff->refresh()->load('lines.product', 'warehouse');
        });
    }

    /**
     * Approve a write-off: stock leaves its compartment, the layers are
     * consumed and the cost is posted. The person who raised it may not
     * approve it — the second pair of eyes is the whole point.
     */
    public function approveWriteoff(StockWriteoff $writeoff, User $actor): StockWriteoff
    {
        if (! $writeoff->isPending()) {
            throw new RuntimeException("Write-off {$writeoff->code} is {$writeoff->status} — only a pending write-off can be approved.");
        }

        if ($writeoff->created_by !== null && (int) $writeoff->created_by === (int) $actor->id) {
            throw new RuntimeException('You raised this write-off, so you cannot approve it — approval needs a second person.');
        }

        return DB::transaction(function () use ($writeoff, $actor) {
            $fresh = StockWriteoff::query()->whereKey($writeoff->id)->lockForUpdate()->firstOrFail();
            $fresh->load('lines');

            if (! $fresh->isPending()) {
                throw new RuntimeException("Write-off {$fresh->code} was already decided.");
            }

            $source = $fresh->source_state;
            $value = 0.0;

            foreach ($fresh->lines as $line) {
                if ($line->product_id === null || (float) $line->qty <= 0) {
                    continue;
                }

                // The movement type follows the compartment: damaged goods go
                // out through DAMAGE_OUT, anything else through WRITE_OFF.
                $type = $source === StockMovement::STATE_DAMAGED
                    ? StockMovement::TYPE_DAMAGE_OUT
                    : StockMovement::TYPE_WRITE_OFF;

                $movement = $this->ledger->post([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $fresh->warehouse_id,
                    'movement_type' => $type,
                    'state' => $source,
                    'qty' => (float) $line->qty,
                    'source_type' => 'stock_writeoff',
                    'source_id' => $fresh->id,
                    'source_event' => 'writeoff_approved',
                    'idempotency_key' => sprintf('wo:%d:%d', $fresh->id, $line->line_no),
                    'occurred_at' => $fresh->writeoff_date?->toDateString(),
                    'narration' => $fresh->reason,
                ], $actor);

                // The cost the ledger actually consumed — never the estimate
                // made when the document was raised.
                $consumed = abs((float) $movement->qty_signed) * (float) $movement->unit_cost;
                $value += $consumed;

                $line->forceFill([
                    'unit_cost' => $movement->unit_cost,
                    'value' => number_format(round($consumed, 4), 4, '.', ''),
                ])->save();
            }

            $entry = $value > 0 ? $this->postWriteoff($fresh, $value, $actor) : null;

            $fresh->forceFill([
                'status' => StockWriteoff::STATUS_APPROVED,
                'total_value' => number_format(round($value, 4), 4, '.', ''),
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'posted_at' => $entry !== null ? now() : null,
                'journal_entry_id' => $entry?->id,
            ])->save();

            $this->audit->record([
                'action' => 'inventory.writeoff_approved',
                'entity_type' => 'stock_writeoff',
                'entity_id' => $fresh->id,
                'branch_id' => $fresh->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $fresh->code,
                    'source_state' => $source,
                    'value' => round($value, 4),
                    'journal_entry_id' => $entry?->id,
                    'journal_entry_no' => $entry?->entry_no,
                ],
            ]);

            return $fresh->refresh()->load('lines.product', 'warehouse', 'approver');
        });
    }

    /** Refuse a write-off with a reason. Nothing moves, ever. */
    public function rejectWriteoff(StockWriteoff $writeoff, string $note, User $actor): StockWriteoff
    {
        if (! $writeoff->isPending()) {
            throw new RuntimeException("Write-off {$writeoff->code} is {$writeoff->status} — nothing left to decide.");
        }

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('Rejecting a write-off needs a reason, so the next person understands the decision.');
        }

        $writeoff->forceFill([
            'status' => StockWriteoff::STATUS_REJECTED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.writeoff_rejected',
            'entity_type' => 'stock_writeoff',
            'entity_id' => $writeoff->id,
            'branch_id' => $writeoff->branch_id,
            'actor_id' => $actor->id,
            'after' => ['code' => $writeoff->code, 'note' => $note],
        ]);

        return $writeoff->refresh();
    }

    /**
     * Put damaged goods back into sellable stock — the goods turned out fine,
     * or were repaired. The mirror of recording damage: no value changes.
     */
    public function releaseDamage(StockDamageEntry $entry, User $actor): StockDamageEntry
    {
        if (! $entry->isDamage()) {
            throw new RuntimeException('Only a damage entry can be released — a loss is already gone.');
        }

        if (! $entry->isOpen()) {
            throw new RuntimeException("Entry {$entry->code} is {$entry->status}; there is nothing to release.");
        }

        return DB::transaction(function () use ($entry, $actor) {
            $fresh = StockDamageEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $fresh->load('lines');

            foreach ($fresh->lines as $line) {
                $this->ledger->post([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $fresh->warehouse_id,
                    'movement_type' => StockMovement::TYPE_DAMAGE_RELEASE,
                    'state' => StockMovement::STATE_DAMAGED,
                    'qty' => (float) $line->qty,
                    'source_type' => 'stock_damage_entry',
                    'source_id' => $fresh->id,
                    'source_event' => 'damage_released',
                    'idempotency_key' => sprintf('dmg-rel:%d:%d', $fresh->id, $line->line_no),
                    'occurred_at' => now()->toDateString(),
                    'narration' => 'Released back to sellable stock: '.$fresh->reason,
                ], $actor);
            }

            $fresh->forceFill(['status' => StockDamageEntry::STATUS_RELEASED])->save();

            $this->audit->record([
                'action' => 'inventory.damage_released',
                'entity_type' => 'stock_damage_entry',
                'entity_id' => $fresh->id,
                'branch_id' => $fresh->branch_id,
                'actor_id' => $actor->id,
                'after' => ['code' => $fresh->code, 'lines' => $fresh->lines->count()],
            ]);

            return $fresh->refresh()->load('lines.product', 'warehouse');
        });
    }

    /* -------------------------------------------------------------- reading --- */

    /**
     * Damage/loss register.
     *
     * @param  array{kind?: string|null, warehouse?: int|null, reason_code?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function entries(array $filters = [], int $perPage = 15)
    {
        return StockDamageEntry::query()
            ->where('company_id', $this->context->companyId())
            ->with(['warehouse', 'lines.product'])
            ->when(($filters['kind'] ?? null) !== null, fn ($q) => $q->where('kind', $filters['kind']))
            ->when(($filters['warehouse'] ?? null) !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse']))
            ->when(($filters['reason_code'] ?? null) !== null, fn ($q) => $q->where('reason_code', $filters['reason_code']))
            ->when(($filters['from'] ?? null) !== null, fn ($q) => $q->whereDate('entry_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->whereDate('entry_date', '<=', $filters['to']))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Write-off queue, newest first, optionally filtered by status and warehouse. */
    public function writeoffs(string $status = '', ?int $warehouseId = null, int $perPage = 15)
    {
        return StockWriteoff::query()
            ->where('company_id', $this->context->companyId())
            ->with(['warehouse', 'lines.product', 'creator', 'approver'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('writeoff_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The quantity a statement may speak about has to exist. The ledger refuses
     * the same thing a moment later, but by then the message is about valuation
     * layers; this says it in the warehouse's own words.
     */
    protected function assertSellable(Product $product, Warehouse $warehouse, float $qty, string $kind): void
    {
        $onHand = (float) (StockBalance::query()
            ->where('company_id', $this->context->companyId())
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('on_hand') ?? 0);

        if ($onHand + 1e-9 >= $qty) {
            return;
        }

        throw new RuntimeException(sprintf(
            $kind === StockDamageEntry::KIND_LOSS
                ? 'Not enough stock on hand to record a loss of %s: on hand %s, writing off %s.'
                : 'Not enough sellable stock to flag %s as damaged: on hand %s, flagging %s.',
            $product->sku,
            number_format($onHand, 4, '.', ''),
            number_format($qty, 4, '.', ''),
        ));
    }

    /** The same question asked of a compartment: is that much even held there? */
    protected function assertHeld(Product $product, Warehouse $warehouse, string $state, float $qty): void
    {
        $held = (float) (StockBalance::query()
            ->where('company_id', $this->context->companyId())
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value($state) ?? 0);

        if ($held + 1e-9 >= $qty) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Only %s of %s is held as %s in %s — a write-off of %s is more than exists.',
            number_format($held, 4, '.', ''),
            $product->sku,
            $state,
            $warehouse->name ?: 'this warehouse',
            number_format($qty, 4, '.', ''),
        ));
    }

    /**
     * What is held in a compartment right now, with its cost — the pool a
     * write-off draws from. Value comes from the valuation layers, the same
     * definition the stock report uses.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function compartmentHoldings(string $state, ?int $warehouseId = null): Collection
    {
        $column = $state === StockMovement::STATE_QUARANTINED ? 'quarantined' : 'damaged';

        return StockBalance::query()
            ->with(['product', 'warehouse'])
            ->where('company_id', $this->context->companyId())
            ->where($column, '>', 0)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get()
            ->map(function (StockBalance $balance) use ($column) {
                $qty = (float) $balance->{$column};
                $unitCost = $this->unitCost($balance->product, $balance->warehouse);

                return [
                    'balance' => $balance,
                    'product' => $balance->product,
                    'warehouse' => $balance->warehouse,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'value' => round($qty * $unitCost, 4),
                ];
            });
    }

    /**
     * Damage & loss analytics (04-51): what it cost, why, where, and when.
     * Every figure is a sum of documents that exist — no smoothing, no
     * projection, no sample.
     *
     * @return array{
     *   totals: array<string, float|int>,
     *   by_kind: array<string, array{count:int, value:float}>,
     *   by_reason: Collection<int, array<string, mixed>>,
     *   by_warehouse: Collection<int, array<string, mixed>>,
     *   by_month: Collection<int, array<string, mixed>>,
     *   top_products: Collection<int, array<string, mixed>>,
     *   held: array{damaged: float, quarantined: float, damaged_value: float},
     *   from: string, to: string,
     * }
     */
    public function analytics(?string $from = null, ?string $to = null, ?int $warehouseId = null): array
    {
        $from ??= now()->subMonths(11)->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        $entries = StockDamageEntry::query()
            ->with(['warehouse', 'lines.product'])
            ->where('company_id', $this->context->companyId())
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('entry_date')
            ->get();

        $byKind = [];
        foreach (StockDamageEntry::KINDS as $kind) {
            $rows = $entries->where('kind', $kind);
            $byKind[$kind] = [
                'count' => $rows->count(),
                'value' => round((float) $rows->sum(fn (StockDamageEntry $e) => (float) $e->total_value), 4),
            ];
        }

        $byReason = $entries
            ->groupBy(fn (StockDamageEntry $e) => $e->kind.'|'.($e->reason_code ?? 'other'))
            ->map(function ($rows, $key) {
                [$kind, $code] = array_pad(explode('|', (string) $key, 2), 2, 'other');
                $sample = $rows->first();

                return [
                    'kind' => $kind,
                    'reason_code' => $code,
                    'label' => (string) ($sample->reasons()[$code] ?? 'Other'),
                    'count' => $rows->count(),
                    'value' => round((float) $rows->sum(fn (StockDamageEntry $e) => (float) $e->total_value), 4),
                ];
            })
            ->sortByDesc('value')
            ->values();

        $byWarehouse = $entries
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => [
                'warehouse' => $rows->first()->warehouse,
                'count' => $rows->count(),
                'value' => round((float) $rows->sum(fn (StockDamageEntry $e) => (float) $e->total_value), 4),
            ])
            ->sortByDesc('value')
            ->values();

        $byMonth = $entries
            ->groupBy(fn (StockDamageEntry $e) => $e->entry_date->format('Y-m'))
            ->map(fn ($rows, $month) => [
                'month' => (string) $month,
                'label' => Carbon::createFromFormat('Y-m', (string) $month)->format('M Y'),
                'count' => $rows->count(),
                'value' => round((float) $rows->sum(fn (StockDamageEntry $e) => (float) $e->total_value), 4),
            ])
            ->sortKeys()
            ->values();

        $topProducts = $entries
            ->flatMap(fn (StockDamageEntry $e) => $e->lines)
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'product' => $rows->first()->product,
                'qty' => round((float) $rows->sum(fn ($l) => (float) $l->qty), 4),
                'value' => round((float) $rows->sum(fn ($l) => (float) $l->value), 4),
            ])
            ->sortByDesc('value')
            ->take(10)
            ->values();

        $damagedValue = 0.0;
        $damagedQty = 0.0;
        $quarantinedQty = 0.0;

        foreach (StockBalance::query()->with(['product', 'warehouse'])
            ->where('company_id', $this->context->companyId())
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get() as $balance) {
            $damagedQty += (float) $balance->damaged;
            $quarantinedQty += (float) $balance->quarantined;

            if ((float) $balance->damaged > 0) {
                $damagedValue += (float) $balance->damaged * $this->unitCost($balance->product, $balance->warehouse);
            }
        }

        $writeoffs = StockWriteoff::query()
            ->where('company_id', $this->context->companyId())
            ->where('status', StockWriteoff::STATUS_APPROVED)
            ->whereDate('writeoff_date', '>=', $from)
            ->whereDate('writeoff_date', '<=', $to)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get();

        return [
            'totals' => [
                'entries' => $entries->count(),
                'damage_entries' => $byKind[StockDamageEntry::KIND_DAMAGE]['count'],
                'loss_entries' => $byKind[StockDamageEntry::KIND_LOSS]['count'],
                'recorded_value' => round($byKind[StockDamageEntry::KIND_DAMAGE]['value'] + $byKind[StockDamageEntry::KIND_LOSS]['value'], 4),
                'written_off_value' => round((float) $writeoffs->sum(fn (StockWriteoff $w) => (float) $w->total_value), 4),
                'writeoff_count' => $writeoffs->count(),
                'pending_writeoffs' => (int) StockWriteoff::query()
                    ->where('company_id', $this->context->companyId())
                    ->pending()
                    ->count(),
            ],
            'by_kind' => $byKind,
            'by_reason' => $byReason,
            'by_warehouse' => $byWarehouse,
            'by_month' => $byMonth,
            'top_products' => $topProducts,
            'held' => [
                'damaged' => round($damagedQty, 4),
                'quarantined' => round($quarantinedQty, 4),
                'damaged_value' => round($damagedValue, 4),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /* -------------------------------------------------------------- helpers --- */

    /**
     * Cost of a unit in a warehouse, from the valuation layers (the product's
     * own method decides which ones). Standard-cost products use their standard
     * cost — the same rule ValuationService::consume applies when the goods
     * actually leave, so a recorded value is never a guess.
     */
    /** Delegates: one definition of a unit's worth, in ValuationService. */
    public function unitCost(Product $product, Warehouse $warehouse): float
    {
        return $this->valuation->unitCost($product, $warehouse);
    }

    /** The loss journal: Dr Inventory Loss & Damage, Cr Inventory. */
    protected function postLoss(StockDamageEntry $entry, User $actor): \App\Domain\Accounting\JournalEntry
    {
        $resolved = [];
        foreach ($this->rules->resolve('stock_loss_posted') as $rule) {
            $resolved[$rule['role']] = $rule['account'];
        }

        if (! isset($resolved['loss'], $resolved['inventory'])) {
            throw new RuntimeException('The stock_loss_posted posting rule needs both a loss and an inventory account.');
        }

        return $this->journal->post([
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => 'Stock loss '.$entry->code,
            'narration' => $entry->reason,
            'journal_type' => 'inventory',
            'source_type' => 'stock_damage_entry',
            'source_id' => $entry->id,
            'source_event' => 'loss_recorded',
            'branch_id' => $entry->branch_id,
            'lines' => [
                ['account_id' => $resolved['loss']->id, 'dc' => 'debit', 'amount' => (float) $entry->total_value],
                ['account_id' => $resolved['inventory']->id, 'dc' => 'credit', 'amount' => (float) $entry->total_value],
            ],
        ], $actor);
    }

    /** The write-off journal: Dr Inventory Loss & Damage, Cr Inventory. */
    protected function postWriteoff(StockWriteoff $writeoff, float $value, User $actor): \App\Domain\Accounting\JournalEntry
    {
        $resolved = [];
        foreach ($this->rules->resolve('stock_writeoff_posted') as $rule) {
            $resolved[$rule['role']] = $rule['account'];
        }

        if (! isset($resolved['loss'], $resolved['inventory'])) {
            throw new RuntimeException('The stock_writeoff_posted posting rule needs both a loss and an inventory account.');
        }

        return $this->journal->post([
            'entry_date' => $writeoff->writeoff_date->toDateString(),
            'description' => 'Stock write-off '.$writeoff->code,
            'narration' => $writeoff->reason,
            'journal_type' => 'inventory',
            'source_type' => 'stock_writeoff',
            'source_id' => $writeoff->id,
            'source_event' => 'writeoff_approved',
            'branch_id' => $writeoff->branch_id,
            'lines' => [
                ['account_id' => $resolved['loss']->id, 'dc' => 'debit', 'amount' => round($value, 4)],
                ['account_id' => $resolved['inventory']->id, 'dc' => 'credit', 'amount' => round($value, 4)],
            ],
        ], $actor);
    }

    protected function companyId(User $actor): int
    {
        return (int) ($this->context->companyId() ?? $actor->company_id);
    }

    protected function warehouse(int $warehouseId, int $companyId): Warehouse
    {
        return Warehouse::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($warehouseId);
    }

    /**
     * Numbers come from the seeded numbering rule for the document type — the
     * prefix belongs to the type, not to the caller. A damage entry and a loss
     * entry share the register's `DL` series; the register separates them by
     * `kind`, not by pretending to be two document types.
     */
    protected function nextCode(string $documentTypeCode, User $actor): string
    {
        $type = DocumentType::query()->where('code', $documentTypeCode)->first();

        if ($type === null) {
            throw new RuntimeException("Document type [{$documentTypeCode}] is not seeded, so documents cannot be numbered.");
        }

        return $this->numbering->allocate($type->id, $actor->default_branch_id ?? 0);
    }
}
