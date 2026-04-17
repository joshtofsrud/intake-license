<?php

namespace App\Http\Middleware;

use App\Services\DebugLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * LogRequests
 *
 * Tracks every HTTP request that reaches the app:
 *   1. Assigns a correlation ID so every log line produced during this
 *      request links together in the debug panel.
 *   2. Binds the request to DebugLogService so errors/audits inherit
 *      method/route/host/ip automatically.
 *   3. On terminate(), writes a 'request' channel row with status + duration.
 *
 * Skipped paths (health, assets) are controlled in config/debug.php.
 * Response time is measured wall-clock with microtime.
 */
class LogRequests
{
    public function __construct(protected DebugLogService $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $correlationId = (string) Str::uuid();
        $this->log->setCorrelationId($correlationId);
        $this->log->bindRequest($request);

        // Pin correlation ID on the request so controllers can surface it in
        // responses (useful for support — "give me the correlation ID from
        // the page footer and I can pull the full trace").
        $request->attributes->set('correlation_id', $correlationId);

        // Start timer. We use microtime rather than LARAVEL_START because
        // LARAVEL_START lives in public/index.php and isn't always defined
        // in tests/CLI contexts.
        $request->attributes->set('_debug_log_start', microtime(true));

        return $next($request);
    }

    /**
     * Fires after the response has been sent to the client. Runs off the
     * request hot path so slow writes don't delay the user.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->shouldSkip($request)) return;

        $start = $request->attributes->get('_debug_log_start');
        if (! $start) return;

        $durationMs = (int) round((microtime(true) - (float) $start) * 1000);

        $this->log->request($request, $response->getStatusCode(), $durationMs);
    }

    protected function shouldSkip(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        foreach ((array) config('debug.request.skip_paths', []) as $skip) {
            if ($path === $skip || str_starts_with($path, rtrim($skip, '/') . '/')) {
                return true;
            }
        }
        return false;
    }
}
