<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Warranty;
use App\Domain\Sales\Services\OrderStateMachine;
use App\Domain\Sales\Services\WarrantyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MarkDeliveryChallanDelivered (delivery side-effects). Challan
 * dispatched → delivered; advances order to delivered via the
 * validated state machine. DOC lifecycle only — stock/GL already
 * posted at InvoiceIssue (or remain pending until then).
 *
 * §16-16 — this is also the moment a warranty begins, because this is the
 * moment the goods reached the customer. Activation happens inside the same
 * transaction as the state change: a warranty for goods that were never
 * delivered, or a delivery whose cover was lost because the request died
 * halfway, are both wrong, and the one place that can prevent both is here.
 * A product with no policy is skipped without comment — see WarrantyService.
 */
class MarkDeliveryChallanDelivered
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
        protected WarrantyService $warranties,
    ) {}

    public function handle(DeliveryChallan $challan, Request $request): DeliveryChallan
    {
        $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($challan, $request) {
            $fresh = DeliveryChallan::query()->whereKey($challan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['dispatched', 'ready'], true)) {
                throw new RuntimeException(sprintf(
                    'Challan %s cannot be marked delivered from status [%s].',
                    $fresh->challan_no,
                    $fresh->status,
                ));
            }

            $fresh->status = 'delivered';
            $fresh->delivered_at = now();
            $fresh->save();

            // §16-16: the cover on these goods starts now, on the delivery
            // date — not on the invoice date and not on the day somebody
            // remembered to raise it.
            $activated = $this->warranties->activateForChallan($fresh, $request->user());

            if ($fresh->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($fresh->sales_order_id)->lockForUpdate()->first();
                if ($order !== null) {
                    $this->advanceOrder($order);
                }
            }

            $this->audit->record([
                'action' => 'sales.delivery_challan_delivered',
                'entity_type' => 'delivery_challan',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'challan_no' => $fresh->challan_no,
                    'status' => 'delivered',
                    'delivered_at' => $fresh->delivered_at?->toIso8601String(),
                    'warranties_activated' => array_map(fn (Warranty $w) => $w->code, $activated),
                ],
            ]);

            return $fresh;
        });
    }

    /** Walk delivery chain to `delivered` when the state machine allows. */
    protected function advanceOrder(SalesOrder $order): void
    {
        $hops = [
            'confirmed' => ['ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'],
            'processing' => ['ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'],
            'ready_to_ship' => ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'],
            'picked_up' => ['in_transit', 'out_for_delivery', 'delivered'],
            'in_transit' => ['out_for_delivery', 'delivered'],
            'out_for_delivery' => ['delivered'],
        ];

        $path = $hops[$order->status] ?? [];
        $targetIndex = array_search('delivered', $path, true);
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
