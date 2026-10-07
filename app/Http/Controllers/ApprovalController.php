<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\ApprovalAuthority;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Http\Requests\ApprovalActionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Approval inbox (Rule 15 / section G). Every action runs through the
 * generic WorkflowEngine inside a row-locked transaction — this
 * controller never decides WHO may approve; ApprovalAuthority does,
 * server-side, per step (roles, users, delegation, self-approval block).
 */
class ApprovalController extends Controller
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected ApprovalAuthority $authority,
        protected TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $tab = (string) $request->query('tab', 'awaiting');

        $query = ApprovalRequest::query()->with(['definition', 'submitter'])->orderByDesc('id');

        $actorBranches = $actor->accessibleBranchIds();

        if ($actorBranches !== null) {
            $query->whereIn('branch_id', $actorBranches);
        }

        $status = (string) $request->query('status', 'pending');

        if ($tab === 'awaiting') {
            $query->where('status', 'pending');
        } elseif ($tab === 'submitted') {
            $query->where('submitted_by', $actor->id);
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(15)->withQueryString();

        return view('approvals.index', [
            'requests' => $requests,
            'tab' => $tab,
            'status' => $status,
            'canAct' => fn (ApprovalRequest $r) => $this->authority
                ->actionableSteps($r->loadMissing('steps', 'definition'), $actor, 'approve')
                ->isNotEmpty(),
        ]);
    }

    public function show(Request $request, ApprovalRequest $approval): View
    {
        abort_unless($approval->company_id === $request->user()->company_id, 404);

        $actor = $request->user();
        $approval->load(['definition.approvers.role', 'definition.approvers.user', 'steps.approverRole', 'steps.approverUser', 'steps.actedByUser', 'actions.user', 'submitter']);

        $pending = $approval->status === 'pending';

        return view('approvals.show', [
            'requestModel' => $approval,
            'canAct' => $pending && $this->authority->actionableSteps($approval, $actor, 'approve')->isNotEmpty(),
            'canReject' => $pending && $this->authority->actionableSteps($approval, $actor, 'reject')->isNotEmpty(),
            'canComment' => true,
            'canCancel' => $pending && ((int) $approval->submitted_by === (int) $actor->id
                || $actor->isSuperAdmin()
                || $this->authority->actionableSteps($approval, $actor, 'reject')->isNotEmpty()),
            'selfBlocked' => $pending && $this->authority->selfApprovalBlocked($approval, $actor),
        ]);
    }

    public function approve(ApprovalActionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->engine->approve($approval->id, $request->user(), $request->validated('comment'));

        return back()->with('status', 'Request approved.');
    }

    public function reject(ApprovalActionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->engine->reject($approval->id, $request->user(), (string) $request->validated('comment'));

        return back()->with('status', 'Request rejected.');
    }

    public function returnForCorrection(ApprovalActionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->engine->returnForCorrection($approval->id, $request->user(), (string) $request->validated('comment'));

        return back()->with('status', 'Request returned for correction.');
    }

    public function cancel(ApprovalActionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->engine->cancel($approval->id, $request->user(), $request->validated('comment'));

        return back()->with('status', 'Request cancelled.');
    }

    public function comment(ApprovalActionRequest $request, ApprovalRequest $approval): RedirectResponse
    {
        $this->engine->addComment($approval->id, $request->user(), (string) $request->validated('comment'));

        return back()->with('status', 'Comment added.');
    }
}
