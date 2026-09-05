<?php
// MARKER-PATCH-129

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\PinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * AccountController
 *
 * Surfaces a person's own account. Same template as TeamController's
 * person-detail view, but the form actions point at routes that act
 * on the signed-in user (no id in the URL) and the writeable fields
 * are constrained to what self-service is allowed to change.
 */
class AccountController extends Controller
{
    public function __construct(
        protected PinService $pins,
    ) {}

    public function index(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        return view('tenant.account.index', ['me' => $me]);
    }

    public function updateName(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate(['name' => ['required','string','max:255']]);
        $me->update(['name' => $data['name']]);
        return back()->with('success', 'Name updated.');
    }

    // MARKER-TIMECLOCK-EXEMPT — self-serve: owners and salaried staff turn
    // off their own clock-in nudge (Team redirects self-edits here).
    public function updateTimeclockExempt(Request $request)
    {
        $me = Auth::guard('tenant')->user();

        // MARKER-TC-EXEMPT-CAP — a hidden card is not a gate. Both surfaces
        // that offer this post here, so this is the one place to stop it.
        abort_unless($me->can('timeclock.exempt_self'), 403);

        $exempt = (bool) $request->boolean('exempt_from_timeclock');
        $me->update(['exempt_from_timeclock' => $exempt]);

        return back()->with('success', $exempt
            ? "You won't be prompted to clock in anymore."
            : 'Clock-in prompts are back on.');
    }

    public function updatePassword(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate([
            'current_password' => ['required','string'],
            'new_password'     => ['required','string','min:10','confirmed'],
        ]);
        if (! Hash::check($data['current_password'], $me->password)) {
            return back()->withErrors(['current_password' => 'Wrong password.']);
        }
        $me->update(['password' => Hash::make($data['new_password'])]);
        return back()->with('success', 'Password updated.');
    }

    public function setPin(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $data = $request->validate([
            'pin'              => ['required','string','regex:/^\d{4}$/'],
            'pin_confirm'      => ['required','string','same:pin'],
            'current_password' => ['required','string'],
        ]);
        if (! Hash::check($data['current_password'], $me->password)) {
            return back()->withErrors(['current_password' => 'Wrong password.']);
        }
        $this->pins->setPin($me, $data['pin']);
        return back()->with('success', 'PIN saved.');
    }

    public function clearPin(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        $this->pins->forceReset($me, $me);
        return back()->with('success', 'PIN cleared. You will be prompted to set a new one next time.');
    }

    // MARKER-PATCH-130 — per-user device methods removed; devices are tenant-scoped.
}
