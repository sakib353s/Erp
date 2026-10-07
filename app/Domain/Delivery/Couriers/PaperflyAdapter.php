<?php

namespace App\Domain\Delivery\Couriers;

/**
 * Paperfly Courier adapter (02-92). Identity + adapter-declared webhook
 * vocabulary only — the four capabilities and every truthfulness rule
 * live in ProviderAdapter. Codes outside this map (and outside the canonical
 * set) are rejected with an honest error, never guessed; codes without
 * an exact canonical bucket map to the closest canonical state
 * (exception).
 */
class PaperflyAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return 'paperfly';
    }

    public function code(): string
    {
        return 'PAPERFLY';
    }

    public function label(): string
    {
        return 'Paperfly Courier';
    }

    public function eventMap(): array
    {
        return [
            'CREATED' => 'info_received',
            'PICKED' => 'picked_up',
            'MOVING' => 'in_transit',
            'OUT_FOR_DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'REJECTED' => 'exception',
        ];
    }
}
