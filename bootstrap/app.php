<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Signed-in users who hit a guest-only page (e.g. /login) go to their role's home.
        $middleware->redirectUsersTo(fn (Request $request) => LoginController::homeFor($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
