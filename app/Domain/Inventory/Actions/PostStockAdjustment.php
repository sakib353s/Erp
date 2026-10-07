<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\Services\ValuationService;
use App\Domain\Inventory\StockAdjustment;
use App\Domain\Inventory\StockAdjustmentLine;
use App\Domain\Inventory\StockMovement;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PostStockAdjustment (04-25, approval in 04-26). Line-level +/− with reason;
 * creates ADJUST_IN / ADJUST_OUT movements via StockLedgerService only.
 *
 * Three doors, one write path:
 *
 *  · `handle(payload, $request)` — what a controller calls;
 *  · `submit(payload, $actor)` — the same, but it asks the threshold first: an
 *    adjustment worth at least `inventory.adjustment_approval_above` is stored
 *    `pending_approval` and touches no stock until `approve()` runs. A pending
 *    document has no ledger rows behind it, so "waiting" can never be mistaken
 *    for "done";
 *  · `post(payload, $actor)` — no gate. A posted stock count (§04-31) is already
 *    the result of someone counting and someone posting, so it is not put
 *    through a second approval.
 *
 * The value the threshold judges is stored on the document (`total_value`), so
 * the reason a given adjustment needed approval stays readable after somebody
 * changes the setting.
 */
class PostStockAdjustment
{
    /** 0 (or less) means every adjustment posts immediately, as it always has. */
    public const SETTING_GROUP = 'inventory';

    public const SETTING_KEY = 'adjustment_approval_above';

    public function __construct(
        protected StockLedgerService $ledger,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected ValuationService $valuation,
        protected SettingService $settings,
    ) {}

    /**
     * @param array{
     *   warehouse_id: int,
     *   adjustment_date: string,
     *   reason: string,
     *   lines: array<int, array{product_id: int, qty_delta: float, unit_cost?: float|null, narration?: string|null}>,
     * } $payload
     */
    public function handle(array $payload, Request $request): StockAdjustment
    {
        return $this->submit($payload, $request->user());
    }

    /** The user-facing path: store the document, then post it or hold it back. */
    public function submit(array $payload, User $actor): StockAdjustment
    {
        return DB::transaction(function () use ($payload, $actor) {
            $adjustment = $this->createDocument($payload, $actor);

            if ($adjustment->isPending()) {
                $this->audit->record([
                    'action' => 'inventory.adjustment_submitted',
                    'entity_type' => 'stock_adjustment',
                    'entity_id' => $adjustment->id,
                    'branch_id' => $adjustment->branch_id,
                    'actor_id' => $actor->id,
                    'after' => [
                        'adjustment_no' => $adjustment->adjustment_no,
                        'total_value' => (string) $adjustment->total_value,
                        'line_count' => $adjustment->lines->count(),
                    ],
                ]);

                return $adjustment->load('lines');
            }

            $this->apply($adjustment, $actor);

            return $adjustment->load('lines');
        });
    }

    /**
     * The direct write path, with no threshold in front of it. Used where the
     * document itself is the decision (a posted count) and by `approve()`.
     */
    public function post(array $payload, User $actor): StockAdjustment
    {
        return DB::transaction(function () use ($payload, $actor) {
            $adjustment = $this->createDocument($payload, $actor, ignoreThreshold: true);
            $this->apply($adjustment, $actor);

            return $adjustment->load('lines');
        });
    }

