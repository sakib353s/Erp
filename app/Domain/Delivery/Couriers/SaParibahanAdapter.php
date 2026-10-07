<?php

namespace App\Domain\Delivery\Couriers;

/**
 * SA Paribahan adapter (02-92). Identity + adapter-declared webhook
 * vocabulary only — the four capabilities and every truthfulness rule
 * live in ProviderAdapter. Codes outside this map (and outside the canonical
 * set) are rejected with an honest error, never guessed; codes without
 * an exact canonical bucket map to the closest canonical state
 * (exception).
 */
class SaParibahanAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return 'sa-paribahan';
    }

    public function code(): string
    {
        return 'SAPARIBAHAN';
    }

    public function label(): string
    {
        return 'SA Paribahan';
    }

    public function eventMap(): array
    {
        return [
            'RECEIVED' => 'info_received',
            'PICKED_UP' => 'picked_up',
            'IN_TRANSIT' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'HELD' => 'exception',
        ];
    }
}
