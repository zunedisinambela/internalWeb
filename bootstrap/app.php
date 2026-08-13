<?php

use App\Http\Middleware\RecordVisit;
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
        // Records page views into visits_monitoring. This only covers the
        // `web` group; the Filament panel builds its own stack and is wired
        // separately in AdminPanelProvider. What gets recorded is decided by
        // App\Monitoring\PageViewsOnly.
        $middleware->web(append: [
            RecordVisit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
