@php
    /* ---------------------------------------------------------------------
     | AppShell (docs/ARCHITECTURE.md §18.2)
     |
     | One implementation, all modes (§18.4). The shell owns: appearance
     | tokens, the navigation rail, breadcrumbs, the ⌘K palette, toasts and
     | the page-state contract containers. It never owns page markup — pages
     | compose the primitives (PageHeader, KpiCard, DataTable, EmptyState…).
     --------------------------------------------------------------------- */
    $navBuilder = app(\App\Domain\Foundation\Services\NavigationBuilder::class);
    $settings = app(\App\Domain\Settings\Services\SettingService::class);
    $portal = (string) session('tenant.portal', 'erp');
    $currentPath = '/'.request()->path();

    $user = auth()->user();
    $sidebarSections = $navBuilder->sidebar($user, $portal, $currentPath);
    $trail = $navBuilder->trail($currentPath, $user, $portal);
    $palette = $navBuilder->palette($user, $portal);
    $pinTargetId = $navBuilder->currentMenuItemId($currentPath, $user, $portal);

    $utilityEntries = array_values(array_filter(
        $navBuilder->entries('utility', $user, $portal),
        fn (array $entry) => ! empty($entry['route']),
    ));
    $headerEntries = array_values(array_filter(
        $navBuilder->entries('header', $user, $portal),
        fn (array $entry) => ! empty($entry['route']),
    ));

    $company = \App\Domain\Foundation\Company::current();

    // Appearance: company default from settings, per-user override in localStorage.
    $accent = (string) $settings->get('appearance', 'accent', 'teal');
    $density = (string) $settings->get('appearance', 'density', 'comfortable');
    $theme = (string) $settings->get('appearance', 'theme', 'light');
    $railDefault = (bool) $settings->get('appearance', 'sidebar_rail', false);
@endphp
<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Foundation\Services\Translator::class)->locale() === 'bn' ? 'bn' : 'en' }}"
      data-accent="{{ $accent }}"
      data-density="{{ $density }}"
      @if($theme === 'dark') data-theme="dark" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <title>@yield('page_title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="erp-body"
      data-notif-poll="{{ route('notifications.poll') }}"
      data-poll-seconds="{{ (int) config('erp.notifications.poll_seconds', 60) }}"
      @yield('body_attrs')>
<a class="erp-skip-link" href="#erpContent">Skip to main content</a>

<div class="erp-shell" data-rail="{{ $railDefault ? 'collapsed' : 'expanded' }}">
    @include('partials.sidebar')

    <div class="erp-main">
        @include('partials.topbar')

        <main class="erp-content @yield('content_class')" id="erpContent" tabindex="-1">
            @include('partials.flash')
            @yield('content')
        </main>

        <footer class="erp-footer">
            <span>{{ config('app.name') }}@if($company) · {{ \Illuminate\Support\Str::limit($company->name, 48) }}@endif</span>
            <span class="erp-footer-meta">
                <span>{{ now()->format('d M Y, h:i A') }} · Asia/Dhaka</span>
                <span class="d-none d-md-inline">Press <kbd>⌘</kbd><kbd>K</kbd> to jump anywhere</span>
            </span>
        </footer>
    </div>

    <div class="erp-backdrop" data-erp-backdrop hidden></div>
</div>

@include('partials.command-palette', ['entries' => $palette])

<div class="erp-toast-stack" data-erp-toasts role="status" aria-live="polite"></div>

<script>
    window.erpNavIndex = @json($palette, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
</script>
@stack('scripts')
</body>
</html>
