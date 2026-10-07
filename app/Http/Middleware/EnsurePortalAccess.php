<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Portal;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal access gate (`portal:erp|technician|supplier`). The portal must
 * exist and be active, and the user must hold `portal.{code}.access`
 * (super admins hold every key). Sets the portal on TenantContext so
 * navigation filters by it. Supplier portal stays permission/config
 * driven and isolated (correction E).
 */
class EnsurePortalAccess
{
    public function __construct(
        protected TenantContext $context,
        protected PermissionCatalog $catalog,
    ) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $portal = Portal::query()->where('code', $code)->where('is_active', true)->first();

        abort_if($portal === null, 404, 'Portal not found.');

        $user = $request->user();
        abort_if($user === null, 401);

        if (! $this->catalog->allows($user, "portal.{$code}.access")) {
            abort(403, 'You do not have access to this portal.');
        }

        $this->context->setPortal($code);
        $request->session()->put('tenant.portal', $code);

        return $next($request);
    }
}
