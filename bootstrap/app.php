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

        // Exclude Stripe webhook from CSRF — Stripe signs the request body.
        // Tenant booking webhook (/webhooks/stripe) and addon subscription
        // webhook (/webhooks/stripe/subscriptions) both need this.
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/stripe/*',
            'webhooks/cloudflare', // MARKER-PATCH-118
            'webhooks/ses-bounce',  // MARKER-PATCH-146
            'webhooks/postmark',    // MARKER-PATCH-201
            'webhooks/twilio/inbound', // MARKER-PATCH-221
            'webhooks/stripe-connect', // MARKER-PATCH-172D (Stripe Connect, patch 168)
            'webhooks/stripe-direct/*', // MARKER-PATCH-172D (Direct Payments, patch 170)
            'api/plan-quiz/*',
            'funnel/track', // MARKER-FUNNEL-CSRF — anonymous analytics beacon
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Route unhandled exceptions into the debug panel.
        // Runs in addition to Laravel's normal logging — doesn't replace it.
        $exceptions->report(function (\Throwable $e) {
            if (app()->bound(\App\Services\DebugLogService::class)) {
                app(\App\Services\DebugLogService::class)->error($e);
            }
        });

        // 500 error reference ID (patch #43)
        // Stamp a short reference ID on every 5xx so support can grep logs.
        // Also passes the ID into the 500 view as $errorRefId. The ID is
        // written into the log message via the report() hook above when
        // the exception bubbles up — same ID in both places.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // patch #45c: bail early for exceptions that Laravel handles
            // natively with redirects (auth, validation). Without this, the
            // render hook below would catch AuthenticationException and show
            // a 500 page instead of redirecting to login.
            if ($e instanceof \Illuminate\Auth\AuthenticationException) return null;
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) return null;
            if ($e instanceof \Illuminate\Validation\ValidationException) return null;
            if ($e instanceof \Illuminate\Session\TokenMismatchException) return null;

            // Only intercept 5xx-class errors. Symfony HttpException carries
            // its own status code; other Throwables default to 500.
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status < 500 || $status > 599) {
                return null; // let Laravel render normally (404, 419, etc.)
            }

            $refId = 'ERR-' . strtoupper(\Illuminate\Support\Str::random(8));

            // Surface the ref id in the log line so it can be grepped.
            \Illuminate\Support\Facades\Log::error('500 with refId: ' . $refId, [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'url'       => $request->fullUrl(),
            ]);

            return response()->view('errors.500', [
                'errorRefId' => $refId,
                'exception'  => $e,
            ], 500);
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('waitlist:expire')->dailyAt('02:15');
        $schedule->command('addons:expire')->dailyAt('02:30');
    })
    ->create();
