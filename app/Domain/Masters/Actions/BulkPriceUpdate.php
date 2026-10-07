<?php

namespace App\Domain\Masters\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Jobs\ApplyBulkPriceUpdate;
use App\Domain\Masters\PriceBulkUpdate;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\ProductPriceHistory;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk price update (02-109): preview (pure, no writes) → confirm
 * (batch row + queued job) → apply, with a workflow gate when the
 * largest change meets the threshold. Every applied price line writes an
 * append-only history row and the batch records an audited diff.
 */
class BulkPriceUpdate
{
    public const ENTITY_TYPE = 'price_bulk_update';

    public const APPROVAL_ACTION = 'apply';

    public const THRESHOLD_PCT = 20.0;

    /** Diffs kept on the audit row (batches may be huge). */
    public const AUDIT_DIFF_CAP = 50;

    public function __construct(
        protected WorkflowEngine $workflow,
        protected AuditRecorder $audit,
    ) {}

    public function thresholdPct(): float
    {
        return (float) config('erp.pricing.bulk_update_threshold_pct', self::THRESHOLD_PCT);
    }

    /**
     * Pure preview: computes matched rows + summary from the source
     * tables. Writes nothing.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function preview(User $user, array $payload): array
    {
        return $this->rowsFor($user->company_id, $payload);
    }

    /** Create the batch and queue the apply job. */
    public function confirm(User $user, array $payload): PriceBulkUpdate
    {
        $batch = PriceBulkUpdate::query()->create([
            'company_id' => $user->company_id,
            'price_list_id' => (int) $payload['price_list_id'],
            'change_type' => $payload['change_type'],
            'percent' => $payload['percent'] ?? null,
            'set_price' => $payload['set_price'] ?? null,
            'payload' => $payload,
            'note' => $payload['note'] ?? null,
            'status' => PriceBulkUpdate::STATUS_QUEUED,
            'threshold_pct' => $this->thresholdPct(),
            'created_by' => $user->id,
        ]);

        ApplyBulkPriceUpdate::dispatch($batch->id);

        return $batch;
    }

    public function execute(PriceBulkUpdate $batch): void
    {
        if ($batch->status === PriceBulkUpdate::STATUS_APPLIED) {
            return;
        }

        $user = User::query()->find($batch->created_by);

        $result = $this->rowsFor($batch->company_id, $batch->payload);

        if ($result['summary']['changed'] === 0) {
            $batch->forceFill([
                'status' => PriceBulkUpdate::STATUS_APPLIED,
                'row_count' => 0,
                'applied_at' => now(),
                'error' => null,
            ])->save();

            if ($user !== null) {
                $this->recordAudit($batch, $result, $user);
            }

            return;
        }

        $threshold = (float) $batch->threshold_pct;

        if ($threshold > 0 && $result['summary']['max_abs_pct'] >= $threshold) {
            if ($this->gate($batch, $result, $user) === 'pending') {
                return;
            }
        }

        $this->apply($batch, $result, $user);
    }

    /**
     * Workflow gate (mirror of OrderApprovalGate semantics):
     * pending → hold, approved → proceed, definition → submitted/hold,
     * no definition → bypass/proceed.
     *
     * @return 'pending'|'proceed'
     */
    protected function gate(PriceBulkUpdate $batch, array $result, ?User $user): string
    {
        $scope = fn () => ApprovalRequest::query()
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $batch->id)
            ->where('action', self::APPROVAL_ACTION);

        if ($scope()->where('status', 'pending')->exists()) {
            $this->hold($batch, $result, $user);

            return 'pending';
        }

        if ($scope()->where('status', 'approved')->exists()) {
            return 'proceed';
        }

        if ($user === null) {
            $batch->forceFill([
                'status' => PriceBulkUpdate::STATUS_FAILED,
                'error' => 'Batch creator no longer exists — cannot seek approval.',
            ])->save();

            return 'pending';
        }

        $approval = $this->workflow->submit([
            'entity_type' => self::ENTITY_TYPE,
            'entity_id' => $batch->id,
            'action' => self::APPROVAL_ACTION,
            'subject' => sprintf('Bulk price update #%d', $batch->id),
            'branch_id' => $user->default_branch_id,
            'submitted_by' => $user,
            'snapshot' => [
                'price_list_id' => $batch->price_list_id,
                'change_type' => $batch->change_type,
                'changed' => $result['summary']['changed'],
                'max_abs_pct' => $result['summary']['max_abs_pct'],
                'note' => $batch->note,
            ],
            'context' => [
                'entity_type' => self::ENTITY_TYPE,
                'branch_id' => $user->default_branch_id,
            ],
        ]);

        if ($approval !== null) {
            $this->hold($batch, $result, $user);

            return 'pending';
        }

