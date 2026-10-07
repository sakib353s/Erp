@extends('layouts.app')

@section('page_title', 'Workflows')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Workflows</h1>
            <p class="erp-page-sub">One generic engine drives every module's approvals — versions, thresholds, levels, delegation and escalation, all database-driven.</p>
        </div>
        @if ($perm('workflows.manage'))
            <a class="btn btn-primary" href="{{ route('workflows.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New workflow
            </a>
        @endif
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Workflow</th>
                        <th>Event</th>
                        <th>Mode</th>
                        <th>Levels</th>
                        <th>Conditions</th>
                        <th>Requests</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($definitions as $definition)
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('workflows.show', $definition) }}">{{ $definition->name }}</a>
                                <span class="d-block small text-body-secondary">v{{ $definition->current_version }} · due {{ $definition->due_hours }}h</span>
                            </td>
                            <td><code class="small">{{ $definition->entity_type }}.{{ $definition->action }}</code></td>
                            <td><span class="erp-chip erp-chip-soft">{{ $definition->approval_mode }}</span></td>
                            <td>{{ $definition->approvers_count ?? $definition->approvers->count() }}</td>
                            <td>{{ $definition->conditions_count ?? $definition->conditions->count() }}</td>
                            <td>{{ $definition->requests_count ?? 0 }}</td>
                            <td>
                                <span class="erp-status {{ $definition->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $definition->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('workflows.show', $definition) }}">View</a>
                                @if ($perm('workflows.manage'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('workflows.edit', $definition) }}">Edit</a>
                                    <form class="d-inline" method="POST" action="{{ route('workflows.toggle', $definition) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            {{ $definition->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-body-secondary">
                                No workflow definitions yet — create one before modules submit approvals.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
