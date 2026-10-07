<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\PackagingUsage;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ConsumePackaging (02-98): pack an order's packaging items out of
 * stock. Posts one PACK_CONSUME movement through StockLedgerService
 * (layer FIFO sets unit_cost — never client input), records the
 * packaging_usage row, and charges the cost onto the order
 * (sales_orders.packaging_cost).
 *
 * ACCT when perpetual: when the sales_cost rule resolves (inventory
 * carried at value), the packaging cost posts Dr COGS / Cr inventory —
 * periodic/minimal setups without the rule get no fabricated journal.
 * Packaging is never re-costed at invoice time (IssueInvoice only
 * walks order lines), so no double count exists.
 */
class ConsumePackaging
{
    public function __construct(
        protected StockLedgerService $ledger,
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /**
     * @param array{
     *   sales_order_id: int,
     *   packaging_type_id: int,
     *   qty: float|int|string,
     *   notes?: ?string,
     *   consumed_at?: string|null,
     * } $payload
     */
    public function handle(array $payload, Request $request): PackagingUsage
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($payload, $companyId, $request) {
            $type = PackagingType::query()
                ->where('company_id', $companyId)
                ->whereKey($payload['packaging_type_id'] ?? 0)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $type->is_active) {
                throw new RuntimeException("Packaging type {$type->code} is inactive.");
            }

            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->whereKey($payload['sales_order_id'] ?? 0)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
                throw new RuntimeException(sprintf(
                    'Order %s cannot consume packaging from status [%s].',
                    $order->order_no,
                    $order->status,
                ));
            }

            if ($order->warehouse_id === null) {
                throw new RuntimeException(
                    "Order {$order->order_no} has no warehouse — packaging cannot be consumed.",
                );
            }

            $qty = round((float) ($payload['qty'] ?? 0), 3);
            if ($qty <= 0) {
                throw new RuntimeException('Packaging quantity must be greater than zero.');
            }

            $usage = PackagingUsage::create([
                'company_id' => $companyId,
                'branch_id' => $order->branch_id,
                'sales_order_id' => $order->id,
                'packaging_type_id' => $type->id,
                'product_id' => $type->product_id,
                'warehouse_id' => $order->warehouse_id,
                'qty' => $qty,
                'unit_cost' => 0,
                'total_cost' => 0,
                'stock_movement_id' => null,
                'consumed_at' => $payload['consumed_at'] ?? now(),
                'consumed_by' => $request->user()?->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            // Layer FIFO decides the cost; insufficient stock / inactive
            // product roll the whole consumption back with the ledger's
            // own truthful reason.
            $movement = $this->ledger->post([
                'product_id' => $type->product_id,
                'warehouse_id' => $order->warehouse_id,
                'movement_type' => StockMovement::TYPE_PACK_CONSUME,
                'qty' => $qty,
                'source_type' => 'packaging_usage',
                'source_id' => $usage->id,
                'source_event' => 'packaging_consumed',
                'idempotency_key' => "pack-usage:{$usage->id}",
                'narration' => "Packaging {$type->code} for {$order->order_no}",
                'occurred_at' => $usage->consumed_at->toDateTimeString(),
            ], $request->user());

            $unitCost = round((float) ($movement->unit_cost ?? 0), 4);
            $totalCost = round($qty * $unitCost, 4);

            $usage->update([
                'unit_cost' => number_format($unitCost, 4, '.', ''),
                'total_cost' => number_format($totalCost, 4, '.', ''),
                'stock_movement_id' => $movement->id,
            ]);

            $order->packaging_cost = number_format(
                round((float) $order->packaging_cost + $totalCost, 4),
                4,
                '.',
                '',
            );
            $order->save();

            $journalId = $this->postGl($totalCost, $usage, $type, $order, $request);

            $this->audit->record([
                'action' => 'sales.packaging_consumed',
                'entity_type' => 'packaging_usage',
                'entity_id' => $usage->id,
                'actor_id' => $request->user()?->id,
                'after' => [
                    'order_no' => $order->order_no,
                    'packaging_code' => $type->code,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'stock_movement_id' => $movement->id,
                    'order_packaging_cost' => (float) $order->packaging_cost,
                    'journal_entry_id' => $journalId,
                ],
            ]);

            return $usage->fresh();
        });
    }

    /**
     * ACCT when perpetual — the sales_cost rule (Dr COGS / Cr
     * inventory) carries packaging cost into the inventory GL. Absent
     * rule = periodic/minimal setup: order cost still lands, no
     * journal is invented.
     */
    protected function postGl(
        float $totalCost,
        PackagingUsage $usage,
        PackagingType $type,
        SalesOrder $order,
        Request $request,
    ): ?int {
        if ($totalCost <= 0) {
            return null;
        }

        try {
            $byRole = [];
            foreach ($this->rules->resolve('sales_cost') as $r) {
                $byRole[$r['role']] = $r;
            }

            if (! isset($byRole['cogs'], $byRole['inventory'])) {
                return null;
            }

            $entry = $this->posting->post([
                'entry_date' => $usage->consumed_at->toDateString(),
                'description' => "Packaging {$type->code} for {$order->order_no}",
                'journal_type' => 'sales',
                'source_type' => 'packaging_usage',
                'source_id' => $usage->id,
                'source_event' => 'packaging_consumed',
                'branch_id' => $usage->branch_id,
                'lines' => [
                    ['account_id' => $byRole['cogs']['account']->id, 'dc' => 'debit', 'amount' => $totalCost],
                    ['account_id' => $byRole['inventory']['account']->id, 'dc' => 'credit', 'amount' => $totalCost],
                ],
            ], $request->user());

            return $entry->id;
        } catch (RuntimeException) {
            // sales_cost rule absent (periodic/minimal setup) — no GL.
            return null;
        }
    }
}
