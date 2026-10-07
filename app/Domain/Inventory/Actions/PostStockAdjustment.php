<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockAdjustment;
use App\Domain\Inventory\StockAdjustmentLine;
use App\Domain\Inventory\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PostStockAdjustment (04-25). Line-level +/− with reason; creates
 * ADJUST_IN / ADJUST_OUT movements via StockLedgerService only.
 */
class PostStockAdjustment
{
    public function __construct(
        protected StockLedgerService $ledger,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
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
        return $this->post($payload, $request->user());
    }

    /**
     * The same write, addressed to an actor instead of an HTTP request — so a
     * document that is itself the source of the adjustments (a posted count,
     * §04-31) reaches the ledger through one path, not a second copy of it.
     */
    public function post(array $payload, User $actor): StockAdjustment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Adjustment requires at least one line.');
        }

        if (trim((string) ($payload['reason'] ?? '')) === '') {
            throw new RuntimeException('An adjustment reason is required.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $actor) {
            $docType = DocumentType::query()->where('code', 'stock_adjustment')->first()
                ?? abort(500, 'stock_adjustment document type is not seeded.');

            $adjustmentNo = $this->numbering->allocate(
                $docType->id,
                $actor->default_branch_id,
            );

            $adjustment = StockAdjustment::create([
                'company_id' => $companyId,
                'branch_id' => $actor->default_branch_id,
                'warehouse_id' => (int) $payload['warehouse_id'],
                'adjustment_no' => $adjustmentNo,
                'adjustment_date' => $payload['adjustment_date'] ?? now()->toDateString(),
                'reason' => $payload['reason'],
                'status' => StockAdjustment::STATUS_POSTED,
                'created_by' => $actor->id,
                'posted_at' => now(),
            ]);

            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $delta = (float) ($line['qty_delta'] ?? 0);

                if ($delta == 0.0) {
                    throw new RuntimeException('Adjustment line quantity delta cannot be zero.');
                }

                $type = $delta > 0
                    ? StockMovement::TYPE_ADJUST_IN
                    : StockMovement::TYPE_ADJUST_OUT;

                StockAdjustmentLine::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => (int) $line['product_id'],
                    'qty_delta' => number_format($delta, 4, '.', ''),
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'narration' => $line['narration'] ?? null,
                    'line_no' => $lineNo,
                ]);

                $this->ledger->post([
                    'product_id' => (int) $line['product_id'],
                    'warehouse_id' => (int) $payload['warehouse_id'],
                    'movement_type' => $type,
                    'qty' => abs($delta),
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'source_type' => 'stock_adjustment',
                    'source_id' => $adjustment->id,
                    'source_event' => 'adjustment_posted',
                    'idempotency_key' => sprintf('adj:%d:%d:%d', $adjustment->id, $lineNo, (int) $line['product_id']),
                    'narration' => $payload['reason'],
                ], $actor);
            }

            $this->audit->record([
                'action' => 'inventory.adjustment_posted',
                'entity_type' => 'stock_adjustment',
                'entity_id' => $adjustment->id,
                'actor_id' => $actor->id,
                'after' => [
                    'adjustment_no' => $adjustmentNo,
                    'reason' => $adjustment->reason,
                    'line_count' => $lineNo,
                ],
            ]);

            return $adjustment->load('lines');
        });
    }
}
