<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\SalesOrder;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Http\Request;

/**
 * Generic workflow gate for sales-order state changes (02-04/02-05).
 *
 * Nothing here is sales-specific approval routing: the decision comes
 * entirely from workflow_definitions rows (entity_type=sales_order,
 * action=confirm|cancel) evaluated by WorkflowEngine. Outcomes:
 *
 *  - an already pending request            → PENDING (caller applies nothing)
 *  - a previously approved request          → PROCEED (apply the effect now)
 *  - a matching active definition           → submitted, PENDING
 *  - no matching definition (bypass)        → PROCEED
 */
class OrderApprovalGate
{
    public const PROCEED = 'proceed';

    public const PENDING = 'pending_approval';

    public function __construct(protected WorkflowEngine $workflow) {}

    public function pass(SalesOrder $order, string $action, Request $request): string
    {
        $scope = fn () => ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $order->id)
            ->where('action', $action);

        if ($scope()->where('status', 'pending')->lockForUpdate()->exists()) {
            return self::PENDING;
        }

        if ($scope()->where('status', 'approved')->exists()) {
            return self::PROCEED;
        }

        $approval = $this->workflow->submit([
            'entity_type' => 'sales_order',
            'entity_id' => $order->id,
            'action' => $action,
            'subject' => $order->order_no,
            'branch_id' => $order->branch_id ?? $request->user()->default_branch_id,
            'submitted_by' => $request->user(),
            'snapshot' => [
                'order_no' => $order->order_no,
                'status' => $order->status,
                'grand_total' => (float) $order->grand_total,
                'customer_id' => $order->customer_id,
            ],
            'amount' => (float) $order->grand_total,
            'currency' => $order->currency ?? 'BDT',
            'reference_no' => $order->order_no,
            'context' => [
                'amount' => (float) $order->grand_total,
                'branch_id' => $order->branch_id,
                'currency' => $order->currency ?? 'BDT',
                'entity_type' => 'sales_order',
                'status' => $order->status,
            ],
        ]);

        return $approval !== null ? self::PENDING : self::PROCEED;
    }
}
