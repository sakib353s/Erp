<?php

namespace App\Http\Middleware;

use App\Domain\Foundation\Services\Translator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * §16-50 — make the rest of the framework speak the reader's language.
 *
 * The `Translator` decides the UI language (session choice → settings → company →
 * English). Laravel's own translation system — validation messages, the auth
 * screens, date formatting — reads `app()->getLocale()`, which the framework
 * otherwise leaves at the app default ('en'). Without this middleware the box on
 * the screen says "বিক্রয়" while the form that rejects a bad input underneath it
 * says "The :attribute field is required", and a reader who chose Bangla is told
 * so in English. So, once per request, we point the framework's locale at the
 * same language the UI is in.
 *
 * Order matters: this runs before the validation and rendering, and it is cheap
 * (one memoised read). It never throws — a reader with no valid language simply
 * gets the application default, which is exactly Laravel's own fallback.
 */
class SetUiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = app(Translator::class)->locale();

        App::setLocale($locale);

        return $next($request);
    }
}
