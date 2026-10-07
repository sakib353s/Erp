<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Outbox\Services\OutboxPublisher;
use App\Domain\Workflow\ApprovalAction;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\ApprovalStep;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowCancelled;
use App\Domain\Workflow\Events\WorkflowEvent;
use App\Domain\Workflow\Events\WorkflowRejected;
use App\Domain\Workflow\Events\WorkflowReturned;
use App\Domain\Workflow\Events\WorkflowSubmitted;
use App\Domain\Workflow\WorkflowDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Generic database-driven workflow/approval engine (Rule 15, section G).
 *
 * Every module (Sales, Purchase, Inventory, Accounting, HR, Returns,
 * Payments…) submits through this engine only. Definition resolution,
 * amount thresholds, approver levels, delegation, self-approval blocking,
 * reject/return and escalation all run from database rows — no business
 * module hard-codes approval routing (correction G).
 *
 * All state changes happen inside ONE database transaction with a row lock
 * on the request, so two approvers acting concurrently cannot double-apply
 * a decision (Rule 12).
 */
class WorkflowEngine
{
    public function __construct(
        protected AuditRecorder $audit,
        protected OutboxPublisher $outbox,
        protected WorkflowConditionEvaluator $evaluator,
        protected ApprovalAuthority $authority,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Definition resolution                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $context  amount, branch_id, currency, role_keys…
     */
    public function resolve(string $entityType, string $action, array $context = []): ?WorkflowDefinition
    {
        $definitions = WorkflowDefinition::query()
            ->where('entity_type', $entityType)
            ->where('action', $action)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->with(['conditions', 'approvers'])
            ->get();

        foreach ($definitions as $definition) {
            if ($this->evaluator->matches($definition, $context)) {
                return $definition;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Submission                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Submit an entity for approval. Returns null when no workflow applies
     * (the caller then proceeds without approval — recorded by the caller).
     *
     * @param array{
     *     entity_type: string, entity_id: int, action: string, subject: string,
     *     branch_id: int, submitted_by: User, snapshot: array,
     *     amount?: ?float, currency?: ?string, reference_no?: ?string,
     *     context?: array<string, mixed>
     * } $input
     */
    public function submit(array $input): ?ApprovalRequest
    {
        return DB::transaction(function () use ($input) {
            $user = $input['submitted_by'];
            $context = $input['context'] ?? [
                'amount' => $input['amount'] ?? null,
                'branch_id' => $input['branch_id'],
                'currency' => $input['currency'] ?? 'BDT',
            ];

            $definition = $this->resolve($input['entity_type'], $input['action'], $context);

            if ($definition === null) {
                return null;
            }

            $open = ApprovalRequest::query()
                ->where('entity_type', $input['entity_type'])
                ->where('entity_id', $input['entity_id'])
                ->where('action', $input['action'])
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();

            if ($open) {
                abort(409, 'An approval request for this record is already pending.');
            }

            $revision = 1 + (int) ApprovalRequest::query()
                ->where('entity_type', $input['entity_type'])
                ->where('entity_id', $input['entity_id'])
                ->where('action', $input['action'])
                ->max('revision');

            $approverRules = $definition->approvers->groupBy('level')->sortKeys();

            if ($approverRules->isEmpty()) {
                abort(500, 'Workflow definition has no approver levels configured.');
            }

            $dueHours = $definition->due_hours
                ?? (int) config('erp.workflow.default_step_due_hours', 48);

            $snapshot = $input['snapshot'] ?? [];
            // Freeze the resolved definition's routing config into this
            // request: later definition edits (new WorkflowVersion) never
            // alter how an already-submitted request behaves.
            $snapshot['_workflow'] = [
                'definition_id' => $definition->id,
                'definition_name' => $definition->name,
                'version' => $definition->current_version,
                'approval_mode' => $definition->approval_mode,
                'block_self_approval' => (bool) $definition->block_self_approval,
                'due_hours' => (int) $dueHours,
            ];
            $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $request = ApprovalRequest::create([
                'company_id' => $user->company_id,
                'branch_id' => $input['branch_id'],
                'workflow_definition_id' => $definition->id,
                'workflow_version' => $definition->current_version,
                'entity_type' => $input['entity_type'],
                'entity_id' => $input['entity_id'],
                'action' => $input['action'],
                'reference_no' => $input['reference_no'] ?? null,
                'subject' => $input['subject'],
                'amount' => $input['amount'] ?? null,
                'currency' => $input['currency'] ?? 'BDT',
                'status' => 'pending',
                'current_level' => (int) $approverRules->keys()->first(),
                'revision' => $revision,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'due_at' => now()->addHours($dueHours),
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshotHash,
            ]);

            foreach ($approverRules as $level => $rules) {
                foreach ($rules as $rule) {
                    ApprovalStep::create([
                        'approval_request_id' => $request->id,
                        'level' => (int) $level,
                        'status' => 'pending',
                        'approver_user_id' => $rule->approver_type === 'user' ? $rule->user_id : null,
                        'approver_role_id' => $rule->approver_type === 'role' ? $rule->role_id : null,
                        'is_required' => $rule->is_required,
                        'due_at' => now()->addHours($dueHours),
                    ]);
                }
            }

            $this->logAction($request, null, $user, 'submit', $input['comment'] ?? null);
            $request->load('steps');

            $this->audit->record([
                'action' => 'approval.submit',
                'entity_type' => $input['entity_type'],
                'entity_id' => $input['entity_id'],
                'branch_id' => $input['branch_id'],
                'actor_id' => $user->id,
                'amount' => $input['amount'] ?? null,
                'currency' => $input['currency'] ?? null,
                'after' => [
                    'request_id' => $request->id,
                    'subject' => $request->subject,
                    'definition' => $definition->name,
                    'revision' => $revision,
                    'levels' => $approverRules->keys()->all(),
                ],
            ]);

            $this->publish(WorkflowSubmitted::class, $request, $user);

            return $request;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Decisions                                                           */
    /* ------------------------------------------------------------------ */

    public function approve(int $requestId, User $actor, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $comment) {
            $request = $this->lockedRequest($requestId);

            $actionable = $this->authority->actionableSteps($request, $actor, 'approve');

            if ($actionable->isEmpty()) {
                $blocked = $this->authority->selfApprovalBlocked($request, $actor);

                $this->audit->record([
                    'action' => 'approval.approve',
                    'entity_type' => $request->entity_type,
                    'entity_id' => $request->entity_id,
                    'actor_id' => $actor->id,
                    'result' => 'denied',
                    'reason' => $blocked ? 'self approval prohibited' : 'not an approver for this step',
                ]);

                abort(403, $blocked
                    ? 'You cannot approve your own request.'
                    : 'You are not an approver for this step.');
            }

            foreach ($actionable as $step) {
                $step->update([
                    'status' => 'approved',
                    'acted_by' => $actor->id,
                    'acted_at' => now(),
                ]);

                $this->logAction($request, $step, $actor, 'approve', $comment);
            }

            $request->load(['steps', 'definition']);
            $definition = $request->definition;
            $pending = $request->steps->filter(fn (ApprovalStep $step) => $step->status === 'pending');

            $final = false;
            $advanced = null;

            if ($request->isParallelMode()) {
                if ($pending->isEmpty()) {
                    $final = true;
                }
            } elseif ($pending->filter(fn (ApprovalStep $step) => (int) $step->level === (int) $request->current_level)->isEmpty()) {
                $nextLevel = $pending->pluck('level')->unique()->sort()->first();

                if ($nextLevel === null) {
                    $final = true;
                } else {
                    $request->current_level = (int) $nextLevel;
                    $request->save();
                    $advanced = (int) $nextLevel;
                }
            }

            $after = ['request_id' => $request->id, 'levels_approved' => $actionable->pluck('level')->unique()->all()];

            if ($final) {
                $request->forceFill(['status' => 'approved', 'decided_at' => now()])->save();
                $after['status'] = 'approved';
            } elseif ($advanced !== null) {
                $after['status'] = 'pending';
                $after['current_level'] = $advanced;
            } else {
                $after['status'] = 'pending';
            }

            $this->audit->record([
                'action' => 'approval.approve',
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'after' => $after,
            ]);

            if ($final) {
                $this->publish(WorkflowApproved::class, $request, $actor, $comment);
            } elseif ($advanced !== null) {
                $this->publish(WorkflowSubmitted::class, $request, $actor);
            }

            return $request->fresh(['steps', 'definition']);
        });
    }

    public function reject(int $requestId, User $actor, string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $comment) {
            $request = $this->lockedRequest($requestId);

            $actionable = $this->authority->actionableSteps($request, $actor, 'reject');

            if ($actionable->isEmpty()) {
                abort(403, 'You are not an approver for this step.');
            }

            foreach ($actionable as $step) {
                $step->update(['status' => 'rejected', 'acted_by' => $actor->id, 'acted_at' => now()]);
                $this->logAction($request, $step, $actor, 'reject', $comment);
            }

            ApprovalStep::query()
                ->where('approval_request_id', $request->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $request->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();

            $this->audit->record([
                'action' => 'approval.reject',
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->id,
                'amount' => $request->amount,
                'reason' => mb_substr($comment, 0, 191),
                'after' => ['request_id' => $request->id, 'status' => 'rejected'],
            ]);

            $this->publish(WorkflowRejected::class, $request, $actor, $comment);

            return $request->fresh(['steps', 'definition']);
        });
    }

    public function returnForCorrection(int $requestId, User $actor, string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $comment) {
            $request = $this->lockedRequest($requestId);

            $actionable = $this->authority->actionableSteps($request, $actor, 'return');

            if ($actionable->isEmpty()) {
                abort(403, 'You are not an approver for this step.');
            }

            foreach ($actionable as $step) {
                $step->update(['status' => 'returned', 'acted_by' => $actor->id, 'acted_at' => now()]);
                $this->logAction($request, $step, $actor, 'return', $comment);
            }

            ApprovalStep::query()
                ->where('approval_request_id', $request->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $request->forceFill([
                'status' => 'returned',
                'decided_at' => now(),
                'return_count' => $request->return_count + 1,
            ])->save();

            $this->audit->record([
                'action' => 'approval.return',
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->id,
                'reason' => mb_substr($comment, 0, 191),
                'after' => ['request_id' => $request->id, 'status' => 'returned'],
            ]);

            $this->publish(WorkflowReturned::class, $request, $actor, $comment);

            return $request->fresh(['steps', 'definition']);
        });
    }

    public function cancel(int $requestId, User $actor, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $comment) {
            $request = $this->lockedRequest($requestId);

            $isSubmitter = (int) $request->submitted_by === (int) $actor->id;
            $canAct = $this->authority->actionableSteps($request, $actor, 'reject')->isNotEmpty();

            if (! $isSubmitter && ! $canAct && ! $actor->isSuperAdmin()) {
                abort(403, 'Only the requester, an approver or a super admin may cancel this request.');
            }

            ApprovalStep::query()
                ->where('approval_request_id', $request->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $request->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();

            $this->logAction($request, null, $actor, 'cancel', $comment);

            $this->audit->record([
                'action' => 'approval.cancel',
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->id,
                'reason' => $comment !== null ? mb_substr($comment, 0, 191) : null,
                'after' => ['request_id' => $request->id, 'status' => 'cancelled'],
            ]);

            $this->publish(WorkflowCancelled::class, $request, $actor, $comment);

            return $request->fresh(['steps', 'definition']);
        });
    }

    public function addComment(int $requestId, User $actor, string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $comment) {
            $request = ApprovalRequest::query()->findOrFail($requestId);

            $this->logAction($request, null, $actor, 'comment', $comment);

            $this->audit->record([
                'action' => 'approval.comment',
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'actor_id' => $actor->id,
                'after' => ['request_id' => $request->id, 'comment' => mb_substr($comment, 0, 500)],
            ]);

            return $request;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    protected function lockedRequest(int $id): ApprovalRequest
    {
        $request = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();

        if ($request === null) {
            abort(404, 'Approval request not found.');
        }

        if ($request->status !== 'pending') {
            abort(409, 'This approval request is no longer pending.');
        }

        $request->load(['steps', 'definition']);

        return $request;
    }

    protected function logAction(
        ApprovalRequest $request,
        ?ApprovalStep $step,
        User $actor,
        string $action,
        ?string $comment,
    ): void {
        ApprovalAction::create([
            'approval_request_id' => $request->id,
            'approval_step_id' => $step?->id,
            'user_id' => $actor->id,
            'action' => $action,
            'comment' => $comment,
            'level' => $step?->level,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 191),
        ]);
    }

    /**
     * @param  class-string<WorkflowEvent>  $class
     */
    protected function publish(string $class, ApprovalRequest $request, User $actor, ?string $comment = null): void
    {
        $this->outbox->publish(
            new $class(
                companyId: (int) $request->company_id,
                branchId: (int) $request->branch_id,
                requestId: $request->id,
                entityType: $request->entity_type,
                entityId: (int) $request->entity_id,
                action: $request->action,
                subject: $request->subject,
                amount: $request->amount !== null ? (float) $request->amount : null,
                submittedBy: (int) $request->submitted_by,
                actorId: $actor->id,
                comment: $comment,
            ),
            options: [
                'company_id' => (int) $request->company_id,
                'aggregate_type' => 'approval_request',
                'aggregate_id' => $request->id,
                'idempotency_key' => hash('sha256', implode('.', [
                    'approval', $request->id, strtolower(class_basename($class)),
                    $request->revision, $request->current_level, $request->return_count,
                ])),
            ],
        );
    }
}
