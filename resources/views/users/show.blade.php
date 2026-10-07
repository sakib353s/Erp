@extends('layouts.app')

@section('page_title', 'User details')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $user->name }}</h1>
            <p class="erp-page-sub">{{ $user->email }}</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Back to users</a>
            @if ($perm('users.view'))
                <a class="btn btn-outline-secondary" href="{{ route('users.access', $user) }}">
                    <i class="bi bi-key" aria-hidden="true"></i> Effective access
                </a>
            @endif
            @if ($perm('users.update'))
                <a class="btn btn-primary" href="{{ route('users.edit', $user) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            @if ($perm('users.update') && auth()->id() !== $user->id && !$user->is_super_admin)
                @if ($user->suspended_at)
                    <form method="POST" action="{{ route('users.activate', $user) }}">
                        @csrf
                        <button class="btn btn-outline-success" type="submit">
                            <i class="bi bi-unlock" aria-hidden="true"></i> Reactivate
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('users.suspend', $user) }}"
                          data-confirm="Suspend {{ $user->name }}? They will lose access immediately.">
                        @csrf
                        <input type="hidden" name="reason" value="Suspended by administrator">
                        <button class="btn btn-outline-warning" type="submit">
                            <i class="bi bi-pause-circle" aria-hidden="true"></i> Suspend
                        </button>
                    </form>
                @endif
            @endif
            @if ($perm('users.delete') && auth()->id() !== $user->id)
                <form method="POST" action="{{ route('users.destroy', $user) }}"
                      data-confirm="Delete {{ $user->name }}? Their role and branch assignments are removed too. This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger" type="submit">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Account</h2></header>
                <dl class="erp-dl">
                    <dt>Status</dt>
                    <dd><span class="erp-status erp-status-{{ $user->status }}">{{ $user->status }}</span>
                        @if($user->suspended_at)
                            <span class="erp-chip erp-chip-warn ms-1">suspended</span>
                            <div class="text-body-secondary small">{{ $user->suspension_reason }}</div>
                        @elseif($user->must_change_password)<span class="erp-chip erp-chip-warn ms-1">password change pending</span>@endif
                    </dd>
                    <dt>Phone</dt>
                    <dd>{{ $user->phone ?: '—' }}</dd>
                    <dt>Branch scope</dt>
                    <dd>{{ $user->branch_scope === 'all' ? 'All branches (unrestricted)' : 'Assigned branches only' }}</dd>
                    <dt>Default branch</dt>
                    <dd>{{ $user->defaultBranch?->name ?: '—' }}</dd>
                    <dt>Member since</dt>
                    <dd>{{ $user->created_at?->format('d M Y') }}</dd>
                </dl>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Access</h2></header>

                <p class="erp-field-label">Roles</p>
                <div class="mb-3">
                    @forelse($user->roles as $r)
                        <a class="erp-chip" href="{{ route('roles.show', $r) }}">{{ $r->name }}</a>
                    @empty
                        <span class="text-body-secondary">No roles assigned.</span>
                    @endforelse
                </div>

                <p class="erp-field-label">Branches</p>
                <div class="mb-3">
                    @if($user->branch_scope === 'all')
                        <span class="erp-chip erp-chip-warn">all branches</span>
                    @elseif($user->branchAssignments->isEmpty())
                        <span class="text-body-secondary">No branch assignments.</span>
                    @else
                        @foreach($user->branchAssignments as $b)
                            <span class="erp-chip">{{ $b->name }}</span>
                        @endforeach
                    @endif
                </div>

                <p class="erp-field-label">Warehouses</p>
                <div>
                    @forelse($user->warehouses as $w)
                        <span class="erp-chip">{{ $w->name }}</span>
                    @empty
                        <span class="text-body-secondary">No direct warehouse assignments.</span>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
