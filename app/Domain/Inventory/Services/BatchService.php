<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBatch;
use App\Domain\Inventory\StockBatchExpiryChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Batches and their dates (§04-37 batch half, §04-38, §04-39).
 *
 * The register is the answer to a question the ledger cannot be asked: *when*
 * does this stock go off. So the service does three things and nothing else —
 * it registers the batch a receipt named, it lets a wrong date be corrected with
 * a reason instead of silently, and it hands back the batches worth worrying
 * about, with the quantity read from the valuation layers.
 *
 * It never writes stock. A batch is a label on goods the ledger already moved.
 */
class BatchService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * The batch a movement brought in — created once, then reused. Later
     * receipts of the same reference only fill in blanks: a date that is already
     * recorded is not overwritten by whatever the next delivery says, because
     * that is a correction and corrections go through `updateExpiry()` with a
     * reason attached.
     */
    public function register(
        Product $product,
        Warehouse $warehouse,
        string $batchNo,
        ?string $manufacturedOn = null,
        ?string $expiresOn = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?User $actor = null,
    ): StockBatch {
        $batchNo = trim($batchNo);

        if ($batchNo === '') {
            throw new RuntimeException('A batch needs a number — it is what the label on the shelf says.');
        }

        $batch = StockBatch::query()->firstOrCreate(
            [
                'company_id' => $product->company_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'batch_no' => $batchNo,
            ],
            [
                'manufactured_on' => $manufacturedOn,
                'expires_on' => $expiresOn,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_by' => $actor?->id,
            ],
        );

        if ($batch->wasRecentlyCreated) {
            $this->audit->record([
                'action' => 'inventory.batch_registered',
                'entity_type' => 'stock_batch',
                'entity_id' => $batch->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor?->id,
                'after' => [
                    'batch_no' => $batch->batch_no,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'expires_on' => $batch->expires_on?->toDateString(),
                    'source' => $sourceType,
                ],
            ]);

            return $batch;
        }

        $fill = [];

        if ($batch->manufactured_on === null && $manufacturedOn !== null) {
            $fill['manufactured_on'] = $manufacturedOn;
        }

        if ($batch->expires_on === null && $expiresOn !== null) {
            $fill['expires_on'] = $expiresOn;
        }

        if ($fill !== []) {
            $before = ['manufactured_on' => null, 'expires_on' => null];
            $batch->forceFill($fill)->save();

            $this->audit->record([
                'action' => 'inventory.batch_dated',
                'entity_type' => 'stock_batch',
                'entity_id' => $batch->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor?->id,
                'before' => $before,
                'after' => ['expires_on' => $batch->expires_on?->toDateString(), 'source' => $sourceType],
            ]);
        }

        return $batch;
    }

    /**
     * Correct a batch's expiry date (§04-38). The old date, the new one, who did
     * it and why are kept, because a date that moves without a trace is
     * indistinguishable from a date that was always wrong.
     */
    public function updateExpiry(
        StockBatch $batch,
        ?string $newDate,
        string $reason,
        User $actor,
    ): StockBatch {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('An expiry correction needs a reason — "why was the label wrong?" is the useful part.');
        }

        $before = $batch->expires_on?->toDateString();

        if ($before === $newDate) {
            throw new RuntimeException('That is the date the batch already carries — nothing to correct.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($batch, $newDate, $reason, $actor, $before) {
            StockBatchExpiryChange::create([
                'stock_batch_id' => $batch->id,
                'expires_on_before' => $before,
                'expires_on_after' => $newDate,
                'reason' => $reason,
                'changed_by' => $actor->id,
            ]);

            $batch->forceFill(['expires_on' => $newDate])->save();

            $this->audit->record([
                'action' => 'inventory.batch_expiry_corrected',
                'entity_type' => 'stock_batch',
                'entity_id' => $batch->id,
                'actor_id' => $actor->id,
                'before' => ['expires_on' => $before],
                'after' => ['expires_on' => $newDate, 'reason' => $reason],
            ]);

            return $batch->refresh();
        });
    }

    /**
     * The register: every batch with what is left of it, read from the layers.
     *
     * @param  array{product_id?: int|null, warehouse_id?: int|null, state?: string|null, q?: string|null, only_stocked?: bool}  $filters
     * @return Builder<StockBatch>
     */
    public function batches(array $filters = [], int $withinDays = 30): Builder
    {
        $query = $this->baseQuery($withinDays)
            ->with(['product:id,sku,name', 'warehouse:id,name'])
            ->orderByRaw('expires_on is null')      // dated batches first, undated last
            ->orderBy('expires_on')
            ->orderBy('batch_no');

        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['only_stocked'])) {
            $query->withStock();
        }

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(fn ($q) => $q->where('batch_no', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")));
        }

        $state = $filters['state'] ?? null;

        if ($state === StockBatch::STATE_EXPIRED) {
            $query->expired();
        } elseif ($state === StockBatch::STATE_EXPIRING) {
            $query->expiring($withinDays);
        } elseif ($state === StockBatch::STATE_UNDATED) {
            $query->undated();
        }

        return $query;
    }

    /**
     * The batches that are a problem right now, by kind (§04-39). Each bucket is
     * a query over rows, and only counts stock that is still here: an expired
     * batch nobody has any of is history, not a risk.
     *
     * @return array<string, array{rows: Collection<int, StockBatch>, batches: int, qty: float, value: float}>
     */
    public function buckets(int $withinDays = 30): array
    {
        $build = fn (string $type) => $this->batches(['only_stocked' => true, 'state' => $type], $withinDays)->get();

        $out = [];

        foreach ([StockBatch::STATE_EXPIRED, StockBatch::STATE_EXPIRING, StockBatch::STATE_UNDATED] as $type) {
            $rows = $build($type);

            $out[$type] = [
                'rows' => $rows,
                'batches' => $rows->count(),
                'qty' => round((float) $rows->sum('remaining_qty'), 4),
                'value' => round((float) $rows->sum('remaining_value'), 4),
            ];
        }

        return $out;
    }

    /** Batches that deserve an alert today, newest-problem first (§04-39). */
    public function alertRows(int $withinDays): Collection
    {
        return $this->batches(['only_stocked' => true], $withinDays)
            ->where(fn ($q) => $q->expired()->orWhere(fn ($qq) => $qq->expiring($withinDays)))
            ->get();
    }

    /**
     * What the screens read: the batch, plus the two figures pulled out of the
     * layers in the same query (so a list of a hundred batches is one query, not
     * two hundred — and the quantity is still the ledger's, never a copy).
     *
     * @return Builder<StockBatch>
     */
    protected function baseQuery(int $withinDays): Builder
    {
        return StockBatch::query()
            ->where('company_id', $this->context->companyId())
            ->selectRaw('stock_batches.*')
            ->selectRaw('(select coalesce(sum(l.qty_remaining), 0) from stock_layers l where l.stock_batch_id = stock_batches.id and l.qty_remaining > 0) as remaining_qty')
            ->selectRaw('(select coalesce(sum(l.qty_remaining * l.unit_cost), 0) from stock_layers l where l.stock_batch_id = stock_batches.id and l.qty_remaining > 0) as remaining_value')
            ->selectRaw('case when stock_batches.expires_on is null then ? when stock_batches.expires_on < ? then ? when stock_batches.expires_on <= ? then ? else ? end as state', [
                StockBatch::STATE_UNDATED,
                now()->toDateString(),
                StockBatch::STATE_EXPIRED,
                now()->addDays($withinDays)->toDateString(),
                StockBatch::STATE_EXPIRING,
                StockBatch::STATE_OK,
            ]);
    }
}
