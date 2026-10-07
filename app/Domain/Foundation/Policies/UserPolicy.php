<?php

namespace App\Domain\Foundation\Policies;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;

/**
 * User administration. Branch rule (Rule 5): a branch-scoped admin can
 * only manage users whose assignments fall inside their own accessible
 * branches; managing all-branch users is reserved for super admins.
 */
class UserPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'users.view');
    }

    public function view(User $user, User $subject): bool
    {
        return $this->catalog->allows($user, 'users.view')
            && $this->canManage($user, $subject);
    }

    public function create(User $user): bool
    {
        return $this->catalog->allows($user, 'users.create');
    }

    public function update(User $user, User $subject): bool
    {
        return $this->catalog->allows($user, 'users.update')
            && $this->canManage($user, $subject);
    }

    protected function canManage(User $actor, User $subject): bool
    {
        if ($actor->id === $subject->id) {
            return true; // own record (password/profile self-service)
        }

        if ($subject->branch_scope === 'all') {
            return $actor->isSuperAdmin();
        }

        $actorBranches = $actor->accessibleBranchIds();

        if ($actorBranches === null) {
            return true; // unrestricted within the company
        }

        $subjectBranches = $subject->accessibleBranchIds();

        if ($subjectBranches === null) {
            return false; // cannot manage an all-branch user
        }

        return array_intersect($actorBranches, $subjectBranches) !== [];
    }
}
