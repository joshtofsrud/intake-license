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
        // Append LogRequests to every web + api request so we capture the
        // full request lifecycle including the terminate() write. Runs last
        // in the stack so it sees the real response status.
        $middleware->append(\App\Http\Middleware\LogRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Route unhandled exceptions into the debug panel.
        // Runs in addition to Laravel's normal logging — doesn't replace it.
        $exceptions->report(function (\Throwable $e) {
            if (app()->bound(\App\Services\DebugLogService::class)) {
                app(\App\Services\DebugLogService::class)->error($e);
            }
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('waitlist:expire')->dailyAt('02:15');
    })
    ->create();
