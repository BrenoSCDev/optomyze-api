<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\VerifyApiToken;
use App\Http\Middleware\VerifyManagerKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {
        // $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Prod is only reachable through the platform's own TLS-terminating
        // reverse proxy, which forwards plain HTTP internally. Without
        // trusting it, Laravel can't tell the original request was HTTPS
        // (e.g. for signed-URL validation, which reconstructs the request's
        // URL including scheme to recompute the signature).
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'verify.api.token'    => VerifyApiToken::class,
            'permission'          => CheckPermission::class,
            'verify.manager.key'  => VerifyManagerKey::class,
            'log.api.requests'    => LogApiRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stash the throwable on the request so LogApiRequest::terminate()
        // can attach exception details to the api_request_logs entry.
        $exceptions->reportable(function (\Throwable $e) {
            if ($request = request()) {
                $request->attributes->set('__api_log_exception', $e);
            }
        });
    })->create();