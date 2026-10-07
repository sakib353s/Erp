<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\FeatureEntitlement;
use RuntimeException;

/**
 * Local feature entitlement checks (clarification C3): the platform
 * control plane mirrors entitlement rows into this database; menu and
 * authorization paths never call out to the platform at request time.
 */
class EntitlementService
{
    /** @var array<string, bool>|null */
    protected ?array $cache = null;

    public function has(string $key): bool
    {
        $all = $this->all();

        return $all[$key] ?? (bool) config('erp.features.default_enabled');
    }

    public function assert(string $key): void
    {
        if (! $this->has($key)) {
            throw new RuntimeException("Feature '{$key}' is not entitled for this instance.");
        }
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        foreach (config('erp.features.keys') as $key) {
            $this->cache[$key] = (bool) config('erp.features.default_enabled');
        }

        $companyId = app(TenantContext::class)->companyId();

        if ($companyId !== null) {
            foreach (FeatureEntitlement::query()->where('company_id', $companyId)->get() as $row) {
                $this->cache[$row->feature_key] = $row->currentlyEnabled();
            }
        }

        return $this->cache;
    }

    public function forget(): void
    {
        $this->cache = null;
    }
}
