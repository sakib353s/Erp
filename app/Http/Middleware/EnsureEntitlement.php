<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Services\EntitlementService;
use App\Domain\Settings\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature-entitlement gate (`feature:pos` etc.). Entitlement rows are
 * mirrored into this database by the platform control plane (C3) — the
 * check never calls out to the platform (Rule 18: integrations must not
 * block core ERP).
 */
class EnsureEntitlement
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected SettingService $settings,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        if (! $this->entitlements->has($featureKey)) {
            if ($request->expectsJson()) {
                abort(403, "The feature '{$featureKey}' is not enabled for this instance.");
            }

            abort(403, 'This feature is not enabled for your subscription.');
        }

        return $next($request);
    }
}
