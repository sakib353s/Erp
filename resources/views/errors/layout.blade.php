<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Foundation\Services\Translator::class)->locale() === 'bn' ? 'bn' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code', 'Error') · {{ config('app.name', 'BD ERP') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="erp-body erp-error-body">
    <main class="erp-error-wrap">
        <div class="erp-error-card">
            <p class="erp-error-code">@yield('code', 'Error')</p>
            <h1 class="erp-error-title">@yield('title', 'Something went wrong')</h1>
            <p class="erp-error-text">@yield('text', 'Please try again.')</p>
            <div class="erp-error-actions">
                <a class="btn btn-primary" href="{{ auth()->check() ? route('dashboard') : url('/') }}">
                    <i class="bi bi-house" aria-hidden="true"></i>
                    {{ auth()->check() ? 'Back to dashboard' : 'Go to sign in' }}
                </a>
                <button class="btn btn-outline-secondary" type="button" onclick="history.back()">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Go back
                </button>
            </div>
            @yield('extra')
        </div>
    </main>
</body>
</html>
