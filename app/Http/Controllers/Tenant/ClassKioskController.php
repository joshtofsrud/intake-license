<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\ClassKiosk;
use Illuminate\Http\Request;

/** Self check-in on a studio tablet (public, gated by the studio's private link). MARKER-CLASSES-P10 */
class ClassKioskController extends Controller
{
    public function index(string $token)
    {
        $tenant = $this->gate($token);
        return view('public.classes.kiosk', ['screen' => 'classes', 'token' => $token, 'sessions' => ClassKiosk::sessions($tenant)]);
    }

    public function session(string $token, string $sessionId)
    {
        $tenant  = $this->gate($token);
        $session = ClassKiosk::open($tenant, $sessionId);
        if (! $session) {
            return redirect()->route('tenant.customer.classes.kiosk', ['token' => $token]);
        }
        return view('public.classes.kiosk', ['screen' => 'people', 'token' => $token, 'session' => $session, 'people' => ClassKiosk::people($tenant, $session)]);
    }

    public function in(Request $request, string $token, string $sessionId, string $registrationId)
    {
        $tenant  = $this->gate($token);
        $session = ClassKiosk::open($tenant, $sessionId);
        if (! $session) {
            return redirect()->route('tenant.customer.classes.kiosk', ['token' => $token]);
        }
        $r = ClassKiosk::checkIn($tenant, $session, $registrationId,
            $request->input('waiver_name'), $request->boolean('waiver_agree'), $request->ip());

        return view('public.classes.kiosk', ['screen' => $r['state'], 'token' => $token, 'session' => $session,
            'name' => $r['name'], 'waiver' => $r['waiver'] ?? null, 'registrationId' => $registrationId,
            'tried' => $request->has('waiver_name')]);
    }

    private function gate(string $token)
    {
        $tenant = tenant();
        abort_unless(ClassKiosk::valid($tenant, $token), 404);
        return $tenant;
    }
}
