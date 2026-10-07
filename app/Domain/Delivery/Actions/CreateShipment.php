<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\CourierPort;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\ShipmentLine;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateShipment (02-89): courier handoff rows with explicit
 * product/qty lines. The courier acceptance state comes from
 * CourierPort (assigned + external_ref only when configured) —
 * physical dispatch (stock TRANSIT_OUT + order chain) happens later
 * through DispatchShipment, never at creation.
 */
class CreateShipment
{
    public function __construct(
        protected TenantContext $context,
        protected CourierPort $courierPort,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{courier_id: int|string, lines: array<int, array{product_id: int|string, qty: float|int|string}>}  $payload
     */
    public function handle(SalesOrder $order, array $payload, Request $request): Shipment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $order->company_id !== $companyId) {
            abort(404);
        }

        if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
            throw new RuntimeException(sprintf(
                'Order %s cannot be shipped from status [%s].',
                $order->order_no,
                $order->status,
            ));
        }

        $courier = Courier::query()
            ->where('company_id', $companyId)
            ->find((int) ($payload['courier_id'] ?? 0));

        if ($courier === null || ! $courier->is_active) {
            throw new RuntimeException('Courier not found or inactive.');
        }

        $lines = $payload['lines'] ?? [];
        if ($lines === []) {
            throw new RuntimeException('Shipment requires at least one line.');
        }

        return DB::transaction(function () use ($order, $courier, $lines, $companyId, $request) {
            $orderLines = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()->lines;

            $ordered = [];
            foreach ($orderLines as $line) {
                $ordered[(int) $line->product_id] = (float) $line->qty;
            }

            $alreadyShipped = ShipmentLine::query()
                ->where('company_id', $companyId)
                ->whereIn('shipment_id', Shipment::query()
                    ->where('sales_order_id', $order->id)
                    ->where('status', '!=', 'cancelled')
                    ->select('id'))
                ->get()
                ->groupBy(fn ($line) => (int) $line->product_id)
                ->map(fn ($group) => $group->sum(fn ($line) => (float) $line->qty));

            $validated = [];
            foreach ($lines as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (float) ($line['qty'] ?? 0);

                if (! isset($ordered[$productId])) {
                    throw new RuntimeException('Product is not on this order.');
                }

                if ($qty <= 0) {
                    throw new RuntimeException('Shipment quantity must be greater than zero.');
                }

                $remaining = $ordered[$productId] - (float) $alreadyShipped->get($productId, 0);
                if ($qty > $remaining + 1e-9) {
                    throw new RuntimeException(sprintf(
                        'Shipment quantity exceeds the remaining ordered quantity for product #%d.',
                        $productId,
                    ));
                }

                $validated[$productId] = ($validated[$productId] ?? 0.0) + $qty;
            }

            $dispatch = $this->courierPort->assign($courier, $order);

            $shipment = Shipment::query()->create([
                'company_id' => $companyId,
                'sales_order_id' => $order->id,
                'courier_id' => $courier->id,
                'status' => $dispatch['dispatched']
                    ? Shipment::STATUS_ASSIGNED
                    : Shipment::STATUS_PENDING_DISPATCH,
                'external_ref' => $dispatch['external_ref'],
                'dispatched_at' => null,
                'assigned_by' => $request->user()?->id,
            ]);

            foreach ($validated as $productId => $qty) {
                ShipmentLine::query()->create([
                    'company_id' => $companyId,
                    'shipment_id' => $shipment->id,
                    'product_id' => $productId,
                    'qty' => $qty,
                ]);
            }

            $this->audit->record([
                'action' => 'sales.shipment_created',
                'entity_type' => 'shipment',
                'entity_id' => $shipment->id,
                'actor_id' => $request->user()?->id,
                'after' => [
                    'order_no' => $order->order_no,
                    'courier' => $courier->name,
                    'status' => $shipment->status,
                    'line_count' => count($validated),
                ],
            ]);

            return $shipment->load('lines');
        });
    }
}
