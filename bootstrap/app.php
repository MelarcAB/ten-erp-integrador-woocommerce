<?php

use App\Http\Middleware\ApiAuthenticationMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Cookie;
use App\Http\Middleware\ViewLogs;
use Illuminate\Auth\AuthenticationException;    
use Illuminate\Http\Request;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'viewLogs' => ViewLogs::class,
            ''
        ]);
        $middleware->api(prepend: [
          //  ApiAuthenticationMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            $response = [
                'error'   => true,
                'content' => __('auth.unauthenticated'),
            ];
            // Normalmente se usaría 401 para "Unauthenticated" pero se puede dejar a 403
            return response()->json($response, 401);
        });
    })->create();
