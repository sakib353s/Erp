<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every operational page requires an initialised instance (company row
 * exists). Before first boot the user is sent to the explicit setup flow.
 */
class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Company::current() === null) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
