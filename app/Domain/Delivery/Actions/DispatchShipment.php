<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DispatchShipment (02-89): goods physically leave the warehouse.
 * Stock stage = TRANSIT_OUT per stocked line from the order's
 * warehouse (layers consumed, on_hand → in_transit), skipped with a
 * truthful reason when the order has no warehouse or the invoice
 * already issued the stock — never a fabricated movement. The order
 * walks the validated state chain to in_transit; dispatch after
 * invoice (order completed) still ships, without touching stock.
 */
class DispatchShipment
{
    public function __construct(
        protected TenantContext $context,
        protected StockLedgerService $ledger,
        protected OrderStateMachine $stateMachine,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Shipment $shipment, Request $request): Shipment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $shipment->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($shipment, $companyId, $request) {
            $fresh = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, [Shipment::STATUS_PENDING_DISPATCH, Shipment::STATUS_ASSIGNED], true)) {
                throw new RuntimeException(sprintf(
                    'Shipment cannot be dispatched from status [%s].',
                    $fresh->status,
                ));
            }

            $lines = $fresh->lines;
            if ($lines->isEmpty()) {
                throw new RuntimeException('Shipment has no lines to dispatch.');
            }

            $order = SalesOrder::query()->whereKey($fresh->sales_order_id)->lockForUpdate()->firstOrFail();

            [$stockPostable, $stockReason] = $this->stockStage($order, $companyId);

            $posted = 0;
            if ($stockPostable) {
                foreach ($lines as $line) {
                    $product = Product::query()
                        ->where('company_id', $companyId)
                        ->find($line->product_id);

                    if ($product === null || ! $product->is_stocked || ! $product->is_active) {
                        continue;
                    }

                    $this->ledger->post([
                        'product_id' => $product->id,
                        'warehouse_id' => $order->warehouse_id,
                        'movement_type' => StockMovement::TYPE_TRANSIT_OUT,
                        'qty' => (float) $line->qty,
                        'source_type' => 'shipment',
                        'source_id' => $fresh->id,
                        'source_event' => 'shipment_dispatched',
                        'idempotency_key' => sprintf('ship-out:%d:%d', $fresh->id, $product->id),
                        'narration' => 'Dispatch shipment for '.$order->order_no,
                    ], $request->user());
                    $posted++;
                }
            }

            $fresh->status = Shipment::STATUS_DISPATCHED;
            $fresh->dispatched_at ??= now();
            $fresh->save();

            $this->advanceOrder($order, 'in_transit');

            $this->audit->record([
                'action' => 'sales.shipment_dispatched',
                'entity_type' => 'shipment',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()?->id,
                'after' => [
                    'order_no' => $order->order_no,
                    'status' => $fresh->status,
                    'stock_posted' => $stockPostable,
                    'posted_lines' => $posted,
                    'stock_reason' => $stockReason,
                ],
            ]);

            return $fresh->load('lines');
        });
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    protected function stockStage(SalesOrder $order, int $companyId): array
    {
        if ($order->warehouse_id === null) {
            return [false, 'Order has no warehouse configured.'];
        }

        $issued = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('movement_type', StockMovement::TYPE_SALES_OUT)
            ->where('source_type', 'invoice')
            ->whereIn(
                'source_id',
                Invoice::query()->where('sales_order_id', $order->id)->select('id'),
            )
            ->exists();

        if ($issued) {
            return [false, 'Invoice already issued stock for this order.'];
        }

        return [true, null];
    }

    /** Walk the validated delivery chain forward to $target when reachable. */
    protected function advanceOrder(SalesOrder $order, string $target): void
    {
        $hops = [
            'confirmed' => ['ready_to_ship', 'picked_up', 'in_transit'],
            'processing' => ['ready_to_ship', 'picked_up', 'in_transit'],
            'ready_to_ship' => ['picked_up', 'in_transit'],
            'picked_up' => ['in_transit'],
            'in_transit' => [],
        ];

        $path = $hops[$order->status] ?? [];
        $targetIndex = array_search($target, $path, true);
        if ($targetIndex === false) {
            return;
        }

        foreach (array_slice($path, 0, $targetIndex + 1) as $step) {
            if ($this->stateMachine->canTransition($order->status, $step)) {
                $this->stateMachine->transition($order, $step);
            }
        }
    }
}
