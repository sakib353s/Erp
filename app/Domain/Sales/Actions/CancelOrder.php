<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderApprovalGate;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CancelOrder: any non-terminal status → cancelled; releases stock reservations.
 *
 * Workflow (02-05): when a workflow definition matches sales_order/cancel,
 * the cancellation is submitted for approval and the order keeps its
 * current status and reservations until the request is approved.
 */
class CancelOrder
{
    public function __construct(
        protected ReservationService $reservations,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderApprovalGate $approvalGate,
    ) {}

    public function handle(SalesOrder $order, ?string $reason, Request $request): SalesOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (in_array($order->status, ['cancelled', 'completed', 'delivered'], true)) {
            throw new RuntimeException("Order {$order->order_no} cannot be cancelled from status [{$order->status}].");
        }

        return DB::transaction(function () use ($order, $reason, $companyId, $request) {
            $fresh = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'cancelled'
                && $this->approvalGate->pass($fresh, 'cancel', $request) === OrderApprovalGate::PENDING) {
                return $fresh->load('lines');
            }

            if ($fresh->stock_reserved) {
                $this->reservations->release($companyId, 'sales_order', $fresh->id);
            }

            $before = ['status' => $fresh->status];
            $fresh->status = 'cancelled';
            $fresh->stock_reserved = false;
            $fresh->cancel_reason = $reason;
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.order_cancelled',
                'entity_type' => 'sales_order',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'before' => $before,
                'after' => [
                    'order_no' => $fresh->order_no,
                    'status' => 'cancelled',
                    'cancel_reason' => $reason,
                ],
            ]);

            return $fresh;
        });
    }
}
