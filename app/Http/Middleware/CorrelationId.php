<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every request (spec: correlation ids in
 * errors, logs and audit rows). Honours a well-formed incoming
 * X-Request-Id so traces can span proxies, otherwise generates a UUID.
 */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        $request->attributes->set('correlation_id', $id);

        Log::shareContext(['correlation_id' => $id]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
