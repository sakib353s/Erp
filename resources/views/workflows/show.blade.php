@extends('layouts.app')

@section('page_title', 'Workflow details')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $definition->name }}</h1>
            <p class="erp-page-sub">
                <code>{{ $definition->entity_type }}.{{ $definition->action }}</code>
                · {{ $definition->approval_mode }}
                · version {{ $definition->current_version }}
                <span class="erp-status {{ $definition->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                    {{ $definition->is_active ? 'active' : 'inactive' }}
                </span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('workflows.index') }}">Back</a>
            @if ($perm('workflows.manage'))
                <a class="btn btn-primary" href="{{ route('workflows.edit', $definition) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit (creates a new version)
                </a>
                <form method="POST" action="{{ route('workflows.toggle', $definition) }}">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">
                        {{ $definition->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Configuration</h2></header>
                <dl class="erp-dl">
                    <dt>Description</dt><dd>{{ $definition->description ?: '—' }}</dd>
                    <dt>Approval mode</dt><dd>{{ $definition->approval_mode }}</dd>
                    <dt>Self-approval</dt>
                    <dd>{{ $definition->block_self_approval ? 'Blocked (requester cannot approve own request)' : 'Allowed' }}</dd>
                    <dt>Step SLA</dt><dd>{{ $definition->due_hours }} hours</dd>
                    <dt>Escalation</dt>
                    <dd>
                        @if($definition->escalation_hours > 0)
                            after {{ $definition->escalation_hours }}h → {{ $definition->escalationRole?->name ?: 'no role set' }}
                        @else
                            disabled
                        @endif
                    </dd>
                    <dt>Priority</dt><dd>{{ $definition->priority }}</dd>
                </dl>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Approver levels</h2>
                    <span class="erp-chip erp-chip-soft">{{ $definition->approvers->count() }}</span>
                </header>
                <ol class="erp-levels">
                    @foreach($definition->approvers->sortBy([['level', 'asc'], ['position', 'asc']]) as $approver)
                        <li>
                            <strong>Level {{ $approver->level }}</strong>
                            @if($approver->is_required)<span class="erp-chip erp-chip-soft">required</span>@else<span class="erp-chip erp-chip-soft">optional</span>@endif
                            <span class="small text-body-secondary">
                                — @if($approver->approver_type === 'role')
                                    role · {{ $approver->role?->name ?: 'deleted role' }}
                                @else
                                    user · {{ $approver->user?->name ?: 'deleted user' }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Conditions</h2>
                    <span class="erp-chip erp-chip-soft">{{ $definition->conditions->count() }}</span>
                </header>
                @if($definition->conditions->isEmpty())
                    <p class="text-body-secondary mb-0">No conditions — the workflow matches its event unconditionally.</p>
                @else
                    <div class="table-responsive">
                        <table class="table erp-table mb-0">
                            <thead><tr><th>Subject</th><th>Operator</th><th>Value</th><th>Group</th></tr></thead>
                            <tbody>
                                @foreach($definition->conditions as $condition)
                                    <tr>
                                        <td><code>{{ $condition->subject }}</code></td>
                                        <td>{{ $condition->operator }}</td>
                                        <td class="text-body-secondary">
                                            {{ $condition->value_string ?? trim(($condition->value_min ?? '').($condition->value_max !== null ? ' – '.$condition->value_max : '')) }}
                                        </td>
                                        <td>{{ $condition->condition_group }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Version history</h2>
                    <span class="erp-chip erp-chip-soft">immutable snapshots</span>
                </header>
                @forelse($definition->versions as $version)
                    <div class="erp-list-row">
                        <div>
                            <strong>v{{ $version->version }}</strong>
                            <span class="small text-body-secondary"> · {{ $version->created_at?->format('d M Y, H:i') }}</span>
                        </div>
                        <code class="small" title="{{ $version->snapshot_hash }}">{{ \Illuminate\Support\Str::limit($version->snapshot_hash, 16, '…') }}</code>
                    </div>
                @empty
                    <p class="text-body-secondary mb-0">No versions recorded.</p>
                @endforelse
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Recent requests</h2>
                    <a class="small" href="{{ route('approvals.index') }}">Open inbox →</a>
                </header>
                @forelse($recent as $req)
                    <div class="erp-list-row">
                        <a class="text-decoration-none" href="{{ route('approvals.show', $req) }}">{{ $req->subject }}</a>
                        <span class="erp-status erp-status-{{ $req->status }}">{{ $req->status }}</span>
                    </div>
                @empty
                    <p class="text-body-secondary mb-0">No requests have been submitted against this workflow yet.</p>
                @endforelse
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
