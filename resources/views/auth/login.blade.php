@extends('layouts.guest')

@section('page_title', 'Sign in')

@section('content')
    <h1 class="erp-auth-title">Sign in</h1>
    <p class="erp-auth-sub">Use your work account to continue to {{ config('app.name') }}.</p>

    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">E-mail address</label>
            <input class="form-control @error('email') is-invalid @enderror" type="email" id="email"
                   name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control @error('password') is-invalid @enderror" type="password" id="password"
                   name="password" required autocomplete="current-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label" for="remember">Remember me on this device</label>
        </div>

        <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Sign in
        </button>
    </form>
@endsection
