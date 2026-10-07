<?php

namespace App\Domain\Delivery;

use App\Domain\Delivery\Couriers\ProviderRegistry;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;

/**
 * Courier integration adapter (02-06, dispatching since 02-92). The
 * truthfulness contract is unchanged: a courier that is not configured
 * (or whose integration is disabled) never produces a dispatch or an
 * external reference — the assignment stays local (`pending_dispatch`)
 * and the caller is told so in plain words. Since 02-92 the decision is
 * delegated to the courier's provider adapter (one of the seven brands,
 * or the generic fallback) so brand rows also gain the provider slug and
 * tracking URL in the mapped response.
 */
class CourierPort
{
    public function __construct(
        protected ProviderRegistry $providers,
    ) {}

    /**
     * @return array{dispatched: bool, external_ref: ?string, provider: ?string, tracking_url: ?string}
     */
    public function assign(Courier $courier, SalesOrder $order): array
    {
        return $this->providers->adapterFor($courier)->assign($courier, $order);
    }
}
