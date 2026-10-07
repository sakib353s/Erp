<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Role;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowCondition;
use App\Domain\Workflow\WorkflowDefinition;
use App\Domain\Workflow\WorkflowVersion;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Requests\UpdateWorkflowRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Generic workflow definition admin (Rule 15 / correction G): the SAME
 * screens configure sales, purchase, inventory, accounting, HR and any
 * future approval — nothing workflow-shaped is ever hard-coded in a
 * business module. Updates create a new immutable WorkflowVersion
 * (snapshot + hash) while pending requests keep their materialised
 * steps and recorded workflow_version.
 */
class WorkflowController extends Controller
{
    public function __construct(
        protected AuditRecorder $audit,
    ) {}

    public function index(): View
    {
        return view('workflows.index', [
            'definitions' => WorkflowDefinition::query()
                ->with(['approvers.role', 'escalationRole'])
                ->withCount(['approvers', 'conditions'])
                ->withCount(['requests as requests_count'])
                ->orderBy('entity_type')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('workflows.form', [
            'definition' => new WorkflowDefinition([
                'is_active' => true,
                'approval_mode' => 'sequential',
                'block_self_approval' => (bool) config('erp.workflow.block_self_approval_default', true),
                'due_hours' => (int) config('erp.workflow.default_step_due_hours', 48),
                'priority' => 0,
            ]),
            'roles' => Role::query()->orderBy('name')->get(),
            'mode' => 'create',
        ]);
    }

    public function store(StoreWorkflowRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $definition = DB::transaction(function () use ($data, $request) {
            $definition = WorkflowDefinition::create([
                'company_id' => $request->user()->company_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'entity_type' => $data['entity_type'],
                'action' => $data['action'],
                'approval_mode' => $data['approval_mode'],
                'block_self_approval' => (bool) ($data['block_self_approval'] ?? true),
                'due_hours' => $data['due_hours'],
                'escalation_hours' => $data['escalation_hours'] ?? 0,
                'escalation_role_id' => $data['escalation_role_id'] ?? null,
                'priority' => $data['priority'] ?? 0,
                'is_active' => true,
                'current_version' => 1,
                'created_by' => $request->user()->id,
            ]);

            $this->syncChildren($definition, $data);
            $this->writeVersion($definition, $request->user()->id);

            return $definition;
        });

        $this->audit->record([
            'action' => 'workflow.definition_toggled',
            'entity_type' => 'workflow_definition',
            'entity_id' => $definition->id,
            'actor_id' => $request->user()->id,
            'after' => ['name' => $definition->name, 'event' => 'created', 'entity_type_ref' => $definition->entity_type],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('workflows.show', $definition)->with('status', 'Workflow created (version 1).');
    }

    public function show(Request $request, WorkflowDefinition $definition): View
    {
        abort_unless($definition->company_id === $request->user()->company_id, 404);

        return view('workflows.show', [
            'definition' => $definition->load([
                'approvers.role', 'approvers.user', 'conditions', 'versions', 'escalationRole',
            ]),
            'recent' => ApprovalRequest::query()
                ->where('workflow_definition_id', $definition->id)
                ->with('submitter')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function edit(WorkflowDefinition $definition): View
    {
        return view('workflows.form', [
            'definition' => $definition->load(['approvers', 'conditions']),
            'roles' => Role::query()->orderBy('name')->get(),
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateWorkflowRequest $request, WorkflowDefinition $definition): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($definition, $data, $request) {
            $definition->fill(array_diff_key($data, array_flip(['approvers', 'conditions'])))->save();

            $this->syncChildren($definition, $data);
            $definition->increment('current_version');
            $definition->refresh();
            $this->writeVersion($definition, $request->user()->id);
        });

        $this->audit->record([
            'action' => 'workflow.definition_toggled',
            'entity_type' => 'workflow_definition',
            'entity_id' => $definition->id,
            'actor_id' => $request->user()->id,
            'after' => ['event' => 'updated', 'version' => $definition->current_version],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('workflows.show', $definition)
            ->with('status', "Workflow updated — saved as version {$definition->current_version}. Pending requests keep their existing routing.");
    }

    public function toggle(Request $request, WorkflowDefinition $definition): RedirectResponse
    {
        $before = (bool) $definition->is_active;
        $definition->is_active = ! $before;
        $definition->save();

        $this->audit->record([
            'action' => 'workflow.definition_toggled',
            'entity_type' => 'workflow_definition',
            'entity_id' => $definition->id,
            'actor_id' => $request->user()->id,
            'before' => ['is_active' => $before],
            'after' => ['is_active' => $definition->is_active],
            'ip' => (string) $request->ip(),
        ]);

        return back()->with('status', $definition->is_active ? 'Workflow activated.' : 'Workflow deactivated.');
    }

    /** @param array<string, mixed> $data */
    protected function syncChildren(WorkflowDefinition $definition, array $data): void
    {
        $definition->approvers()->delete();

        foreach ($data['approvers'] as $i => $approver) {
            WorkflowApprover::create([
                'workflow_definition_id' => $definition->id,
                'level' => (int) $approver['level'],
                'approver_type' => $approver['approver_type'],
                'role_id' => $approver['role_id'] ?? null,
                'user_id' => $approver['user_id'] ?? null,
                'is_required' => (bool) ($approver['is_required'] ?? true),
                'position' => $i,
            ]);
        }

        $definition->conditions()->delete();

        foreach (($data['conditions'] ?? []) as $i => $condition) {
            if (trim((string) ($condition['subject'] ?? '')) === '') {
                continue;
            }

            WorkflowCondition::create([
                'workflow_definition_id' => $definition->id,
                'subject' => $condition['subject'],
                'operator' => $condition['operator'],
                'value_string' => $condition['value_string'] ?? null,
                'value_min' => $condition['value_min'] ?? null,
                'value_max' => $condition['value_max'] ?? null,
                'condition_group' => (int) ($condition['condition_group'] ?? 0),
                'position' => $i,
            ]);
        }
    }

    protected function writeVersion(WorkflowDefinition $definition, int $actorId): void
    {
        $definition->load(['approvers', 'conditions']);

        $snapshot = [
            'name' => $definition->name,
            'entity_type' => $definition->entity_type,
            'action' => $definition->action,
            'approval_mode' => $definition->approval_mode,
            'block_self_approval' => $definition->block_self_approval,
            'due_hours' => $definition->due_hours,
            'escalation_hours' => $definition->escalation_hours,
            'escalation_role_id' => $definition->escalation_role_id,
            'priority' => $definition->priority,
            'is_active' => $definition->is_active,
            'approvers' => $definition->approvers->map(fn ($a) => $a->only([
                'level', 'approver_type', 'role_id', 'user_id', 'is_required',
            ]))->all(),
            'conditions' => $definition->conditions->map(fn ($c) => $c->only([
                'subject', 'operator', 'value_string', 'value_min', 'value_max', 'condition_group',
            ]))->all(),
        ];

        WorkflowVersion::create([
            'workflow_definition_id' => $definition->id,
            'version' => $definition->current_version,
            'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'activated_at' => now(),
            'created_by' => $actorId,
        ]);
    }
}
