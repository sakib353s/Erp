<?php

namespace App\Domain\Delivery\Couriers;

/**
 * Redx Courier adapter (02-92). Identity + adapter-declared webhook
 * vocabulary only — the four capabilities and every truthfulness rule
 * live in ProviderAdapter. Codes outside this map (and outside the canonical
 * set) are rejected with an honest error, never guessed; codes without
 * an exact canonical bucket map to the closest canonical state
 * (exception).
 */
class RedxAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return 'redx';
    }

    public function code(): string
    {
        return 'REDX';
    }

    public function label(): string
    {
        return 'Redx Courier';
    }

    public function eventMap(): array
    {
        return [
            'BOOKED' => 'info_received',
            'PICKUP_COMPLETE' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'HOLD' => 'exception',
            'RETURN_TO_ORIGIN' => 'exception',
        ];
    }
}
