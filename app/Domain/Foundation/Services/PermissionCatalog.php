<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Permission;
use App\Domain\Foundation\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Database-driven permission resolution (Rule 6).
 *
 * Effective keys = role permissions ∪ direct grants − direct denies.
 * Super admins hold every key. Results are cached per user and
 * invalidated on any role/permission assignment change — never
 * hard-coded in PHP conditionals (correction G).
 */
class PermissionCatalog
{
    /** Grant a key ending in ".*" matches any key under that prefix. */
    public function allows(?User $user, string $key): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $keys = $this->keysFor($user);

        return isset($keys[$key]) || $this->matchesWildcard($keys, $key);
    }

    /** @return array<string, bool> map of granted keys */
    public function keysFor(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return ['*' => true]; // wildcard: everything
        }

        return Cache::remember(
            "permissions.user.{$user->id}",
            now()->addMinutes(10),
            fn () => $this->resolve($user),
        );
    }

    public function invalidate(User $user): void
    {
        Cache::forget("permissions.user.{$user->id}");
    }

    public function invalidateAll(): void
    {
        foreach (User::query()->pluck('id') as $id) {
            Cache::forget("permissions.user.{$id}");
        }
    }

    protected function resolve(User $user): array
    {
        $granted = DB::table('permissions')
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->pluck('permissions.key')
            ->flip()
            ->all();

        foreach (DB::table('user_permission')->where('user_id', $user->id)->get() as $row) {
            $key = Permission::query()->whereKey($row->permission_id)->value('key');

            if ($key === null) {
                continue;
            }

            if ($row->effect === 'deny') {
                unset($granted[$key]);
                $granted['deny:'.$key] = true; // explicit deny marker (wins over wildcards too)
            } else {
                $granted[$key] = true;
            }
        }

        // Strip deny markers that did not block anything above.
        foreach (array_keys($granted) as $key) {
            if (str_starts_with((string) $key, 'deny:')) {
                unset($granted[$key]);
            }
        }

        return $granted;
    }

    protected function matchesWildcard(array $keys, string $key): bool
    {
        if (isset($keys['*'])) {
            return true;
        }

        foreach ($keys as $granted => $_) {
            if (str_ends_with((string) $granted, '.*')
                && str_starts_with($key, substr((string) $granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
