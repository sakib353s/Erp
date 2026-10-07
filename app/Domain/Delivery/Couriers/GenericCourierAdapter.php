<?php

namespace App\Domain\Delivery\Couriers;

/**
 * Fallback adapter (02-92) for every courier that is not one of the
 * seven supported providers: same truthfulness rules, no provider
 * vocabulary (only canonical webhook event codes pass), no provider
 * identity in mapped responses.
 */
class GenericCourierAdapter extends ProviderAdapter
{
    public function slug(): ?string
    {
        return null;
    }

    public function code(): string
    {
        return '';
    }

    public function label(): string
    {
        return 'Courier';
    }

    public function eventMap(): array
    {
        return [];
    }
}
