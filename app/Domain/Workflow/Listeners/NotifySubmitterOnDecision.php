<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowCancelled;
use App\Domain\Workflow\Events\WorkflowEvent;
use App\Domain\Workflow\Events\WorkflowRejected;
use App\Domain\Workflow\Events\WorkflowReturned;
use App\Domain\Workflow\Events\WorkflowEscalated;
use App\Domain\Workflow\WorkflowDefinition;

/**
 * Tells the requester what happened to their submission, and (on
 * escalation) warns the configured escalation role. Outbox-driven, so a
 * failed notification never rolls back the decision itself — it retries.
 */
class NotifySubmitterOnDecision
{
    public function __construct(protected NotificationCenter $notifications) {}

    public function handle(WorkflowEvent|WorkflowEscalated $event): void
    {
        if ($event instanceof WorkflowEscalated) {
            $this->notifyEscalation($event);

            return;
        }

        $label = match (true) {
            $event instanceof WorkflowApproved => 'approved',
            $event instanceof WorkflowRejected => 'rejected',
            $event instanceof WorkflowReturned => 'returned for correction',
            $event instanceof WorkflowCancelled => 'cancelled',
            default => 'updated',
        };

        $submitter = User::query()->find($event->submittedBy);

        if ($submitter === null || ! $submitter->isActive()) {
            return;
        }

        $this->notifications->notify(
            $submitter,
            'approval.decided',
            "{$event->subject} — {$label}",
            $event->comment,
            [
                'action_url' => "/app/approvals/{$event->requestId}",
                'priority' => in_array($label, ['rejected', 'returned for correction'], true) ? 'high' : 'normal',
                'data' => ['request_id' => $event->requestId, 'decision' => $label],
                'dedupe_key' => "approval.{$event->requestId}.decision.".str_replace(' ', '_', $label),
            ],
        );
    }

    protected function notifyEscalation(WorkflowEscalated $event): void
    {
        $role = \App\Domain\Foundation\Role::query()->find($event->escalationRoleId);

        if ($role === null) {
            return;
        }

        $recipients = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereKey($role->id))
            ->get()
            ->filter(fn (User $user) => $user->hasBranchAccess($event->branchId));

        foreach ($recipients as $user) {
            $this->notifications->notify(
                $user,
                'approval.escalated',
                'Overdue approval: '.$event->subject,
                "Level {$event->level} approval exceeded its SLA and was escalated to role: {$role->name}.",
                [
                    'action_url' => "/app/approvals/{$event->requestId}",
                    'priority' => 'critical',
                    'data' => ['request_id' => $event->requestId, 'level' => $event->level],
                    'dedupe_key' => "approval.{$event->requestId}.escalated.level.{$event->level}.role.{$role->id}",
                ],
            );
        }
    }
}
