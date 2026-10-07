<?php

namespace App\Domain\Foundation\Listeners;

use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Events\RolePermissionsChanged;

/** Drops cached effective-permission maps for every affected user. */
class InvalidatePermissionCache
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function handle(RolePermissionsChanged $event): void
    {
        $userIds = $event->userIds;

        if ($event->roleId !== null) {
            $userIds = array_merge(
                $userIds,
                Role::query()->whereKey($event->roleId)->first()?->users()->pluck('users.id')->all() ?? [],
            );
        }

        foreach (array_unique($userIds) as $userId) {
            $this->catalog->invalidate(
                \App\Domain\Foundation\User::query()->find($userId)
            );
        }
    }
}
