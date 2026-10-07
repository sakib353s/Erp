<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Events\WorkflowSubmitted;
use App\Domain\Workflow\Services\ApprovalAuthority;

/**
 * Notifies every concrete approver of the currently actionable pending
 * steps (in-app). Recipients come from the database (role/user rules +
 * active delegation), filtered by branch scope. Dedupe keys make the
 * fan-out idempotent across outbox retries (Rule I).
 */
class NotifyPendingApprovers
{
    public function __construct(
        protected NotificationCenter $notifications,
        protected ApprovalAuthority $authority,
    ) {}

    public function handle(WorkflowSubmitted $event): void
    {
        $request = ApprovalRequest::query()
            ->with(['steps', 'definition'])
            ->find($event->requestId);

        if ($request === null || $request->status !== 'pending') {
            return;
        }

        $steps = $request->steps->filter(fn ($step) => $step->status === 'pending');

        if ($request->definition !== null && ! $request->definition->parallelMode()) {
            $steps = $steps->filter(fn ($step) => (int) $step->level === (int) $request->current_level);
        }

        foreach ($steps as $step) {
            foreach ($this->authority->recipientsForStep($request, $step) as $user) {
                $this->notifications->notify(
                    $user,
                    'approval.pending',
                    'Approval required: '.$request->subject,
                    "Level {$step->level} approval is waiting for your action.",
                    [
                        'action_url' => "/app/approvals/{$request->id}",
                        'priority' => 'high',
                        'data' => [
                            'request_id' => $request->id,
                            'level' => $step->level,
                            'entity_type' => $request->entity_type,
                        ],
                        'dedupe_key' => "approval.{$request->id}.step.{$step->level}.user.{$user->id}",
                    ],
                );
            }
        }
    }
}
