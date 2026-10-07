<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\ShipmentLine;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BulkCreateShipment (02-89): one shipment per selected order carrying
 * that order's full remaining ordered lines. Each order runs in its
 * own transaction — a failure (cancelled, no lines, no remaining
 * quantity) never rolls back the others, exactly like BulkOrderAction.
 */
class BulkCreateShipment
{
    public const MAX_PER_RUN = 100;

    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        protected CreateShipment $createShipment,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array<int, int>  $orderIds
     * @param  array{courier_id: int|string}  $payload
     * @return array{requested: int, counts: array<string, int>, results: array<int, array{outcome: string, message: string}>}
     */
    public function handle(array $orderIds, array $payload, Request $request): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if (count($orderIds) > self::MAX_PER_RUN) {
            throw new RuntimeException(sprintf(
                'Bulk shipment is limited to %d orders per run.',
                self::MAX_PER_RUN,
            ));
        }

        $counts = [self::OUTCOME_CREATED => 0, self::OUTCOME_FAILED => 0];
        $results = [];

        foreach ($orderIds as $orderId) {
            $results[$orderId] = $this->shipOne($orderId, $payload, $request, $counts);
        }

        $this->audit->record([
            'action' => 'sales.shipment_bulk_created',
            'entity_type' => 'shipment',
            'entity_id' => null,
            'actor_id' => $request->user()?->id,
            'after' => [
                'requested' => count($orderIds),
                'counts' => $counts,
                'order_ids' => array_values($orderIds),
            ],
        ]);

        return [
            'requested' => count($orderIds),
            'counts' => $counts,
            'results' => $results,
        ];
    }

    /**
     * @param  array{courier_id: int|string}  $payload
     * @param  array<string, int>  $counts
     * @return array{outcome: string, message: string}
     */
    protected function shipOne(int $orderId, array $payload, Request $request, array &$counts): array
    {
        try {
            $order = SalesOrder::query()->find($orderId);

            if ($order === null) {
                $counts[self::OUTCOME_FAILED]++;

                return ['outcome' => self::OUTCOME_FAILED, 'message' => 'Order not found.'];
            }

            $orderLines = $order->lines;
            if ($orderLines->isEmpty()) {
                $counts[self::OUTCOME_FAILED]++;

                return ['outcome' => self::OUTCOME_FAILED, 'message' => 'Order has no lines to ship.'];
            }

            $shipped = ShipmentLine::query()
                ->where('company_id', $order->company_id)
                ->whereIn('shipment_id', $order->shipments()->select('id'))
                ->get()
                ->groupBy(fn ($line) => (int) $line->product_id)
                ->map(fn ($group) => $group->sum(fn ($line) => (float) $line->qty));

            $lines = [];
            foreach ($orderLines as $line) {
                $remaining = (float) $line->qty - (float) $shipped->get((int) $line->product_id, 0);
                if ($remaining > 1e-9) {
                    $lines[] = ['product_id' => $line->product_id, 'qty' => $remaining];
                }
            }

            if ($lines === []) {
                $counts[self::OUTCOME_FAILED]++;

                return ['outcome' => self::OUTCOME_FAILED, 'message' => 'Nothing left to ship for this order.'];
            }

            $this->createShipment->handle(
                $order,
                ['courier_id' => $payload['courier_id'], 'lines' => $lines],
                $request,
            );

            $counts[self::OUTCOME_CREATED]++;

            return ['outcome' => self::OUTCOME_CREATED, 'message' => sprintf('Shipment created for %s.', $order->order_no)];
        } catch (RuntimeException $e) {
            $counts[self::OUTCOME_FAILED]++;

            return ['outcome' => self::OUTCOME_FAILED, 'message' => $e->getMessage()];
        }
    }
}
