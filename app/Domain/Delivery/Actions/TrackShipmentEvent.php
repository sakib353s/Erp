<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\FailedDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TrackShipmentEvent (02-90): the single ingest path for courier
 * tracking observations — manual operator notes and adapter webhooks
 * share it. Every observation is recorded verbatim; shipment/order
 * statuses only move FORWARD along validated transitions (never from
 * pending/assigned, never backwards, never into terminal states), the
 * order creator gets a truthful in-app status-update notification, and
 * webhook redeliveries collapse through the idempotency key.
 */
class TrackShipmentEvent
{
    /** event_code => honest default description. */
    public const CODES = [
        'info_received' => 'Shipment information received',
        'picked_up' => 'Picked up by courier',
        'in_transit' => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'exception' => 'Delivery exception reported',
        'delivery_failed' => 'Delivery attempt failed',
    ];

    /** Codes that may move the shipment, forward-only. */
    protected const SHIPMENT_TARGETS = [
        'out_for_delivery' => Shipment::STATUS_OUT_FOR_DELIVERY,
        'delivered' => Shipment::STATUS_DELIVERED,
        'delivery_failed' => Shipment::STATUS_DELIVERY_FAILED,
    ];

    protected const SHIPMENT_RANK = [
        Shipment::STATUS_DISPATCHED => 1,
        Shipment::STATUS_OUT_FOR_DELIVERY => 2,
        Shipment::STATUS_DELIVERED => 3,
    ];

    /** Codes that may move the order (through OrderStateMachine only). */
    protected const ORDER_TARGETS = [
        'out_for_delivery' => 'out_for_delivery',
        'delivered' => 'delivered',
    ];

    /** States a tracking observation must never push an order into. */
    protected const BLOCKED_STATES = [
        'cancelled', 'returned', 'refunded',
        'return_requested', 'return_approved',
    ];

    public function __construct(
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
        protected NotificationCenter $notifications,
    ) {}

