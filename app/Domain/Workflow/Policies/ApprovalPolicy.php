<?php

namespace App\Domain\Workflow\Policies;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Workflow\ApprovalRequest;

/**
 * Approval inbox permissions. Branch visibility is enforced separately
 * by the BranchScope global scope + ApprovalAuthority (Rules 5 & 15);
 * per-step eligibility is checked by the engine, never by the UI.
 */
class ApprovalPolicy
{
    public function __construct(protected PermissionCatalog $catalog) {}

    public function viewAny(User $user): bool
    {
        return $this->catalog->allows($user, 'approvals.view');
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $this->catalog->allows($user, 'approvals.view')
            && $user->hasBranchAccess((int) $request->branch_id);
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $this->catalog->allows($user, 'approvals.decide')
            && $user->hasBranchAccess((int) $request->branch_id);
    }

    public function comment(User $user, ApprovalRequest $request): bool
    {
        return $this->catalog->allows($user, 'approvals.comment')
            && $user->hasBranchAccess((int) $request->branch_id);
    }
}
