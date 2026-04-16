<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
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
            'plan'       => $request->query('plan', 'basic'),
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
            'plan'      => ['required', 'in:basic,branded,custom'],
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
            'settings'            => ['admin_theme' => 'a'],
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

        // Seed a basic home page so public URL doesn't 404
        $this->seedHomePage($tenant);

        // One-time token so signup auto-logs in across subdomains
        $token = Str::random(40);
        Cache::put(
            'onboarding_token_' . $token,
            ['user_id' => $user->id, 'tenant_id' => $tenant->id],
            now()->addMinutes(5)
        );

        // Drop them straight on the dashboard — onboarding runs as a modal there
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

    public function checkout(Request $request)
    {
        return redirect()->route('marketing.pricing');
    }

    /**
     * Seed a minimal home page so the public tenant URL renders something
     * even before the owner has customized anything. They edit it later
     * from the Pages section.
     */
    private function seedHomePage(Tenant $tenant): void
    {
        if (TenantPage::where('tenant_id', $tenant->id)->where('is_home', true)->exists()) {
            return;
        }

        $page = TenantPage::create([
            'tenant_id'    => $tenant->id,
            'title'        => 'Home',
            'slug'         => 'home',
            'is_home'      => true,
            'is_published' => true,
            'is_in_nav'    => false,
            'nav_order'    => 0,
        ]);

        $accent = $tenant->accent_color ?: '#BEF264';
        $accentText = \App\Support\ColorHelper::accentTextColor($accent);

        $defaults = [
            'nav' => [
                'show_logo' => true,
                'cta_label' => 'Book Now',
                'cta_url'   => '/book',
                'bg_style'  => 'solid',
            ],
            'hero' => [
                'headline'            => $tenant->name,
                'subheading'          => $tenant->tagline ?: 'Professional service you can count on. Book your appointment online in seconds.',
                'bg_color'            => '#111111',
                'text_color'          => '#ffffff',
                'cta_primary_label'   => 'Book an Appointment',
                'cta_primary_url'     => '/book',
                'cta_secondary_label' => 'View Services',
                'cta_secondary_url'   => '#services',
                'height'              => 'medium',
            ],
            'services' => [
                'heading'     => 'What we offer',
                'subheading'  => 'Browse our services and book online.',
                'show_prices' => true,
                'columns'     => 3,
            ],
            'cta_banner' => [
                'headline'   => 'Ready to get started?',
                'subheading' => 'Book your appointment today — it only takes a minute.',
                'cta_label'  => 'Book Now',
                'cta_url'    => '/book',
                'bg_color'   => $accent,
                'text_color' => $accentText,
            ],
            'footer' => [
                'show_logo'      => true,
                'copyright_text' => '',
            ],
        ];

        $order = 0;
        foreach ($defaults as $type => $content) {
            TenantPageSection::create([
                'page_id'      => $page->id,
                'tenant_id'    => $tenant->id,
                'section_type' => $type,
                'content'      => $content,
                'padding'      => 'normal',
                'is_visible'   => true,
                'sort_order'   => $order++,
            ]);
        }
    }
}
