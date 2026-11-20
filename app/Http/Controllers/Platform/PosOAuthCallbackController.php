<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Pos\PosConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * MARKER-POS-CONNECT: the single OAuth return point for every shop (app host).
 * The nonce in `state` is the only thing trusted from the browser; it maps to
 * the shop, staff member and return page saved when Connect was clicked.
 */
class PosOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, PosConnectionService $svc, ?string $adapter = null)
    {
        $pending = $svc->claim((string) $request->query('state', ''));
        // MARKER-POS-CALLBACK: the POS is known from the saved state; a code in the path is only checked when present
        if (! $pending || ($adapter !== null && $pending['code'] !== strtolower($adapter))) {
            return response()->view('platform.pos-connect-expired', [], 410);
        }

        $back = fn (string $key, string $msg) => redirect()->away($pending['return'] . '&' . http_build_query([$key => $msg]));

        if ($request->query('error')) {
            return $back('pos_error', 'The connection was not approved (' . Str::limit((string) $request->query('error'), 60) . ').');
        }

        try {
            $conn = $svc->complete($pending, (string) $request->query('code', ''), $request->query()); // MARKER-POS-CONTEXT
        } catch (Throwable $e) {
            \App\Support\JobFailureReporter::report(self::class, 'POS connect failed', $e, ['adapter' => $pending['code']], $pending['tenant_id']);

            return $back('pos_error', Str::limit($e->getMessage(), 200));
        }

        return $back('pos', $conn->status === 'connected' ? 'connected' : 'checkfailed');
    }
}
