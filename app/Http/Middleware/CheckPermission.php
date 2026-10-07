<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;

/**
 * Database-driven permission gate (Rules 6/7): route middleware
 * `permission:resource.action`. Multiple comma-separated keys are ALL
 * required (AND). Failures return a polished 403 page or structured
 * JSON — and every denial by an authenticated user is written to the
 * tamper-evident audit trail with result=denied (Rule 16).
 */
class CheckPermission
{
    public function __construct(
        protected PermissionCatalog $catalog,
        protected AuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$keys): mixed
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        // Laravel spreads `permission:a,b` into separate arguments, so merge
        // the variadic keys back together and split on commas — every key is
        // required (AND).
        $required = array_filter(array_map('trim', explode(',', implode(',', $keys))));

        foreach ($required as $key) {
            if ($this->catalog->allows($user, $key)) {
                continue;
            }

            $this->audit->record([
                'action' => 'permission.denied',
                'entity_type' => 'permission',
                'entity_id' => null,
                'actor_id' => $user->id,
                'result' => 'denied',
                'reason' => "missing permission: {$key}",
                'after' => [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'permission' => $key,
                ],
            ]);

            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
