<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rebuilds the trusted server-side tenant context on EVERY request
 * (Rules 4/5, decision D6):
 *
 *  - company from the singleton row (never from client input),
 *  - branch from session, re-validated against the user's assignments;
 *    an out-of-scope stored branch silently falls back to the default,
 *  - warehouse likewise constrained to the current branch.
 *
 * The client can request a branch only through ContextController, which
 * re-checks the same server-side rules before storing it.
 */
class SetTenantContext
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = \App\Domain\Foundation\Company::current();

        if ($company === null) {
            abort(500, 'Instance is not initialised.');
        }

        $this->context->setCompany($company);

        /** @var User|null $user */
        $user = Auth::user();
        $this->context->setUser($user);

        $branch = $this->resolveBranch($request, $user);
        $this->context->setBranch($branch);

        $warehouse = $this->resolveWarehouse($request, $branch);
        $this->context->setWarehouse($warehouse);

        $portal = (string) $request->session()->get('tenant.portal', 'erp');
        $this->context->setPortal($portal);

        return $next($request);
    }

    protected function resolveBranch(Request $request, ?User $user): ?Branch
    {
        if ($user === null) {
            return null;
        }

        $storedId = (int) $request->session()->get('tenant.branch_id', 0);

        if ($storedId > 0 && $user->hasBranchAccess($storedId)) {
            $branch = Branch::query()->find($storedId);

            if ($branch !== null && $branch->is_active) {
                return $branch;
            }
        }

        // Default: user's pinned branch → first assigned/any branch.
        $defaultId = $user->default_branch_id;

        if ($defaultId !== null && $user->hasBranchAccess((int) $defaultId)) {
            $branch = Branch::query()->find($defaultId);

            if ($branch !== null && $branch->is_active) {
                $request->session()->put('tenant.branch_id', $branch->id);

                return $branch;
            }
        }

        $ids = $user->accessibleBranchIds();

        $query = Branch::query()->where('is_active', true);

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $branch = $query->orderBy('id')->first();

        if ($branch !== null) {
            $request->session()->put('tenant.branch_id', $branch->id);
        }

        return $branch;
    }

    protected function resolveWarehouse(Request $request, ?Branch $branch): ?Warehouse
    {
        if ($branch === null) {
            return null;
        }

        $storedId = (int) $request->session()->get('tenant.warehouse_id', 0);

        if ($storedId > 0) {
            $warehouse = Warehouse::query()
                ->whereKey($storedId)
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->first();

            if ($warehouse !== null) {
                return $warehouse;
            }
        }

        $warehouse = Warehouse::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($warehouse !== null) {
            $request->session()->put('tenant.warehouse_id', $warehouse->id);
        } else {
            $request->session()->forget('tenant.warehouse_id');
        }

        return $warehouse;
    }
}
