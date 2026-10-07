<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-boot setup gate (§49): the setup screens are only reachable
 * while the instance is UNCONFIGURED. The moment a company exists the
 * whole flow is closed with 403 — setup can never re-run (Rule 1/2:
 * one company per instance; no path to create a second).
 */
class GuardSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Company::current() !== null) {
            abort(403, 'Setup has already been completed for this instance.');
        }

        return $next($request);
    }
}
