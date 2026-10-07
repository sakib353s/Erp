<!DOCTYPE html>
<html lang="{{ app(\App\Domain\Foundation\Services\Translator::class)->locale() === 'bn' ? 'bn' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('page_title', config('app.name')) · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="erp-body erp-guest">
    <div class="erp-guest-wrap">
        <div class="erp-guest-brand">
            <span class="erp-brand-mark" aria-hidden="true">◆</span>
            <strong>{{ config('app.name') }}</strong>
        </div>

        @include('partials.flash')

        <div class="erp-guest-card">
            @yield('content')
        </div>

        <p class="erp-guest-foot">Bangladesh ERP · {{ now()->year }}</p>
    </div>
</body>
</html>
