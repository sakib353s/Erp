<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Outbox\Services\OutboxPublisher;
use App\Domain\Workflow\ApprovalAction;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\ApprovalStep;
use App\Domain\Workflow\Events\WorkflowEscalated;
use Illuminate\Support\Facades\DB;

/**
 * Escalation of overdue approval steps (section G). Runs from
 * `php artisan erp:workflow:escalate` (schedule every 15 minutes per
 * config erp.workflow.escalation_check_minutes). Escalation targets are
 * read from workflow_definitions.escalation_role_id — never hard-coded.
 */
class WorkflowEscalationService
{
    public function __construct(
        protected AuditRecorder $audit,
        protected OutboxPublisher $outbox,
    ) {}

    public function escalateDue(): int
    {
        $candidates = ApprovalStep::query()
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->whereNull('escalated_at')
            ->whereHas('request', fn ($q) => $q->where('status', 'pending'))
            ->with(['request.definition'])
            ->get()
            ->filter(function (ApprovalStep $step) {
                $definition = $step->request?->definition;

                if ($definition === null || $definition->escalation_role_id === null) {
                    return false; // escalation not configured for this workflow
                }

                $extraHours = (int) ($definition->escalation_hours ?? 0);

                return $step->due_at->copy()->addHours($extraHours)->lte(now());
            });

        $count = 0;

        foreach ($candidates as $step) {
            DB::transaction(function () use ($step, &$count) {
                /** @var ApprovalRequest $request */
                $request = ApprovalStep::query()
                    ->whereKey($step->id)
                    ->lockForUpdate()
                    ->first()
                    ->request;

                if ($request === null || $request->status !== 'pending') {
                    return;
                }

                $definition = $request->definition;

                $step->forceFill(['escalated_at' => now()])->save();

                ApprovalAction::create([
                    'approval_request_id' => $request->id,
                    'approval_step_id' => $step->id,
                    'user_id' => null, // system action
                    'action' => 'escalate',
                    'comment' => 'Escalated: approval step exceeded its SLA.',
                    'level' => $step->level,
                ]);

                $this->audit->record([
                    'action' => 'approval.escalate',
                    'entity_type' => $request->entity_type,
                    'entity_id' => $request->entity_id,
                    'branch_id' => $request->branch_id,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'after' => [
                        'request_id' => $request->id,
                        'step_id' => $step->id,
                        'level' => $step->level,
                        'escalation_role_id' => $definition->escalation_role_id,
                    ],
                ]);

                $this->outbox->publish(
                    new WorkflowEscalated(
                        companyId: (int) $request->company_id,
                        branchId: (int) $request->branch_id,
                        requestId: $request->id,
                        stepId: $step->id,
                        level: (int) $step->level,
                        escalationRoleId: (int) $definition->escalation_role_id,
                        subject: $request->subject,
                    ),
                    options: [
                        'company_id' => (int) $request->company_id,
                        'aggregate_type' => 'approval_request',
                        'aggregate_id' => $request->id,
                        'idempotency_key' => hash('sha256', "escalate.{$request->id}.{$step->id}.{$step->level}"),
                    ],
                );

                $count++;
            });
        }

        return $count;
    }
}
