<?php

namespace App\Domain\Foundation\Policies;

use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

class RolePolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->catalog->allows($user, 'roles.view');
    }

    public function create(User $user): bool
    {
        return $this->catalog->allows($user, 'roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->catalog->allows($user, 'roles.update');
    }
}
