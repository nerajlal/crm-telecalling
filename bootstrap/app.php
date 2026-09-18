<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\OwnerOnly;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => ActiveUser::class, 'owner' => OwnerOnly::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->validateCsrfTokens(except: ['webhooks/exotel/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
