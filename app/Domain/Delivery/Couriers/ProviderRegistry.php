<?php

namespace App\Domain\Delivery\Couriers;

use App\Domain\Masters\Courier;
use App\Domain\Sales\Services\PricingService;

/**
 * Registry of the seven supported courier providers (02-92). Slugs drive
 * the per-provider settings routes and menu leaves; courier codes are
 * matched case-insensitively so a row created through the generic
 * Courier Partners screen still picks up its provider adapter. Anything
 * else falls back to GenericCourierAdapter — same truthfulness rules,
 * no provider vocabulary.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<ProviderAdapter>> */
    public const PROVIDERS = [
        'pathao' => PathaoAdapter::class,
        'redx' => RedxAdapter::class,
        'steadfast' => SteadfastAdapter::class,
        'paperfly' => PaperflyAdapter::class,
        'e-courier' => ECourierAdapter::class,
        'sundarban' => SundarbanAdapter::class,
        'sa-paribahan' => SaParibahanAdapter::class,
    ];

    /** @var array<class-string<CourierAdapter>, CourierAdapter> */
    protected array $instances = [];

    public function resolve(?string $slug): ?CourierAdapter
    {
        $class = self::PROVIDERS[$slug ?? ''] ?? null;

        return $class === null ? null : $this->make($class);
    }

    /**
     * Adapter for a courier row (by code) or a provider slug; unknown
     * slugs/codes fall back to the generic adapter.
     */
    public function adapterFor(Courier|string $key): CourierAdapter
    {
        if (is_string($key)) {
            return $this->resolve($key) ?? $this->make(GenericCourierAdapter::class);
        }

        $code = strtoupper((string) $key->code);

        foreach (self::PROVIDERS as $class) {
            $adapter = $this->make($class);
            if ($adapter->code() === $code) {
                return $adapter;
            }
        }

        return $this->make(GenericCourierAdapter::class);
    }

    /** @param  class-string<CourierAdapter>  $class */
    protected function make(string $class): CourierAdapter
    {
        return $this->instances[$class] ??= new $class(app(PricingService::class));
    }
}
