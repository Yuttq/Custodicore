<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 'role:<Role Name>[,<Role Name>...]' — see App\Http\Middleware\
        // EnsureRoleAccess. Used alongside 'auth' on each staff dashboard's
        // route group in routes/web.php.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRoleAccess::class,
            // 'reauth' — the signed-in staff member must re-enter THEIR OWN
            // password (current_password field) before a change to someone
            // else's details goes through. See ConfirmActorPassword.
            'reauth' => \App\Http\Middleware\ConfirmActorPassword::class,
        ]);

        // Send a not-logged-in visitor to /login instead of Laravel's
        // stock '/login' assumption erroring out — same route name, just
        // explicit, and resolved lazily via closure since routes/web.php
        // hasn't been loaded yet when withMiddleware() runs.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
