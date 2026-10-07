<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (section C): clickjacking
 * protection, MIME sniffing off, referrer/permission policy, COOP and
 * optional HSTS. A CSP meta is added for non-API HTML responses by the
 * layout; this middleware sets the transport-level headers.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = config('erp.security.headers', []);

        $response->headers->set('X-Content-Type-Options', $headers['x_content_type_options'] ?? 'nosniff');
        $response->headers->set('X-Frame-Options', $headers['x_frame_options'] ?? 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', $headers['referrer_policy'] ?? 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', $headers['permissions_policy'] ?? 'camera=(), geolocation=(), microphone=()');
        $response->headers->set('Cross-Origin-Opener-Policy', $headers['cross_origin_opener_policy'] ?? 'same-origin');

        // Never leak the framework/version banner.
        $response->headers->remove('X-Powered-By');

        if (($headers['hsts'] ?? false) && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.($headers['hsts_max_age'] ?? 31536000).'; includeSubDomains',
            );
        }

        return $response;
    }
}
