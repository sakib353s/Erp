<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DispatchDeliveryChallan (delivery side-effects). Moves challan
 * draft|ready → dispatched (timestamps + optional courier/tracking)
 * and advances the linked order along the delivery chain to
 * out_for_delivery via OrderStateMachine. DOC lifecycle only —
 * stock issue remains at InvoiceIssue stage.
 */
class DispatchDeliveryChallan
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param  array{courier_name?: string|null, tracking_no?: string|null, notes?: string|null}  $payload
     */
    public function handle(DeliveryChallan $challan, array $payload, Request $request): DeliveryChallan
    {
        $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($challan, $payload, $request) {
            $fresh = DeliveryChallan::query()->whereKey($challan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['draft', 'ready'], true)) {
                throw new RuntimeException(sprintf(
                    'Challan %s cannot be dispatched from status [%s].',
                    $fresh->challan_no,
                    $fresh->status,
                ));
            }

            $fresh->status = 'dispatched';
            $fresh->dispatched_at = now();
            if (isset($payload['courier_name']) && trim((string) $payload['courier_name']) !== '') {
                $fresh->courier_name = trim((string) $payload['courier_name']);
            }
            if (isset($payload['tracking_no']) && trim((string) $payload['tracking_no']) !== '') {
                $fresh->tracking_no = trim((string) $payload['tracking_no']);
            }
            if (isset($payload['notes']) && trim((string) $payload['notes']) !== '') {
                $fresh->notes = trim((string) $payload['notes']);
            }
            $fresh->save();

            if ($fresh->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($fresh->sales_order_id)->lockForUpdate()->first();
                if ($order !== null) {
                    $this->advanceOrder($order, 'out_for_delivery');
                }
            }

            $this->audit->record([
                'action' => 'sales.delivery_challan_dispatched',
                'entity_type' => 'delivery_challan',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'challan_no' => $fresh->challan_no,
                    'status' => 'dispatched',
                    'courier_name' => $fresh->courier_name,
                    'tracking_no' => $fresh->tracking_no,
                ],
            ]);

            return $fresh;
        });
    }

    /** Walk the validated delivery chain forward to $target when reachable. */
    protected function advanceOrder(SalesOrder $order, string $target): void
    {
        $hops = [
            'confirmed' => ['ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery'],
            'processing' => ['ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery'],
            'ready_to_ship' => ['picked_up', 'in_transit', 'out_for_delivery'],
            'picked_up' => ['in_transit', 'out_for_delivery'],
            'in_transit' => ['out_for_delivery'],
            'out_for_delivery' => [],
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
