<!DOCTYPE html>
<html lang="en">
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
                <a class="btn btn-primary" href="/">Go to home</a>
                <button class="btn btn-outline-secondary" type="button" onclick="history.back()">Go back</button>
            </div>
            @yield('extra')
        </div>
    </main>
</body>
</html>
