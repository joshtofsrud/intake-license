<?php
// MARKER-PATCH-143

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\TestEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestEmailController extends Controller
{
    public function __construct(protected TestEmailService $tests) {}

    /**
     * POST /admin/settings/email/test
     *
     * Sends a test email using the tenant's currently-saved from-address
     * and reply-to. Optional 'recipient' input overrides the default
     * (current user's email).
     *
     * Permissioned to manager+ to avoid staff spamming themselves.
     */
    // MARKER-PATCH-144 — JSON response for XHR, fallback redirect for non-XHR
    public function sendSettingsTest(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => 'Manager or owner access required.'], 403);
            }
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'recipient' => ['nullable', 'email', 'max:255'],
        ]);

        $recipient = $data['recipient'] ?? $me->email;
        $result = $this->tests->sendSettingsTest(tenant(), $recipient);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($result, $result['ok'] ? 200 : 500);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
