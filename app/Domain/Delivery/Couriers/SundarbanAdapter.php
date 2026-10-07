<?php

namespace App\Domain\Delivery\Couriers;

/**
 * Sundarban Courier adapter (02-92). Identity + adapter-declared webhook
 * vocabulary only — the four capabilities and every truthfulness rule
 * live in ProviderAdapter. Codes outside this map (and outside the canonical
 * set) are rejected with an honest error, never guessed; codes without
 * an exact canonical bucket map to the closest canonical state
 * (exception).
 */
class SundarbanAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return 'sundarban';
    }

    public function code(): string
    {
        return 'SUNDARBAN';
    }

    public function label(): string
    {
        return 'Sundarban Courier';
    }

    public function eventMap(): array
    {
        return [
            'BOOKED' => 'info_received',
            'DISPATCHED' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'DAMAGED' => 'exception',
        ];
    }
}
