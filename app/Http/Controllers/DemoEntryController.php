<?php

namespace App\Http\Controllers;

use App\Models\DemoSetting;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * MARKER-DEMO-ENTRY — one link, no account, no password.
 *
 * Two steps on purpose: session cookies are scoped per subdomain, so a sign-in
 * performed on intake.works would not travel to demo.intake.works. /demo just
 * builds a signed hop to /demo/enter on the demo host, which is where the
 * actual login happens.
 */
class DemoEntryController extends Controller
{
    private const SLUG = 'demo';

    /** GET /demo — from the marketing site, an email, anywhere. */
    public function start(Request $request)
    {
        $tenant = $this->tenantOrNull();
        if (! $tenant) {
            return response()->view('platform.demo-unavailable', [
                'reason' => 'The demo is being rebuilt right now.',
            ], 503);
        }
        if (DemoSetting::get('offline:' . self::SLUG) === '1') {
            return response()->view('platform.demo-unavailable', [
                'reason' => DemoSetting::get('offline_reason:' . self::SLUG) ?: 'The demo is temporarily switched off.',
            ], 503);
        }

        // signed + short-lived: the hop is a credential, however brief
        $url = URL::temporarySignedRoute('demo.enter', now()->addMinutes(5), [], false);
        return redirect()->to($this->demoOrigin($tenant) . $url);
    }

    /** GET /demo/enter — runs on the demo host, where the cookie will stick. */
    public function enter(Request $request)
    {
        if (! $request->hasValidSignature(false)) {
            return redirect('/demo');
        }
        $tenant = tenant();
        if (! $tenant || ! $tenant->is_demo) {
            abort(404);
        }
        if (DemoSetting::get('offline:' . self::SLUG) === '1') {
            return response()->view('platform.demo-unavailable', [
                'reason' => DemoSetting::get('offline_reason:' . self::SLUG) ?: 'The demo is temporarily switched off.',
            ], 503);
        }

        $user = TenantUser::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderByRaw("FIELD(role, 'owner', 'manager', 'staff')")
            ->first();
        if (! $user) {
            Log::warning('MARKER-DEMO-ENTRY no staff user in the demo tenant', ['tenant' => $tenant->id]);
            return response()->view('platform.demo-unavailable', ['reason' => 'The demo is being rebuilt right now.'], 503);
        }

        Auth::guard('tenant')->login($user);
        $request->session()->regenerate();
        // a demo visitor must never meet a PIN prompt or a location picker
        $request->session()->put('last_pin_activity_at', now()->toIso8601String());
        $request->session()->put('demo_epoch', (int) DemoSetting::get('epoch:' . self::SLUG, '0'));
        $location = $user->activeLocations()->orderBy('is_default', 'desc')->orderBy('name')->first();
        if ($location) {
            $request->session()->put('current_location_id', $location->id);
        }

        DemoSetting::put('last_entry_at:' . self::SLUG, now()->toIso8601String());
        DemoSetting::put('entries:' . self::SLUG, (string) (((int) DemoSetting::get('entries:' . self::SLUG, '0')) + 1));

        return redirect()->route('tenant.dashboard');
    }

    private function tenantOrNull(): ?Tenant
    {
        return Tenant::where('subdomain', self::SLUG)->where('is_demo', true)->first();
    }

    private function demoOrigin(Tenant $tenant): string
    {
        return 'https://' . $tenant->subdomain . '.' . config('intake.domain');
    }
}
