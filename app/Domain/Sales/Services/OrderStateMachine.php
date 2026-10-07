<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\SalesOrder;
use RuntimeException;

/**
 * OrderStateMachine (delivery lifecycle). Ad-hoc status writes elsewhere
 * remain; this is the validated transition map for delivery-stage moves.
 */
class OrderStateMachine
{
    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'ready_to_ship', 'cancelled', 'confirmed'],
        'processing' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['picked_up', 'out_for_delivery', 'cancelled'],
        'picked_up' => ['in_transit', 'out_for_delivery'],
        'in_transit' => ['out_for_delivery', 'delivered'],
        'out_for_delivery' => ['delivered'],
        'delivered' => ['completed', 'return_requested'],
        'completed' => ['return_requested'],
        // receive may skip the approval step (07-04 approval path is
        // PLANNED; the returns screen already accepts receive from a
        // plain request) — the machine records the real physical flow;
        // a refund settles the case even when goods were never received
        // (the returns screen also allows credit from a plain request).
        'return_requested' => ['return_approved', 'returned', 'refunded', 'cancelled'],
        'return_approved' => ['returned', 'refunded'],
        'returned' => ['refunded'],
        'refunded' => [],
        'cancelled' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assert(SalesOrder $order, string $to): void
    {
        if (! in_array($to, SalesOrder::STATUSES, true)) {
            throw new RuntimeException("Unknown order status [{$to}].");
        }

        if (! $this->canTransition($order->status, $to)) {
            throw new RuntimeException(sprintf(
                'Order %s cannot move from [%s] to [%s].',
                $order->order_no,
                $order->status,
                $to,
            ));
        }
    }

    /**
     * Apply transition if allowed; returns the (possibly unchanged) order.
     */
    public function transition(SalesOrder $order, string $to): SalesOrder
    {
        if ($order->status === $to) {
            return $order;
        }

        $this->assert($order, $to);
        $order->status = $to;
        $order->save();

        return $order;
    }
}
