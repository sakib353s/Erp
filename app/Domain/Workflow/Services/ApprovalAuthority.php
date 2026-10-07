<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Foundation\User;
use App\Domain\Workflow\ApprovalDelegation;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\ApprovalStep;
use Illuminate\Support\Collection;

/**
 * Approval authority resolution (Rule 15): who may act on which step —
 * role rules, direct user rules, branch-scope rules, active delegation
 * and the configured self-approval prohibition. Enforced by the engine
 * itself, independent of any UI.
 */
class ApprovalAuthority
{
    public function canAct(ApprovalRequest $request, ApprovalStep $step, User $actor, string $intent = 'approve'): bool
    {
        if ($request->status !== 'pending' || $step->status !== 'pending') {
            return false;
        }

        if ($intent === 'approve' && $this->selfApprovalBlocked($request, $actor)) {
            return false;
        }

        if (! $actor->hasBranchAccess((int) $request->branch_id)) {
            return false; // branch rule (Rule 5): approver must be in scope
        }

        if ($step->approver_user_id !== null) {
            if ((int) $step->approver_user_id === (int) $actor->id) {
                return true;
            }

            return $this->actingUnderDelegation($request, (int) $step->approver_user_id, $actor);
        }

        if ($step->approver_role_id !== null) {
            return $actor->roles()->whereKey($step->approver_role_id)->exists();
        }

        return false;
    }

    public function selfApprovalBlocked(ApprovalRequest $request, User $actor): bool
    {
        $blocked = $request->frozenBlocksSelfApproval();

        return $blocked && (int) $request->submitted_by === (int) $actor->id;
    }

    /** Steps of the current level (sequential) or any pending step (parallel) the actor may act on. */
    public function actionableSteps(ApprovalRequest $request, User $actor, string $intent = 'approve'): Collection
    {
        $steps = $request->steps
            ->filter(fn (ApprovalStep $step) => $step->status === 'pending');

        if (! $request->isParallelMode()) {
            $steps = $steps->filter(fn (ApprovalStep $step) => (int) $step->level === (int) $request->current_level);
        }

        return $steps
            ->filter(fn (ApprovalStep $step) => $this->canAct($request, $step, $actor, $intent))
            ->values();
    }

    /** Concrete users to notify for a pending step (approver + active delegates / role members in scope). */
    public function recipientsForStep(ApprovalRequest $request, ApprovalStep $step): Collection
    {
        if ($step->approver_user_id !== null) {
            $principal = User::query()->find($step->approver_user_id);

            $users = collect();

            if ($principal !== null) {
                $users->push($principal);

                $delegates = ApprovalDelegation::query()
                    ->where('delegator_user_id', $principal->id)
                    ->where('is_active', true)
                    ->where('starts_at', '<=', now())
                    ->where('ends_at', '>=', now())
                    ->where(fn ($q) => $q->whereNull('entity_type')->orWhere('entity_type', $request->entity_type))
                    ->with('delegate')
                    ->get()
                    ->map(fn ($d) => $d->delegate)
                    ->filter();

                $users = $users->merge($delegates);
            }

            return $users
                ->filter(fn (User $u) => $u->isActive() && $u->hasBranchAccess((int) $request->branch_id))
                ->unique('id')
                ->values();
        }

        if ($step->approver_role_id !== null) {
            return User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereKey($step->approver_role_id))
                ->get()
                ->filter(fn (User $u) => $u->hasBranchAccess((int) $request->branch_id))
                ->values();
        }

        return collect();
    }

    protected function actingUnderDelegation(ApprovalRequest $request, int $principalId, User $actor): bool
    {
        return ApprovalDelegation::query()
            ->where('delegator_user_id', $principalId)
            ->where('delegate_user_id', $actor->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->where(fn ($q) => $q->whereNull('entity_type')->orWhere('entity_type', $request->entity_type))
            ->exists();
    }
}