    /**
     * @param  array{event_code: string, description?: string|null, location?: string|null, occurred_at?: string|null, external_event_id?: string|null}  $payload
     * @param  array{source?: string, actor?: User|null, idempotency_key?: string|null}  $opts
     * @return array{outcome: string, event: TrackingEvent}
     */
    public function handle(Shipment $shipment, array $payload, array $opts = []): array
    {
        $code = (string) ($payload['event_code'] ?? '');
        if (! isset(self::CODES[$code])) {
            throw new RuntimeException(sprintf('Unknown tracking event code [%s].', $code));
        }

        $source = $opts['source'] ?? TrackingEvent::SOURCE_MANUAL;
        $actor = $opts['actor'] ?? null;
        $idempotencyKey = $opts['idempotency_key'] ?? null;

        return DB::transaction(function () use ($shipment, $payload, $code, $source, $actor, $idempotencyKey) {
            $fresh = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = TrackingEvent::query()
                    ->where('company_id', $fresh->company_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return ['outcome' => 'duplicate', 'event' => $existing];
                }
            }

            $description = trim((string) ($payload['description'] ?? ''));
            if ($description === '') {
                $description = self::CODES[$code];
            }

            try {
                $event = TrackingEvent::query()->create([
                    'company_id' => $fresh->company_id,
                    'shipment_id' => $fresh->id,
                    'courier_id' => $fresh->courier_id,
                    'event_code' => $code,
                    'description' => mb_substr($description, 0, 500),
                    'location' => $payload['location'] ?? null,
                    'occurred_at' => $payload['occurred_at'] ?? now(),
                    'source' => $source,
                    'external_event_id' => $payload['external_event_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'actor_id' => $actor?->id,
                ]);
            } catch (QueryException $e) {
                // A concurrent redelivery won the unique-key race.
                if ($idempotencyKey !== null && str_contains($e->getMessage(), 'UNIQUE')) {
                    $existing = TrackingEvent::query()
                        ->where('company_id', $fresh->company_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->firstOrFail();

                    return ['outcome' => 'duplicate', 'event' => $existing];
                }

                throw $e;
            }

            $shipmentChanged = $this->advanceShipment($fresh, $code);

            $order = null;
            $orderChanged = false;
            if ($fresh->sales_order_id !== null) {
                $order = SalesOrder::query()
                    ->whereKey($fresh->sales_order_id)
                    ->lockForUpdate()
                    ->first();

                if ($order !== null) {
                    $orderChanged = $this->advanceOrder($order, $code);
                }
            }

            if ($shipmentChanged && $code === 'delivery_failed') {
                $this->openFailedDelivery($fresh, $order, $event);
            }

            $this->audit->record([
                'company_id' => (int) $fresh->company_id,
                'action' => 'sales.tracking_event_ingested',
                'entity_type' => 'tracking_event',
                'entity_id' => $event->id,
                'actor_id' => $actor?->id,
                'actor_type' => $actor !== null ? 'user' : 'system',
                'after' => [
                    'shipment_id' => $fresh->id,
                    'order_no' => $order?->order_no,
                    'source' => $source,
                    'event_code' => $code,
                    'external_event_id' => $payload['external_event_id'] ?? null,
                    'shipment_status' => $fresh->status,
                    'shipment_status_changed' => $shipmentChanged,
                    'order_status' => $order?->status,
                    'order_status_changed' => $orderChanged,
                ],
            ]);

            $this->notify($event, $fresh, $order, $code);

            return ['outcome' => 'created', 'event' => $event];
        });
    }

    /** Forward-only shipment movement; pending/assigned never move. */
    protected function advanceShipment(Shipment $shipment, string $code): bool
    {
        if (! isset(self::SHIPMENT_TARGETS[$code])) {
            return false;
        }

        $target = self::SHIPMENT_TARGETS[$code];
        $current = $shipment->status;

        if ($current === $target) {
            return false;
        }

        if ($code === 'delivery_failed') {
            if (! in_array($current, [Shipment::STATUS_DISPATCHED, Shipment::STATUS_OUT_FOR_DELIVERY], true)) {
                return false;
            }
        } else {
            $currentRank = self::SHIPMENT_RANK[$current] ?? null;
            $targetRank = self::SHIPMENT_RANK[$target] ?? 0;

            if ($currentRank === null || $targetRank <= $currentRank) {
                return false;
            }
        }

        $shipment->status = $target;
        $shipment->save();

        return true;
    }

    /** Walk validated forward transitions only; terminal states blocked. */
    protected function advanceOrder(SalesOrder $order, string $code): bool
    {
        if (! isset(self::ORDER_TARGETS[$code])) {
            return false;
        }

        $target = self::ORDER_TARGETS[$code];
        if ($order->status === $target) {
            return false;
        }

        $path = $this->pathTo($order->status, $target);
        if ($path === null || $path === []) {
            return false;
        }

        foreach ($path as $step) {
            if ($this->stateMachine->canTransition($order->status, $step)) {
                $this->stateMachine->transition($order, $step);
            }
        }

        return $order->status === $target;
    }

    /**
     * Breadth-first shortest path through OrderStateMachine::TRANSITIONS,
     * refusing terminal/reject states as intermediate hops.
     *
     * @return list<string>|null
     */
    protected function pathTo(string $from, string $to): ?array
    {
        $queue = [[$from]];
        $visited = [$from => true];

        while ($queue !== []) {
            $path = array_shift($queue);
            $last = $path[count($path) - 1];

            foreach (OrderStateMachine::TRANSITIONS[$last] ?? [] as $next) {
                if ($next === $to) {
                    return [...$path, $next];
                }

                if (isset($visited[$next]) || in_array($next, self::BLOCKED_STATES, true)) {
                    continue;
                }

                $visited[$next] = true;
                $queue[] = [...$path, $next];
            }
        }

        return null;
    }

    /** Truthful in-app status update to the order creator (retry-safe). */
    /**
     * Open a failed-delivery record when the observation actually moved
     * the shipment into delivery_failed — attempt 1 for a first failure,
     * the next attempt after a retry.
     */
    protected function openFailedDelivery(Shipment $shipment, ?SalesOrder $order, TrackingEvent $event): void
    {
        $attempt = (int) FailedDelivery::query()
            ->where('shipment_id', $shipment->id)
            ->max('attempt_no');

        FailedDelivery::query()->create([
            'company_id' => $shipment->company_id,
            'branch_id' => $order?->branch_id,
            'shipment_id' => $shipment->id,
            'sales_order_id' => $shipment->sales_order_id,
            'courier_id' => $shipment->courier_id,
            'attempt_no' => $attempt + 1,
            'status' => FailedDelivery::STATUS_OPEN,
            'reason' => $event->description,
            'failed_at' => $event->occurred_at ?? now(),
        ]);
    }

    protected function notify(TrackingEvent $event, Shipment $shipment, ?SalesOrder $order, string $code): void
    {
        $recipientId = $order?->created_by ?? $shipment->assigned_by;
        if ($recipientId === null) {
            return;
        }

        $recipient = User::query()->find($recipientId);
        if ($recipient === null || ! $recipient->isActive()) {
            return;
        }

        $label = self::CODES[$code];

        $this->notifications->notify(
            $recipient,
            'sales.shipment_tracking',
            ($order?->order_no ?? 'Shipment #'.$shipment->id).' — '.$label,
            trim($event->description.($event->location !== null ? ' at '.$event->location : '')),
            [
                'action_url' => '/app/sales/shipments/'.$shipment->id.'/tracking',
                'dedupe_key' => 'shipment.track.'.$event->id,
                'data' => [
                    'shipment_id' => $shipment->id,
                    'tracking_event_id' => $event->id,
                    'event_code' => $code,
                    'order_no' => $order?->order_no,
                    'source' => $event->source,
                ],
            ],
        );
    }
}
