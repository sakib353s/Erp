<?php

namespace App\Http\Middleware;

use App\Domain\Security\Services\SessionPolicyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configurable session policy (section C): enforces the idle timeout on
 * every request. An expired session is logged out, invalidated and
 * audited (security.session_expired), then redirected to login.
 */
class EnforceSessionPolicy
{
    public function __construct(protected SessionPolicyService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! $this->sessions->enforceIdle()) {
            $this->sessions->expireCurrentSession();

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => [
                        'code' => 'http_401',
                        'message' => 'Your session expired due to inactivity. Please sign in again.',
                        'correlation_id' => $request->attributes->get('correlation_id'),
                    ],
                ], 401);
            }

            return redirect()->guest(route('login'))
                ->withErrors(['email' => 'Your session expired due to inactivity. Please sign in again.']);
        }

        return $next($request);
    }
}
