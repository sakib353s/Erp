@extends('layouts.app')

@section('page_title', 'Effective permissions')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Effective access — {{ $user->name }}</h1>
            <p class="erp-page-sub">Resolved from the database permission matrix (roles ∪ direct grants − denies). Not hardcoded.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('users.show', $user) }}">Back to user</a>
            @if ($perm('users.update'))
                <a class="btn btn-primary" href="{{ route('users.edit', $user) }}">Edit assignments</a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Account</h2></header>
                <dl class="erp-dl">
                    <dt>Status</dt>
                    <dd>
                        <span class="erp-status erp-status-{{ $user->suspended_at ? 'disabled' : $user->status }}">
                            {{ $user->suspended_at ? 'suspended' : $user->status }}
                        </span>
                    </dd>
                    <dt>Super admin</dt>
                    <dd>{{ $isSuperAdmin ? 'Yes — bypasses the matrix' : 'No' }}</dd>
                    <dt>Roles</dt>
                    <dd>
                        @forelse($user->roles as $r)
                            <span class="erp-chip">{{ $r->name }}</span>
                        @empty
                            <span class="text-body-secondary">None</span>
                        @endforelse
                    </dd>
                    <dt>Branch scope</dt>
                    <dd>{{ $user->branch_scope === 'all' ? 'All branches' : 'Assigned only' }}</dd>
                </dl>
            </section>
        </div>

        <div class="col-lg-8">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Resolved permission keys</h2>
                    <span class="erp-chip erp-chip-soft">{{ count($keys) }}</span>
                </header>

                @if ($isSuperAdmin)
                    <p class="mb-0"><code>*</code> — every key (super-admin wildcard).</p>
                @elseif(empty($keys))
                    <p class="text-body-secondary mb-0">No effective permissions.</p>
                @else
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($keys as $key)
                            <span class="erp-chip">{{ $key }}</span>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
