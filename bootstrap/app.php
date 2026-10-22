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
        // ngrok (and similar local tunnels) forward HTTPS traffic to `php artisan serve` from this machine,
        // adding X-Forwarded-Proto/Host. Trusting only loopback lets Laravel generate https://<tunnel>/
        // links, while nobody else can spoof those headers. Add your proxy's IP here if you deploy behind one.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // HitPay posts webhooks without a CSRF token; they're authenticated by HMAC signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/hitpay']);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Signed-in users who hit a guest-only page (e.g. /login) go to their role's home.
        $middleware->redirectUsersTo(fn (Request $request) => LoginController::homeFor($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