        return 'proceed'; // no matching workflow definition — bypass
    }

    protected function hold(PriceBulkUpdate $batch, array $result, ?User $user): void
    {
        $batch->forceFill(['status' => PriceBulkUpdate::STATUS_PENDING])->save();

        if ($user !== null) {
            $this->recordAudit($batch, $result, $user);
        }
    }

    protected function apply(PriceBulkUpdate $batch, array $result, ?User $user): void
    {
        DB::transaction(function () use ($batch, $result): void {
            foreach ($result['rows'] as $row) {
                if ($row['new_price'] === null || abs($row['delta']) < 0.0001) {
                    continue;
                }

                DB::table('price_list_items')
                    ->where('id', $row['item_id'])
                    ->update([
                        'price' => $row['new_price'],
                        'updated_at' => now(),
                    ]);

                ProductPriceHistory::query()->create([
                    'company_id' => $batch->company_id,
                    'product_id' => $row['product_id'],
                    'price_list_id' => $batch->price_list_id,
                    'price_list_item_id' => $row['item_id'],
                    'old_price' => $row['old_price'],
                    'new_price' => $row['new_price'],
                    'percent_change' => $row['percent_change'],
                    'source' => 'bulk_update',
                    'price_bulk_update_id' => $batch->id,
                    'changed_by' => $batch->created_by,
                    'note' => $batch->note,
                ]);
            }

            $batch->forceFill([
                'status' => PriceBulkUpdate::STATUS_APPLIED,
                'row_count' => $result['summary']['changed'],
                'applied_at' => now(),
                'error' => null,
            ])->save();
        });

        if ($user !== null) {
            $this->recordAudit($batch, $result, $user);
        }
    }

    /**
     * Compute rows from the source tables for a payload. Company-scoped:
     * the price list and every product must belong to $companyId.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function rowsFor(int $companyId, array $payload): array
    {
        $priceList = PriceList::query()
            ->where('company_id', $companyId)
            ->find((int) ($payload['price_list_id'] ?? 0));

        if ($priceList === null) {
            throw ValidationException::withMessages([
                'price_list_id' => 'Unknown price list.',
            ]);
        }

        $query = DB::table('price_list_items')
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->join('products', 'products.id', '=', 'price_list_items.product_id')
            ->where('price_lists.id', $priceList->id)
            ->where('price_lists.company_id', $companyId)
            ->where('products.company_id', $companyId)
            ->select(
                'price_list_items.id as item_id',
                'products.id as product_id',
                'products.code as product_code',
                'products.name as product_name',
                'price_list_items.price as old_price',
            );

        $productIds = array_values(array_filter(array_map('intval', $payload['product_ids'] ?? [])));

        if ($productIds !== []) {
            $query->whereIn('products.id', $productIds);
        }

        if (! empty($payload['category_id'])) {
            $query->where('products.product_category_id', (int) $payload['category_id']);
        }

        if (! empty($payload['brand_id'])) {
            $query->where('products.brand_id', (int) $payload['brand_id']);
        }

        $changeType = $payload['change_type'];
        $percent = isset($payload['percent']) ? (float) $payload['percent'] : null;
        $setPrice = isset($payload['set_price']) ? round((float) $payload['set_price'], 2) : null;

        $rows = [];
        $changed = 0;
        $maxAbsPct = 0.0;

        foreach ($query->orderBy('products.code')->get() as $source) {
            $old = round((float) $source->old_price, 2);
            $new = $changeType === 'percent'
                ? round($old * (1 + ((float) $percent) / 100), 2)
                : (float) $setPrice;
            $new = max(0.0, $new);

            $delta = round($new - $old, 2);
            $pct = $old > 0 ? round(($delta / $old) * 100, 4) : null;
            $isChanged = abs($delta) >= 0.005;

            if ($isChanged) {
                $changed++;

                if ($pct !== null) {
                    $maxAbsPct = max($maxAbsPct, abs($pct));
                }
            }

            $rows[] = [
                'item_id' => (int) $source->item_id,
                'product_id' => (int) $source->product_id,
                'product_code' => (string) $source->product_code,
                'product_name' => (string) $source->product_name,
                'old_price' => $old,
                'new_price' => $new,
                'delta' => $delta,
                'percent_change' => $pct,
                'changed' => $isChanged,
            ];
        }

        return [
            'rows' => $rows,
            'summary' => [
                'matched' => count($rows),
                'changed' => $changed,
                'max_abs_pct' => round($maxAbsPct, 4),
                'threshold_pct' => $this->thresholdPct(),
                'approval_required' => round($maxAbsPct, 4) >= $this->thresholdPct(),
            ],
        ];
    }

    protected function recordAudit(PriceBulkUpdate $batch, array $result, User $user): void
    {
        $diffs = array_values(array_map(
            fn (array $row): array => [
                'product_id' => $row['product_id'],
                'product_code' => $row['product_code'],
                'old' => $row['old_price'],
                'new' => $row['new_price'],
                'pct' => $row['percent_change'],
            ],
            array_filter($result['rows'], fn (array $row): bool => $row['changed']),
        ));

        $this->audit->record([
            'action' => 'sales.price_bulk_update',
            'entity_type' => 'price_bulk_update',
            'entity_id' => $batch->id,
            'actor' => $user,
            'company_id' => $batch->company_id,
            'after' => [
                'price_list_id' => $batch->price_list_id,
                'change_type' => $batch->change_type,
                'percent' => $batch->percent !== null ? (float) $batch->percent : null,
                'set_price' => $batch->set_price !== null ? (float) $batch->set_price : null,
                'status' => $batch->status,
                'matched' => $result['summary']['matched'],
                'changed' => $result['summary']['changed'],
                'max_abs_pct' => $result['summary']['max_abs_pct'],
                'diffs' => array_slice($diffs, 0, self::AUDIT_DIFF_CAP),
            ],
        ]);
    }
}
