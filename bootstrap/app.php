<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Http\Middleware\EnsureEntitlement;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\GuardSetup;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            CorrelationId::class,
            SecurityHeaders::class,
            EnforceSessionPolicy::class,
        ]);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'permission' => CheckPermission::class,
            'portal' => EnsurePortalAccess::class,
            'feature' => EnsureEntitlement::class,
            'setup.complete' => EnsureSetupComplete::class,
            'setup.open' => GuardSetup::class,
        ]);

        /*
         * Authorization must run BEFORE route-model binding so a caller
         * without the permission always gets 403 (never a 404 that leaks
         * whether the id exists). SubstituteBindings is in Laravel's
         * default priority list; our gate is not — pin it ahead.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: CheckPermission::class,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // AJAX/JSON callers always receive structured JSON (never raw framework output).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Safe error rendering (spec section C / §Error Handling):
         * - full-page navigation gets polished HTML error views;
         * - JSON callers get {ok:false, error:{code, message, correlation_id}};
         * - stack traces, SQL, secrets and file paths are NEVER rendered —
         *   they are reported to the log channels only.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            // Framework-native outcomes: guests must be redirected to /login
            // (401 JSON for API callers) and ValidationException must
            // redirect back WITH flashed errors + old input. Intercepting
            // either here would break sign-in and every form in the app.
            if ($e instanceof AuthenticationException
                || $e instanceof ValidationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            // Normalise lookups/policy denials so a single branch below
            // renders them (404/403), never a generic 500.
            if ($e instanceof ModelNotFoundException) {
                $e = new NotFoundHttpException(previous: $e);
            } elseif ($e instanceof AuthorizationException) {
                $e = new HttpException(403, '', $e);
            }

            $correlationId = (string) ($request->attributes->get('correlation_id')
                ?? ($e->getTraceAsString() ? $request->headers->get('X-Request-Id') : null)
                ?? '-');

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() !== '' ? $e->getMessage() : null;

                $message ??= match (true) {
                    $status === 401 => 'Your session has expired. Please sign in again.',
                    $status === 403 => 'You do not have permission to perform this action.',
                    $status === 404 => 'The page you requested was not found.',
                    $status === 419 => 'Your session expired while loading the page. Please refresh and try again.',
                    $status === 429 => 'Too many requests. Please wait a moment and try again.',
                    default => 'The request could not be completed.',
                };

                // Only trust messages raised by our own abort() calls (never DB/framework text).
                if (preg_match('/SQLSTATE|SQL syntax|Exception thrown|Stack trace|storage\/|vendor\//i', $message)) {
                    $message = 'The request could not be completed.';
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'ok' => false,
                        'error' => [
                            'code' => 'http_'.$status,
                            'message' => $message,
                            'correlation_id' => $correlationId,
                        ],
                    ], $status);
                }

                $view = view()->exists("errors.$status")
                    ? "errors.$status"
                    : 'errors.500';

                return response()->view($view, ['message' => $message], $status);
            }

            // Non-HTTP exceptions: report via log, render generic output only.
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => [
                        'code' => 'server_error',
                        'message' => 'An unexpected error occurred. The incident has been logged.',
                        'correlation_id' => $correlationId,
                    ],
                ], 500);
            }

            return response()->view('errors.500', [
                'message' => 'An unexpected error occurred. The incident has been logged.',
                'correlation_id' => $correlationId,
            ], 500);
        });
    })->create();
