@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New workflow' : 'Edit workflow')

@section('content')
    @php
        $approvers = $definition->approvers->sortBy([['level', 'asc'], ['position', 'asc']])->values();
        $conditions = $definition->conditions->sortBy('position')->values();
        $approverUsers = \App\Domain\Foundation\User::query()->orderBy('name')->get(['id', 'name', 'email']);
        $operatorList = \App\Http\Requests\StoreWorkflowRequest::OPERATORS;
    @endphp

    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New workflow' : 'Edit: '.$definition->name }}</h1>
            <p class="erp-page-sub">
                Saving creates a new immutable version. Requests already in flight keep the routing they were submitted with.
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('workflows.index') }}">Back to workflows</a>
    </div>

    <form method="POST" action="{{ $mode === 'create' ? route('workflows.store') : route('workflows.update', $definition) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Definition</h2></header>
                    <div class="mb-3">
                        <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                               value="{{ old('name', $definition->name) }}" required maxlength="128">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2">{{ old('description', $definition->description) }}</textarea>
                        @error('description')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="entity_type">Entity type <span class="text-danger">*</span></label>
                            <input class="form-control @error('entity_type') is-invalid @enderror" id="entity_type"
                                   name="entity_type" value="{{ old('entity_type', $definition->entity_type) }}"
                                   placeholder="sales.order" required>
                            @error('entity_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="action">Action <span class="text-danger">*</span></label>
                            <input class="form-control @error('action') is-invalid @enderror" id="action"
                                   name="action" value="{{ old('action', $definition->action) }}" placeholder="post" required>
                            @error('action')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label" for="approval_mode">Approval mode <span class="text-danger">*</span></label>
                        <select class="form-select @error('approval_mode') is-invalid @enderror" id="approval_mode" name="approval_mode">
                            <option value="sequential" @selected(old('approval_mode', $definition->approval_mode) === 'sequential')>Sequential — levels in order</option>
                            <option value="parallel" @selected(old('approval_mode', $definition->approval_mode) === 'parallel')>Parallel — all levels simultaneously</option>
                        </select>
                        @error('approval_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="block_self_approval"
                               name="block_self_approval" value="1"
                               @checked((bool) old('block_self_approval', $definition->block_self_approval))>
                        <label class="form-check-label" for="block_self_approval">Block self-approval</label>
                    </div>
                    @error('block_self_approval')<div class="text-danger small">{{ $message }}</div>@enderror
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <label class="form-label" for="due_hours">SLA (h) <span class="text-danger">*</span></label>
                            <input class="form-control @error('due_hours') is-invalid @enderror" type="number" min="1" max="720"
                                   id="due_hours" name="due_hours" value="{{ old('due_hours', $definition->due_hours) }}" required>
                            @error('due_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="escalation_hours">Escalate after (h)</label>
                            <input class="form-control @error('escalation_hours') is-invalid @enderror" type="number" min="0" max="720"
                                   id="escalation_hours" name="escalation_hours" value="{{ old('escalation_hours', $definition->escalation_hours) }}">
                            @error('escalation_hours')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="priority">Priority</label>
                            <input class="form-control" type="number" min="-100" max="100" id="priority" name="priority"
                                   value="{{ old('priority', $definition->priority) }}">
                            @error('priority')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label" for="escalation_role_id">Escalation role</label>
                        <select class="form-select" id="escalation_role_id" name="escalation_role_id">
                            <option value="">— none —</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}" @selected((int) old('escalation_role_id', $definition->escalation_role_id) === $role->id)>
                                    {{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('escalation_role_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create workflow (v1)' : 'Save as new version' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('workflows.index') }}">Cancel</a>
                </div>
            </div>

            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Approver levels</h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-repeater-add="approvers">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add level
                        </button>
                    </header>
                    @error('approvers')<div class="alert alert-danger">{{ $message }}</div>@enderror

                    <div data-repeater="approvers" data-repeater-start="{{ $approvers->count() }}">
                        @foreach($approvers as $i => $approver)
                            <div class="erp-repeater-row" data-repeater-row>
                                <input type="hidden" name="approvers[{{ $i }}][is_required]" value="0">
                                <div class="row g-2 align-items-end">
                                    <div class="col-2">
                                        <label class="form-label small">Level</label>
                                        <input class="form-control" type="number" min="1" max="20" name="approvers[{{ $i }}][level]"
                                               value="{{ old("approvers.$i.level", $approver->level) }}" required>
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label small">Approver is</label>
                                        <select class="form-select" name="approvers[{{ $i }}][approver_type]" data-approver-type>
                                            <option value="role" @selected(old("approvers.$i.approver_type", $approver->approver_type) === 'role')>A role</option>
                                            <option value="user" @selected(old("approvers.$i.approver_type", $approver->approver_type) === 'user')>A specific user</option>
                                        </select>
                                    </div>
                                    <div class="col-3" data-approver-role>
                                        <label class="form-label small">Role</label>
                                        <select class="form-select" name="approvers[{{ $i }}][role_id]">
                                            <option value="">— select role —</option>
                                            @foreach($roles as $role)
                                                <option value="{{ $role->id }}" @selected((int) old("approvers.$i.role_id", $approver->role_id) === $role->id)>{{ $role->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-3" data-approver-user>
                                        <label class="form-label small">User</label>
                                        <select class="form-select" name="approvers[{{ $i }}][user_id]">
                                            <option value="">— select user —</option>
                                            @foreach($approverUsers as $u)
                                                <option value="{{ $u->id }}" @selected((int) old("approvers.$i.user_id", $approver->user_id) === $u->id)>{{ $u->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-1">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="approvers[{{ $i }}][is_required]" value="1"
                                                   @checked((bool) old("approvers.$i.is_required", $approver->is_required))>
                                            <label class="form-check-label small" title="Required">req</label>
                                        </div>
                                    </div>
                                    <div class="col-1 text-end">
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-repeater-remove aria-label="Remove level">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <template data-repeater-template="approvers">
                        <div class="erp-repeater-row" data-repeater-row>
                            <input type="hidden" name="approvers[__IDX__][is_required]" value="0">
                            <div class="row g-2 align-items-end">
                                <div class="col-2">
                                    <label class="form-label small">Level</label>
                                    <input class="form-control" type="number" min="1" max="20" name="approvers[__IDX__][level]" value="1" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label small">Approver is</label>
                                    <select class="form-select" name="approvers[__IDX__][approver_type]" data-approver-type>
                                        <option value="role">A role</option>
                                        <option value="user">A specific user</option>
                                    </select>
                                </div>
                                <div class="col-3" data-approver-role>
                                    <label class="form-label small">Role</label>
                                    <select class="form-select" name="approvers[__IDX__][role_id]">
                                        <option value="">— select role —</option>
                                        @foreach($roles as $role)
                                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-3" data-approver-user>
                                    <label class="form-label small">User</label>
                                    <select class="form-select" name="approvers[__IDX__][user_id]">
                                        <option value="">— select user —</option>
                                        @foreach($approverUsers as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-1">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="approvers[__IDX__][is_required]" value="1" checked>
                                        <label class="form-check-label small" title="Required">req</label>
                                    </div>
                                </div>
                                <div class="col-1 text-end">
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-repeater-remove aria-label="Remove level">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <p class="form-text mt-2 mb-0">
                        Sequential mode walks levels in order; parallel mode requires every level concurrently.
                        Self-approval and branch scope are enforced by the engine regardless of this screen.
                    </p>
                </section>

                <section class="erp-card mt-3">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Conditions <span class="fw-normal small text-body-secondary">(all groups must match; rows in a group are OR)</span></h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-repeater-add="conditions">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add condition
                        </button>
                    </header>
                    @error('conditions')<div class="alert alert-danger">{{ $message }}</div>@enderror

                    <div data-repeater="conditions" data-repeater-start="{{ $conditions->count() }}">
                        @foreach($conditions as $i => $condition)
                            <div class="erp-repeater-row" data-repeater-row>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label small">Subject</label>
                                        <input class="form-control" name="conditions[{{ $i }}][subject]"
                                               value="{{ old("conditions.$i.subject", $condition->subject) }}" placeholder="payload.total" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Operator</label>
                                        <select class="form-select" name="conditions[{{ $i }}][operator]">
                                            @foreach($operatorList as $operator)
                                                <option value="{{ $operator }}" @selected(old("conditions.$i.operator", $condition->operator) === $operator)>{{ $operator }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Value</label>
                                        <input class="form-control" name="conditions[{{ $i }}][value_string]"
                                               value="{{ old("conditions.$i.value_string", $condition->value_string) }}">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Min</label>
                                        <input class="form-control" type="number" step="any" name="conditions[{{ $i }}][value_min]"
                                               value="{{ old("conditions.$i.value_min", $condition->value_min) }}">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small">Max</label>
                                        <input class="form-control" type="number" step="any" name="conditions[{{ $i }}][value_max]"
                                               value="{{ old("conditions.$i.value_max", $condition->value_max) }}">
                                    </div>
                                    <div class="col-md-1 text-end">
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-repeater-remove aria-label="Remove condition">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-0">Group (OR rows sharing a number; different numbers AND together)</label>
                                        <input class="form-control form-control-sm" type="number" min="0" max="50"
                                               name="conditions[{{ $i }}][condition_group]"
                                               value="{{ old("conditions.$i.condition_group", $condition->condition_group) }}">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <template data-repeater-template="conditions">
                        <div class="erp-repeater-row" data-repeater-row>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small">Subject</label>
                                    <input class="form-control" name="conditions[__IDX__][subject]" placeholder="payload.total" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Operator</label>
                                    <select class="form-select" name="conditions[__IDX__][operator]">
                                        @foreach($operatorList as $operator)
                                            <option value="{{ $operator }}">{{ $operator }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Value</label>
                                    <input class="form-control" name="conditions[__IDX__][value_string]">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Min</label>
                                    <input class="form-control" type="number" step="any" name="conditions[__IDX__][value_min]">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">Max</label>
                                    <input class="form-control" type="number" step="any" name="conditions[__IDX__][value_max]">
                                </div>
                                <div class="col-md-1 text-end">
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-repeater-remove aria-label="Remove condition">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-0">Group</label>
                                    <input class="form-control form-control-sm" type="number" min="0" max="50"
                                           name="conditions[__IDX__][condition_group]" value="0">
                                </div>
                            </div>
                        </div>
                    </template>
                </section>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
