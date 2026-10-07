<?php

namespace App\Domain\Foundation\Events;

/**
 * Fired synchronously whenever a role's permissions or a user's roles are
 * modified — triggers permission-cache invalidation so the change takes
 * effect on the very next request.
 */
class RolePermissionsChanged
{
    /** @param array<int, int> $userIds */
    public function __construct(
        public readonly ?int $roleId = null,
        public readonly array $userIds = [],
    ) {}
}
