<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * CreateOpeningStock (04-22). OPENING movement per line; idempotent per
 * (product, warehouse, source_id) when an opening batch id is supplied.
 */
class CreateOpeningStock
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
     *   lines: array<int, array{product_id: int, qty: float, unit_cost?: float|null}>,
     *   narration?: string|null,
     * } $payload
     * @return array<int, StockMovement>
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $warehouseId = (int) $payload['warehouse_id'];
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Opening stock requires at least one line.');
        }

        $movements = [];

        foreach ($lines as $i => $line) {
            $qty = (float) ($line['qty'] ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException('Opening quantities must be greater than zero.');
            }

            $movements[] = $this->ledger->post([
                'product_id' => (int) $line['product_id'],
                'warehouse_id' => $warehouseId,
                'movement_type' => StockMovement::TYPE_OPENING,
                'qty' => $qty,
                'unit_cost' => $line['unit_cost'] ?? null,
                'source_type' => 'opening_stock',
                'source_id' => null,
                'source_event' => 'opening',
                'idempotency_key' => sprintf(
                    'opening:%d:%d:%d:%d',
                    $companyId,
                    $warehouseId,
                    (int) $line['product_id'],
                    $i,
                ).':'.($payload['idempotency_suffix'] ?? 'session'),
                'narration' => $payload['narration'] ?? 'Opening stock',
            ], $request->user());
        }

        $this->audit->record([
            'action' => 'inventory.opening_posted',
            'entity_type' => 'stock_movement',
            'entity_id' => $movements[0]->id ?? null,
            'actor_id' => $request->user()?->id,
            'after' => [
                'warehouse_id' => $warehouseId,
                'line_count' => count($movements),
                'total_qty' => array_sum(array_map(fn ($m) => (float) $m->qty_signed, $movements)),
            ],
        ]);

        return $movements;
    }
}
