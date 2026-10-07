<?php

namespace App\Domain\Foundation\Policies;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

class BranchPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->catalog->allows($user, 'branches.view')
            && $user->hasBranchAccess($branch->id);
    }

    public function create(User $user): bool
    {
        return $this->catalog->allows($user, 'branches.create');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->catalog->allows($user, 'branches.update')
            && $user->hasBranchAccess($branch->id);
    }
}
