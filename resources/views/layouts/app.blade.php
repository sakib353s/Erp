<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Foundation\Services\Translator::class)->locale() === 'bn' ? 'bn' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('page_title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="erp-body"
      data-notif-poll="{{ route('notifications.poll') }}"
      data-poll-seconds="{{ (int) config('erp.notifications.poll_seconds', 60) }}">
@php
    $navBuilder = app(\App\Domain\Foundation\Services\NavigationBuilder::class);
    $portal = (string) session('tenant.portal', 'erp');
    $currentPath = '/'.request()->path();
    $sidebarTree = $navBuilder->sidebar(auth()->user(), $portal, $currentPath);
    $utilityEntries = array_values(array_filter(
        $navBuilder->entries('utility', auth()->user(), $portal),
        fn (array $entry) => ! empty($entry['route']),
    ));
    $headerEntries = array_values(array_filter(
        $navBuilder->entries('header', auth()->user(), $portal),
        fn (array $entry) => ! empty($entry['route']),
    ));
    $company = \App\Domain\Foundation\Company::current();
@endphp
<div class="erp-shell">
    @include('partials.sidebar')

    <div class="erp-main">
        @include('partials.topbar')

        <main class="erp-content">
            @include('partials.flash')
            @yield('content')
        </main>

        <footer class="erp-footer">
            <span>{{ config('app.name') }}@if($company) · {{ \Illuminate\Support\Str::limit($company->name, 48) }}@endif</span>
            <span>{{ now()->format('d M Y, h:i A') }} · Asia/Dhaka</span>
        </footer>
    </div>

    <div class="erp-backdrop" data-erp-backdrop hidden></div>
</div>
@stack('scripts')
</body>
</html>
