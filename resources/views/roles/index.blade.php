@extends('layouts.app')

@section('page_title', 'Roles & permissions')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Roles &amp; permissions</h1>
            <p class="erp-page-sub">Database-driven RBAC — the permission matrix below is the single source of authorisation truth.</p>
        </div>
        @if ($perm('roles.create'))
            <a class="btn btn-primary" href="{{ route('roles.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New role
            </a>
        @endif
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Permissions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('roles.show', $role) }}">{{ $role->name }}</a>
                                @if($role->is_system)<span class="erp-chip erp-chip-soft">system</span>@endif
                            </td>
                            <td><code>{{ $role->slug }}</code></td>
                            <td class="text-body-secondary">{{ $role->description ?: '—' }}</td>
                            <td>{{ $role->users_count ?? $role->users()->count() }}</td>
                            <td>{{ $role->permissions_count ?? $role->permissions()->count() }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('roles.show', $role) }}">View</a>
                                @if ($perm('roles.update'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('roles.edit', $role) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-body-secondary">No roles yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
