<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only backend (the login page lives in the Vue SPA).
        // Laravel's default redirects unauthenticated guests to route("login"),
        // which does not exist here and crashes with "Route [login] not
        // defined". Returning null makes auth throw AuthenticationException so
        // the JSON 401 renderer below handles it instead.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is an API-only backend (the login page lives in the Vue SPA), so
        // unauthenticated API requests must return JSON 401 instead of Laravel's
        // default redirect to the non-existent "login" route (which 500s).
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
