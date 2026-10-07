<?php

namespace App\Domain\Foundation\Policies;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

class MenuItemPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'menus.view');
    }

    public function view(User $user, MenuItem $menuItem): bool
    {
        return $this->catalog->allows($user, 'menus.view');
    }

    public function update(User $user, MenuItem $menuItem): bool
    {
        return $this->catalog->allows($user, 'menus.manage');
    }
}
