<?php

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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'device.auth' => \App\Http\Middleware\AuthenticateDevice::class,
        ]);

        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\AuthenticateDevice::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
