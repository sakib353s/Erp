@extends('layouts.app')

@section('page_title', 'Role details')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $role->name }}</h1>
            <p class="erp-page-sub"><code>{{ $role->slug }}</code> @if($role->is_system)· system role @endif · {{ $role->description ?: '' }}</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('roles.index') }}">Back to roles</a>
            @if ($perm('roles.update'))
                <a class="btn btn-primary" href="{{ route('roles.edit', $role) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit permissions
                </a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Granted permissions</h2>
                    <span class="erp-chip erp-chip-soft">{{ $role->permissions->count() }}</span>
                </header>

                @php($grouped = $role->permissions->groupBy('module'))
                @forelse($grouped as $module => $permissions)
                    <p class="erp-field-label">{{ ucfirst(str_replace('_', ' ', $module)) }}</p>
                    <div class="mb-3">
                        @foreach($permissions as $p)
                            <span class="erp-chip" title="{{ $p->key }}">{{ $p->resource }}.{{ $p->action }}</span>
                        @endforeach
                    </div>
                @empty
                    <p class="text-body-secondary">This role has no permissions yet.</p>
                @endforelse
            </section>
        </div>

        <div class="col-lg-5">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Assigned users</h2>
                    <span class="erp-chip erp-chip-soft">{{ $role->users->count() }}</span>
                </header>
                @forelse($role->users as $u)
                    <div class="erp-list-row">
                        <a class="fw-semibold text-decoration-none" href="{{ route('users.show', $u) }}">{{ $u->name }}</a>
                        <span class="text-body-secondary small">{{ $u->email }}</span>
                    </div>
                @empty
                    <p class="text-body-secondary">No users hold this role.</p>
                @endforelse
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
