<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAbandonedBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AbandonedBookingController extends Controller
{
    /** Upsert a partial booking for the current session. */
    public function store(Request $request)
    {
        $tenant = tenant();
        if (!$tenant) {
            return response()->json(['ok' => false], 404);
        }

        $key = 'abandon:' . $request->ip() . ':' . $tenant->id;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['ok' => false, 'reason' => 'rate_limited'], 429);
        }
        RateLimiter::hit($key, 60);

        $sessionId = $request->cookie('fnl_sid');
        if (!$sessionId) {
            return response()->json(['ok' => true, 'skipped' => 'no_session']);
        }

        $data = $request->validate([
            'name'         => ['nullable', 'string', 'max:191'],
            'email'        => ['nullable', 'email', 'max:191'],
            'phone'        => ['nullable', 'string', 'max:32'],
            'step_reached' => ['nullable', 'string', 'max:64'],
            'partial'      => ['nullable', 'array'],
            'completed'    => ['nullable', 'boolean'],
        ]);

        // Booking finished -> clear any abandoned row, nothing to recover.
        if (!empty($data['completed'])) {
            TenantAbandonedBooking::where('tenant_id', $tenant->id)
                ->where('session_id', $sessionId)
                ->delete();
            return response()->json(['ok' => true, 'cleared' => true]);
        }

        // Only worth a row if we actually have a way to reach them.
        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json(['ok' => true, 'skipped' => 'no_contact']);
        }

        TenantAbandonedBooking::updateOrCreate(
            ['tenant_id' => $tenant->id, 'session_id' => $sessionId],
            [
                'name'         => $data['name'] ?? null,
                'email'        => $data['email'] ?? null,
                'phone'        => $data['phone'] ?? null,
                'step_reached' => $data['step_reached'] ?? null,
                'partial'      => $data['partial'] ?? null,
                'status'       => 'open',
            ]
        );

        return response()->json(['ok' => true]);
    }
}
