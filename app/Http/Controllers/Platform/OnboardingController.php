<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function index()
    {
        return redirect()->route('marketing.home');
    }

    public function login()
    {
        return view('platform.login');
    }

    public function signup(Request $request)
    {
        return view('platform.signup', [
            'plan'       => $request->query('plan', 'starter'),
            'planPrices' => config('intake.plan_prices'),
        ]);
    }

    public function processSignup(Request $request)
    {
        $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'shop_name' => ['required', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:32'],
            'subdomain' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', 'unique:tenants,subdomain'],
            'email'     => ['required', 'email', 'max:255'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            'plan'      => ['required', 'in:starter,branded,scale'],
        ]);

        $reserved = config('intake.reserved_subdomains', []);
        if (in_array($request->input('subdomain'), $reserved)) {
            return back()->withInput()->withErrors(['subdomain' => 'That subdomain is reserved.']);
        }

        $tenant = Tenant::create([
            'name'                => $request->input('shop_name'),
            'subdomain'           => $request->input('subdomain'),
            'plan_tier'           => $request->input('plan'),
            'onboarding_status'   => 'pending',
            'currency'            => 'USD',
            'currency_symbol'     => '$',
            'accent_color'        => '#BEF264',
            'booking_window_days' => 60,
            'min_notice_hours'    => 24,
            'booking_mode'        => 'drop_off',
            'settings'            => ['onboarding_step' => 'branding', 'admin_theme' => 'a'],
        ]);

        $user = TenantUser::create([
            'tenant_id'  => $tenant->id,
            'name'       => $request->input('name'),
            'email'      => strtolower($request->input('email')),
            'phone'      => $request->input('phone'),
            'password'   => Hash::make($request->input('password')),
            'role'       => 'owner',
            'is_active'  => true,
        ]);

        // One-time token so signup auto-logs in across subdomains
        $token = Str::random(40);
        Cache::put(
            'onboarding_token_' . $token,
            ['user_id' => $user->id, 'tenant_id' => $tenant->id],
            now()->addMinutes(5)
        );

        $tenantUrl = 'https://' . $tenant->subdomain . '.' . config('intake.domain')
            . '/admin?token=' . $token;

        return redirect($tenantUrl);
    }

    public function checkSubdomain(Request $request)
    {
        $slug     = strtolower(trim($request->input('subdomain', '')));
        $reserved = config('intake.reserved_subdomains', []);

        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/', $slug)) {
            return response()->json(['available' => false, 'reason' => 'invalid']);
        }
        if (in_array($slug, $reserved)) {
            return response()->json(['available' => false, 'reason' => 'reserved']);
        }
        $taken = Tenant::where('subdomain', $slug)->exists();
        return response()->json(['available' => !$taken, 'reason' => $taken ? 'taken' : null]);
    }
}
