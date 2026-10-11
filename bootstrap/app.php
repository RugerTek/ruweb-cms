<?php

use App\Exceptions\RevisionConflict;
use App\Http\Middleware\ApplyRedirects;
use App\Http\Middleware\AuthenticateMcpApiKey;
use App\Http\Middleware\EnforceMcpScopes;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetPublicLocale;
use App\Support\RedirectResolver;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));

            Route::prefix('mcp')
                ->middleware('mcp')
                ->group(base_path('routes/mcp.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('mcp', [
            AuthenticateMcpApiKey::class,
            EnforceMcpScopes::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'admin.locale' => SetAdminLocale::class,
            'public.locale' => SetPublicLocale::class,
            'redirects' => ApplyRedirects::class,
            'mcp.auth' => AuthenticateMcpApiKey::class,
        ]);
        $middleware->redirectGuestsTo(fn () => '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RevisionConflict $exception, Request $request) {
            if ($request->expectsJson() || $request->is('mcp/*')) {
                return response()->json(['message' => $exception->getMessage(), 'error' => $exception->diagnostic()], 409);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return response()->view('errors.404', [], 404);
            }

            return null;
        });

        // A path that matches no route: apply a redirect rule if one exists, otherwise count the 404
        // so it shows up in Admin > Redirects. See docs/operations/redirects.md.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            // Automated discovery probes get a bare answer: no site render, no monitor row.
            if ($request->is('.well-known/*')) {
                return response()->json(['message' => 'Not Found'], 404);
            }
            if ($redirect = RedirectResolver::resolve($request)) {
                return $redirect;
            }

            RedirectResolver::recordNotFound($request);

            return null;
        });
    })->create();
