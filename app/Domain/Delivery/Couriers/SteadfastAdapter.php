<?php

namespace App\Domain\Delivery\Couriers;

/**
 * Steadfast Courier adapter (02-92). Identity + adapter-declared webhook
 * vocabulary only — the four capabilities and every truthfulness rule
 * live in ProviderAdapter. Codes outside this map (and outside the canonical
 * set) are rejected with an honest error, never guessed; codes without
 * an exact canonical bucket map to the closest canonical state
 * (exception).
 */
class SteadfastAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return 'steadfast';
    }

    public function code(): string
    {
        return 'STEADFAST';
    }

    public function label(): string
    {
        return 'Steadfast Courier';
    }

    public function eventMap(): array
    {
        return [
            'ORDER_RECEIVED' => 'info_received',
            'COLLECTED' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'ATTEMPT_FAILED' => 'delivery_failed',
        ];
    }
}
