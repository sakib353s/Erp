@extends('layouts.app')

@section('page_title', 'My profile')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">My profile</h1>
            <p class="erp-page-sub">Your own account details, password and effective access.</p>
        </div>
    </div>

    @if ($mustChange)
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                You must change your password before continuing to use every part of the system.
            </span>
            <a class="btn btn-sm btn-warning" href="#password">Change now</a>
        </div>
    @endif

    @if ($passwordExpired)
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Your password has expired. Set a new one below — the configurable policy is enforced.
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Account details</h2></header>
                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                               value="{{ old('name', $user->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="email">E-mail</label>
                        <input class="form-control" id="email" value="{{ $user->email }}" disabled>
                        <div class="form-text">Your sign-in e-mail cannot be changed from this screen.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone"
                               value="{{ old('phone', $user->phone) }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Save profile
                    </button>
                </form>
            </section>

            <section class="erp-card mt-3" id="password">
                <header class="erp-card-head"><h2 class="erp-card-title">Change password</h2></header>
                @if ($errors->has('password'))
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->get('password') as $passwordError)
                                <li>{{ $passwordError }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <form method="POST" action="{{ route('profile.password') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control @error('current_password') is-invalid @enderror" type="password"
                               id="current_password" name="current_password" required autocomplete="current-password">
                        @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_new">New password</label>
                        <input class="form-control @error('password') is-invalid @enderror" type="password"
                               id="password_new" name="password" required autocomplete="new-password">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Confirm new password</label>
                        <input class="form-control" type="password" id="password_confirmation"
                               name="password_confirmation" required autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-key" aria-hidden="true"></i> Change password
                    </button>
                </form>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Your access</h2></header>

                <p class="erp-field-label">Roles</p>
                <div class="mb-3">
                    @forelse($user->roles as $role)
                        <span class="erp-chip">{{ $role->name }}</span>
                    @empty
                        <span class="text-body-secondary">No roles assigned.</span>
                    @endforelse
                </div>

                <p class="erp-field-label">Branch scope</p>
                <div class="mb-3">
                    @if($user->branch_scope === 'all')
                        <span class="erp-chip erp-chip-warn">all branches</span>
                    @else
                        @forelse($user->branchAssignments as $branch)
                            <span class="erp-chip">{{ $branch->name }}</span>
                        @empty
                            <span class="text-body-secondary">No branch assignments.</span>
                        @endforelse
                    @endif
                </div>

                <p class="erp-field-label">Default branch</p>
                <p>{{ $user->defaultBranch?->name ?: '—' }}</p>

                @if ($user->is_super_admin)
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-star me-1" aria-hidden="true"></i>
                        Super administrator — full access on this instance.
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
