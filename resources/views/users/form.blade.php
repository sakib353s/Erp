@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New user' : 'Edit user')

@section('content')
    @php
        $canAllScope = auth()->user()->accessibleBranchIds() === null;
        $actorBranchIds = auth()->user()->accessibleBranchIds();
        $assignedRoleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignedBranchIds = $user->branchAssignments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assignedWarehouseIds = $user->warehouses->pluck('id')->map(fn ($id) => (int) $id)->all();

        $warehouseQuery = \App\Domain\Foundation\Warehouse::query()->where('is_active', true)->orderBy('name');
        if ($actorBranchIds !== null) {
            $warehouseQuery->whereIn('branch_id', $actorBranchIds);
        }
        $allWarehouses = $warehouseQuery->get();
    @endphp

    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New user' : 'Edit: '.$user->name }}</h1>
            <p class="erp-page-sub">Roles, branch scope and status drive every server-side permission check.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Back to users</a>
    </div>

    <form method="POST"
          action="{{ $mode === 'create' ? route('users.store') : route('users.update', $user) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Account details</h2></header>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                                   value="{{ old('name', $user->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">E-mail <span class="text-danger">*</span></label>
                            <input class="form-control @error('email') is-invalid @enderror" type="email" id="email"
                                   name="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone"
                                   value="{{ old('phone', $user->phone) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                            <select class="form-select @error('status') is-invalid @enderror" id="status" name="status">
                                @foreach (['active', 'locked', 'disabled'] as $s)
                                    <option value="{{ $s }}" @selected(old('status', $user->status) === $s)>{{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">
                                {{ $mode === 'create' ? 'Password *' : 'New password (leave blank to keep current)' }}
                            </label>
                            <input class="form-control @error('password') is-invalid @enderror" type="password"
                                   id="password" name="password" autocomplete="new-password"
                                   {{ $mode === 'create' ? 'required' : '' }}>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @if ($mode === 'create')
                            <div class="col-md-6">
                                <label class="form-label" for="password_confirmation">Confirm password</label>
                                <input class="form-control" type="password" id="password_confirmation"
                                       name="password_confirmation" required autocomplete="new-password">
                            </div>
                        @endif
                    </div>
                </section>

                <section class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">Roles <span class="text-danger">*</span></h2></header>
                    @error('roles')<div class="alert alert-danger">{{ $message }}</div>@enderror
                    <div class="erp-check-grid">
                        @forelse($roles as $role)
                            <label class="erp-check-card">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role->id }}"
                                       @checked(in_array($role->id, old('roles', $assignedRoleIds), true) || in_array((string) $role->id, array_map('strval', old('roles', $assignedRoleIds)), false))>
                                <span>
                                    <strong>{{ $role->name }}</strong>
                                    @if($role->is_system)<span class="erp-chip erp-chip-soft">system</span>@endif
                                    <small class="d-block text-body-secondary">{{ $role->description ?: $role->slug }}</small>
                                </span>
                            </label>
                        @empty
                            <p class="text-body-secondary">No roles exist yet — create one first.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Branch scope</h2></header>

                    <div class="mb-3">
                        <label class="erp-radio">
                            <input type="radio" name="branch_scope" value="assigned"
                                   @checked(old('branch_scope', $user->branch_scope) === 'assigned')>
                            <span><strong>Assigned branches</strong>
                                <small class="d-block text-body-secondary">Only branches picked below.</small></span>
                        </label>
                        <label class="erp-radio {{ $canAllScope ? '' : 'erp-radio-locked' }}">
                            <input type="radio" name="branch_scope" value="all" {{ $canAllScope ? '' : 'disabled' }}
                                   @checked(old('branch_scope', $user->branch_scope) === 'all')>
                            <span><strong>All branches</strong>
                                <small class="d-block text-body-secondary">
                                    {{ $canAllScope ? 'Unrestricted company-wide access.' : 'You cannot grant wider access than your own.' }}
                                </small></span>
                        </label>
                        @error('branch_scope')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <label class="form-label" for="default_branch_id">Default branch</label>
                    <select class="form-select mb-3 @error('default_branch_id') is-invalid @enderror" id="default_branch_id"
                            name="default_branch_id">
                        <option value="">— none —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('default_branch_id', $user->default_branch_id) === $b->id)>
                                {{ $b->name }} ({{ $b->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('default_branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror

                    <p class="erp-field-label mt-3">Branch assignments</p>
                    <div class="erp-check-list mb-3">
                        @foreach($branches as $b)
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $b->id }}"
                                       @checked(in_array($b->id, old('branch_ids', $assignedBranchIds)))>
                                <span class="form-check-label">{{ $b->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('branch_ids')<div class="text-danger small">{{ $message }}</div>@enderror

                    <p class="erp-field-label">Warehouse assignments</p>
                    <div class="erp-check-list">
                        @forelse($allWarehouses as $w)
                            <label class="form-check">
                                <input class="form-check-input" type="checkbox" name="warehouse_ids[]" value="{{ $w->id }}"
                                       @checked(in_array($w->id, old('warehouse_ids', $assignedWarehouseIds)))>
                                <span class="form-check-label">{{ $w->name }} <small class="text-body-secondary">({{ $w->branch?->name }})</small></span>
                            </label>
                        @empty
                            <p class="text-body-secondary mb-0">No active warehouses.</p>
                        @endforelse
                    </div>
                    @error('warehouse_ids')<div class="text-danger small">{{ $message }}</div>@enderror
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create user' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ $mode === 'edit' ? route('users.show', $user) : route('users.index') }}">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
