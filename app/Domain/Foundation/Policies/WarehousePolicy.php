<?php

namespace App\Domain\Foundation\Policies;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;

class WarehousePolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'warehouses.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->catalog->allows($user, 'warehouses.view')
            && $user->hasBranchAccess($warehouse->branch_id);
    }

    public function create(User $user): bool
    {
        return $this->catalog->allows($user, 'warehouses.create');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->catalog->allows($user, 'warehouses.update')
            && $user->hasBranchAccess($warehouse->branch_id);
    }
}