    /**
     * Approve a held adjustment. The movements are written now, and only now —
     * the document carried no stock while it waited. Maker ≠ checker, because an
     * approval that can be given to oneself is not an approval.
     */
    public function approve(StockAdjustment $adjustment, User $actor, ?string $note = null): StockAdjustment
    {
        $this->assertApprovable($adjustment, $actor);

        return DB::transaction(function () use ($adjustment, $actor, $note) {
            $adjustment->forceFill([
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_note' => $note,
            ])->save();

            $this->apply($adjustment, $actor);

            $this->audit->record([
                'action' => 'inventory.adjustment_approved',
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adjustment->id,
                'branch_id' => $adjustment->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'adjustment_no' => $adjustment->adjustment_no,
                    'total_value' => (string) $adjustment->total_value,
                    'note' => $note,
                ],
            ]);

            return $adjustment->refresh()->load('lines');
        });
    }

    /** Refuse a held adjustment. A refusal moves nothing — that is the point. */
    public function reject(StockAdjustment $adjustment, User $actor, string $note): StockAdjustment
    {
        $this->assertApprovable($adjustment, $actor);

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('A rejection needs a reason — the person who raised it has to know what to fix.');
        }

        return DB::transaction(function () use ($adjustment, $actor, $note) {
            $adjustment->forceFill([
                'status' => StockAdjustment::STATUS_REJECTED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_note' => $note,
            ])->save();

            $this->audit->record([
                'action' => 'inventory.adjustment_rejected',
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adjustment->id,
                'branch_id' => $adjustment->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'adjustment_no' => $adjustment->adjustment_no,
                    'note' => $note,
                ],
            ]);

            return $adjustment->refresh()->load('lines');
        });
    }

    /** The value above which a document is worth a second pair of eyes. 0 = never. */
    public function approvalThreshold(): float
    {
        return max(0.0, (float) $this->settings->get(self::SETTING_GROUP, self::SETTING_KEY, 0));
    }

    /* --------------------------------------------------------------- internals */

    /**
     * Store the header and the lines, and decide whether this one is held back.
     * The value is computed here and never recomputed later, so the threshold
     * that judged it is the threshold that is recorded.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function createDocument(array $payload, User $actor, bool $ignoreThreshold = false): StockAdjustment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Adjustment requires at least one line.');
        }

        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw new RuntimeException('An adjustment reason is required.');
        }

        $warehouse = Warehouse::query()->findOrFail((int) $payload['warehouse_id']);

        $docType = DocumentType::query()->where('code', 'stock_adjustment')->first()
            ?? abort(500, 'stock_adjustment document type is not seeded.');

        $adjustmentNo = $this->numbering->allocate(
            $docType->id,
            $actor->default_branch_id,
        );

        $prepared = [];
        $value = 0.0;

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail((int) $line['product_id']);
            $delta = (float) ($line['qty_delta'] ?? 0);

            if ($delta == 0.0) {
                throw new RuntimeException('Adjustment line quantity delta cannot be zero.');
            }

            // The cost the line is judged at: what the user typed, or what the
            // ledger says the goods cost. A decrease is finally valued by the
            // layers it consumes, so this figure is the threshold's yardstick,
            // not a promise about the GL.
            $given = $line['unit_cost'] ?? null;
            $cost = ($given === null || $given === '')
                ? $this->valuation->unitCost($product, $warehouse)
                : (float) $given;


            $value += abs($delta) * $cost;

            $prepared[] = [
                'product_id' => $product->id,
                'qty_delta' => number_format($delta, 4, '.', ''),
                'unit_cost' => $cost,
                'narration' => $line['narration'] ?? null,
            ];
        }

        $value = round($value, 4);
        $threshold = $ignoreThreshold ? 0.0 : $this->approvalThreshold();
        $pending = $threshold > 0 && $value >= $threshold;

        $adjustment = StockAdjustment::create([
            'company_id' => $companyId,
            'branch_id' => $actor->default_branch_id,
            'warehouse_id' => $warehouse->id,
            'adjustment_no' => $adjustmentNo,
            'adjustment_date' => $payload['adjustment_date'] ?? now()->toDateString(),
            'reason' => $payload['reason'],
            'total_value' => $value,
            'status' => $pending ? StockAdjustment::STATUS_PENDING : StockAdjustment::STATUS_POSTED,
            'created_by' => $actor->id,
            'posted_at' => $pending ? null : now(),
        ]);

        foreach ($prepared as $index => $line) {
            StockAdjustmentLine::create([
                'stock_adjustment_id' => $adjustment->id,
                'product_id' => $line['product_id'],
                'qty_delta' => $line['qty_delta'],
                'unit_cost' => $line['unit_cost'],
                'narration' => $line['narration'],
                'line_no' => $index + 1,
            ]);
        }

        return $adjustment->load('lines');
    }

    /**
     * Write the movements for a document that has decided to become true, and
     * stamp it posted. One line = one movement, keyed by line and product, so a
     * second run can only ever be a no-op.
     *
     * Each line carries the cost it was written at, and that is the cost the
     * ledger is handed: a document approved three days after it was raised posts
     * the figures somebody actually approved, instead of quietly re-pricing
     * itself against a moving weighted average. An outbound line's cost is
     * decided by the layers it consumes, as always.
     */
    protected function apply(StockAdjustment $adjustment, User $actor): void
    {
        $adjustment->loadMissing('lines');

        foreach ($adjustment->lines as $line) {
            $delta = (float) $line->qty_delta;

            $type = $delta > 0
                ? StockMovement::TYPE_ADJUST_IN
                : StockMovement::TYPE_ADJUST_OUT;

            $this->ledger->post([
                'product_id' => (int) $line->product_id,
                'warehouse_id' => (int) $adjustment->warehouse_id,
                'movement_type' => $type,
                'qty' => abs($delta),
                'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                'source_type' => 'stock_adjustment',
                'source_id' => $adjustment->id,
                'source_event' => 'adjustment_posted',
                'idempotency_key' => sprintf('adj:%d:%d:%d', $adjustment->id, (int) $line->line_no, (int) $line->product_id),
                'narration' => $adjustment->reason,
            ], $actor);
        }

        $adjustment->forceFill([
            'status' => StockAdjustment::STATUS_POSTED,
            'posted_at' => $adjustment->posted_at ?? now(),
        ])->save();

        $this->audit->record([
            'action' => 'inventory.adjustment_posted',
            'entity_type' => 'stock_adjustment',
            'entity_id' => $adjustment->id,
            'branch_id' => $adjustment->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'adjustment_no' => $adjustment->adjustment_no,
                'reason' => $adjustment->reason,
                'total_value' => (string) $adjustment->total_value,
                'line_count' => $adjustment->lines->count(),
            ],
        ]);
    }

    protected function assertApprovable(StockAdjustment $adjustment, User $actor): void
    {
        if ($this->context->companyId() !== null
            && (int) $adjustment->company_id !== (int) $this->context->companyId()) {
            throw new RuntimeException('That adjustment belongs to another company.');
        }

        if (! $adjustment->isPending()) {
            throw new RuntimeException('Only an adjustment waiting for approval can be decided.');
        }

        if ((int) $adjustment->created_by === (int) $actor->id) {
            throw new RuntimeException('An adjustment cannot be approved or rejected by the person who raised it.');
        }
    }
}
