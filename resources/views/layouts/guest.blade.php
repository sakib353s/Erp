@php
    /* Guest shell — the same design system as the app, never a second theme
       (§18.1). Left: what this workspace is. Right: the single task. */
    $accent = (string) app(\App\Domain\Settings\Services\SettingService::class)->get('appearance', 'accent', 'teal');
    $company = \App\Domain\Foundation\Company::current();
@endphp
<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Foundation\Services\Translator::class)->locale() === 'bn' ? 'bn' : 'en' }}" data-accent="{{ $accent }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d1117">
    <title>@yield('page_title', 'Sign in') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="erp-body erp-guest">
    <div class="erp-guest-wrap">
        <aside class="erp-guest-aside">
            <div class="erp-guest-brand" style="color:#fff">
                <span class="erp-brand-mark erp-brand-mark-inverse" aria-hidden="true">{{ strtoupper(mb_substr(config('app.name'), 0, 1)) }}E</span>
                <strong>{{ config('app.name') }}</strong>
            </div>

            <div>
                <h2>Run sales, stock and accounts from one ledger.</h2>
                <p>Bangladesh-first ERP: branch scope, Mushak documents, POS, courier settlement and a double-entry core that ties every number back to a source document.</p>

                <ul class="erp-guest-points">
                    <li><i class="bi bi-shield-check" aria-hidden="true"></i> Permission-filtered navigation — you only see what you may open</li>
                    <li><i class="bi bi-journal-check" aria-hidden="true"></i> Append-only audit trail on every posting</li>
                    <li><i class="bi bi-box-seam" aria-hidden="true"></i> Stock truth from immutable movements, never edited balances</li>
                </ul>
            </div>

            <p class="erp-guest-foot" style="color:#8296a8;text-align:left">
                {{ $company?->name ?? 'One company per instance' }} · Asia/Dhaka
            </p>
        </aside>

        <main class="erp-guest-main">
            @include('partials.flash')

            <div class="erp-guest-card">
                @yield('content')
            </div>

            <p class="erp-guest-foot">
                {{ config('app.name') }} · {{ now()->year }}
                <span class="d-block">Need help? Contact your workspace administrator.</span>
            </p>
        </main>
    </div>

    <div class="erp-toast-stack" data-erp-toasts role="status" aria-live="polite"></div>
</body>
</html>
