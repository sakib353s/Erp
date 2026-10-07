<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\CourierPort;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BulkOrderAction (02-04/02-05/02-06): bulk confirm, bulk cancel and
 * bulk courier assignment.
 *
 * Every order is processed in its own transaction and its own try/catch so
 * one failing order never rolls back the others — the caller receives a
 * per-order outcome list (partial failure reporting). Workflow routing is
 * inherited from ConfirmOrder/CancelOrder via OrderApprovalGate, so a bulk
 * confirm either confirms (no definition / already approved) or parks the
 * order as pending_approval. Courier assignment routes every order through
 * CourierPort, which never fabricates a dispatch for a courier that is not
 * configured — those shipments stay in `pending_dispatch` with no external
 * reference. A bulk action audit row is written per run.
 */
class BulkOrderAction
{
    public const OUTCOME_CONFIRMED = 'confirmed';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_PENDING = 'pending_approval';

    public const OUTCOME_ASSIGNED = 'assigned';

    public const OUTCOME_FAILED = 'failed';

    /** @var array<int, string> */
    public const ACTIONS = ['confirm', 'cancel', 'assign_courier'];

    public const MAX_PER_RUN = 100;

    public function __construct(
        protected ConfirmOrder $confirmOrder,
        protected CancelOrder $cancelOrder,
        protected CourierPort $courierPort,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array<int, int|string>  $orderIds
     * @param  array{reason?: string|null}  $payload
     * @return array{
     *   action: string,
     *   requested: int,
     *   counts: array<string, int>,
     *   results: array<int, array{order_id: int, order_no: ?string, outcome: string, message: string}>
     * }
     */
    public function handle(string $action, array $orderIds, array $payload, Request $request): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException("Unsupported bulk order action [{$action}].");
        }

        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $ids = array_values(array_unique(array_map('intval', $orderIds)));

        if ($ids === []) {
            throw new RuntimeException('Select at least one order.');
        }

        if (count($ids) > self::MAX_PER_RUN) {
            throw new RuntimeException('A bulk action accepts at most '.self::MAX_PER_RUN.' orders per run.');
        }

        $results = [];
        $counts = [
            self::OUTCOME_CONFIRMED => 0,
            self::OUTCOME_CANCELLED => 0,
            self::OUTCOME_PENDING => 0,
            self::OUTCOME_ASSIGNED => 0,
            self::OUTCOME_FAILED => 0,
        ];

        foreach ($ids as $id) {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->find($id);

            if ($order === null) {
                $counts[self::OUTCOME_FAILED]++;
                $results[] = [
                    'order_id' => $id,
                    'order_no' => null,
                    'outcome' => self::OUTCOME_FAILED,
                    'message' => 'Order not found.',
                ];

                continue;
            }

            try {
                if ($action === 'confirm') {
                    [$outcome, $message] = $this->runConfirm($order, $request);
                } elseif ($action === 'cancel') {
                    [$outcome, $message] = $this->runCancel($order, (string) ($payload['reason'] ?? ''), $request);
                } else {
                    [$outcome, $message] = $this->runAssign($order, $payload, $request);
                }
            } catch (\Throwable $e) {
                $outcome = self::OUTCOME_FAILED;
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Unexpected failure.';
            }

            $counts[$outcome]++;
            $results[] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'outcome' => $outcome,
                'message' => $message,
            ];
        }

        $this->audit->record([
            'action' => "sales.order_bulk_{$action}",
            'entity_type' => 'sales_order',
            'entity_id' => $results[0]['order_id'],
            'actor_id' => $request->user()->id,
            'after' => [
                'action' => $action,
                'requested' => count($ids),
                'confirmed' => $counts[self::OUTCOME_CONFIRMED],
                'cancelled' => $counts[self::OUTCOME_CANCELLED],
                'pending_approval' => $counts[self::OUTCOME_PENDING],
                'assigned' => $counts[self::OUTCOME_ASSIGNED],
                'failed' => $counts[self::OUTCOME_FAILED],
                'courier_id' => isset($payload['courier_id']) ? (int) $payload['courier_id'] : null,
                'order_ids' => $ids,
            ],
            'reason' => $payload['reason'] ?? null,
        ]);

        return [
            'action' => $action,
            'requested' => count($ids),
            'counts' => $counts,
            'results' => $results,
        ];
    }

    /** @return array{0: string, 1: string} */
    protected function runConfirm(SalesOrder $order, Request $request): array
    {
        $fresh = $this->confirmOrder->handle($order, $request);

        if ($fresh->status === 'confirmed') {
            return [self::OUTCOME_CONFIRMED, 'Confirmed; stock reserved.'];
        }

        return [self::OUTCOME_PENDING, 'Submitted for approval.'];
    }

    /** @return array{0: string, 1: string} */
    protected function runCancel(SalesOrder $order, string $reason, Request $request): array
    {
        $fresh = $this->cancelOrder->handle($order, $reason !== '' ? $reason : null, $request);

        if ($fresh->status === 'cancelled') {
            return [self::OUTCOME_CANCELLED, 'Cancelled; reservations released.'];
        }

        return [self::OUTCOME_PENDING, 'Cancellation submitted for approval.'];
    }

    /**
     * @param  array{courier_id?: int|string|null, rider_name?: string|null}  $payload
     * @return array{0: string, 1: string}
     */
    protected function runAssign(SalesOrder $order, array $payload, Request $request): array
    {
        if ($order->status === 'cancelled') {
            return [self::OUTCOME_FAILED, 'Order is cancelled.'];
        }

        $courier = Courier::query()
            ->where('company_id', $order->company_id)
            ->find((int) ($payload['courier_id'] ?? 0));

        if ($courier === null || ! $courier->is_active) {
            return [self::OUTCOME_FAILED, 'Courier not found or inactive.'];
        }

        $dispatch = $this->courierPort->assign($courier, $order);

        $shipment = Shipment::query()->create([
            'company_id' => $order->company_id,
            'sales_order_id' => $order->id,
            'courier_id' => $courier->id,
            'status' => $dispatch['dispatched']
                ? Shipment::STATUS_ASSIGNED
                : Shipment::STATUS_PENDING_DISPATCH,
            'external_ref' => $dispatch['external_ref'],
            'dispatched_at' => $dispatch['dispatched'] ? now() : null,
            'assigned_by' => $request->user()?->id,
        ]);

        $rider = trim((string) ($payload['rider_name'] ?? ''));

        if ($rider !== '') {
            RiderAssignment::query()->create([
                'company_id' => $order->company_id,
                'shipment_id' => $shipment->id,
                'rider_name' => $rider,
                'assigned_by' => $request->user()?->id,
            ]);
        }

        if ($dispatch['dispatched']) {
            return [
                self::OUTCOME_ASSIGNED,
                "Assigned to {$courier->name}; dispatch ref {$dispatch['external_ref']}.",
            ];
        }

        return [
            self::OUTCOME_ASSIGNED,
            "Assigned locally to {$courier->name} — courier not configured, no dispatch created.",
        ];
    }
}
