<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceTier;
use App\Models\Tenant\TenantItemTierPrice;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    private const STEPS = ['branding', 'services', 'complete'];

    public function index(Request $request)
    {
        $tenant = tenant();

        // Already completed
        if ($tenant->isOnboarded()) {
            return redirect()->route('tenant.dashboard');
        }

        // Skip services step
        if ($request->query('skip_services') && ($tenant->settings['onboarding_step'] ?? '') === 'services') {
            $settings = $tenant->settings ?? [];
            $settings['onboarding_step'] = 'complete';
            $tenant->update([
                'settings'          => $settings,
                'onboarding_status' => 'complete',
                'onboarded_at'      => now(),
            ]);
            $this->seedHomePage($tenant);
        }

        $step = $tenant->fresh()->settings['onboarding_step'] ?? 'branding';
        return view('tenant.onboarding.index', compact('step'));
    }

    public function branding()
    {
        return view('tenant.onboarding.index', ['step' => 'branding']);
    }

    public function saveBranding(Request $request)
    {
        $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'tagline'      => ['nullable', 'string', 'max:255'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $tenant = tenant();
        $data   = $request->only(['name', 'tagline', 'accent_color']);

        if ($request->hasFile('logo')) {
            $request->validate(['logo' => ['image', 'max:2048']]);
            $path = $request->file('logo')->store('tenant-logos', 'public');
            $data['logo_url'] = \Illuminate\Support\Facades\Storage::url($path);
        }

        $settings = $tenant->settings ?? [];
        $settings['onboarding_step'] = 'services';
        $data['settings'] = $settings;

        $tenant->update($data);

        return redirect()->route('tenant.onboarding.index');
    }

    public function services()
    {
        return view('tenant.onboarding.index', ['step' => 'services']);
    }

    public function saveServices(Request $request)
    {
        $request->validate([
            'tier_name'     => ['required', 'string', 'max:100'],
            'category_name' => ['required', 'string', 'max:100'],
            'item_name'     => ['required', 'string', 'max:100'],
            'price'         => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenant = tenant();

        // Create tier
        $tier = TenantServiceTier::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($request->input('tier_name'))],
            ['name' => $request->input('tier_name'), 'is_active' => true, 'sort_order' => 0]
        );

        // Create category
        $cat = TenantServiceCategory::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($request->input('category_name'))],
            ['name' => $request->input('category_name'), 'is_active' => true, 'sort_order' => 0]
        );

        // Create item
        $item = TenantServiceItem::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($request->input('item_name'))],
            ['category_id' => $cat->id, 'name' => $request->input('item_name'), 'is_active' => true, 'sort_order' => 0]
        );

        // Create price
        if ($request->filled('price')) {
            TenantItemTierPrice::updateOrCreate(
                ['item_id' => $item->id, 'tier_id' => $tier->id],
                ['tenant_id' => $tenant->id, 'price_cents' => (int) round($request->input('price') * 100)]
            );
        }

        // Seed home page if it doesn't exist yet
        if (! TenantPage::where('tenant_id', $tenant->id)->where('is_home', true)->exists()) {
            $this->seedHomePage($tenant);
        }

        // Mark complete
        $settings = $tenant->settings ?? [];
        $settings['onboarding_step'] = 'complete';
        $tenant->update([
            'settings'          => $settings,
            'onboarding_status' => 'complete',
            'onboarded_at'      => now(),
        ]);

        return redirect()->route('tenant.onboarding.index');
    }

    public function complete()
    {
        return view('tenant.onboarding.index', ['step' => 'complete']);
    }

    private function seedHomePage($tenant): void
    {
        $page = TenantPage::create([
            'tenant_id'    => $tenant->id,
            'title'        => 'Home',
            'slug'         => 'home',
            'is_home'      => true,
            'is_published' => true,
            'is_in_nav'    => false,
            'nav_order'    => 0,
        ]);

        $defaults = [
            'nav'       => ['show_logo' => true, 'cta_label' => 'Book Now', 'cta_url' => '/book', 'bg_style' => 'solid'],
            'hero'      => ['headline' => $tenant->name, 'subheading' => $tenant->tagline ?? 'Book online today.', 'bg_color' => '#1a1a1a', 'text_color' => '#ffffff', 'cta_primary_label' => 'Book Now', 'cta_primary_url' => '/book', 'height' => 'large'],
            'services'  => ['heading' => 'Our services', 'show_prices' => true, 'columns' => 3],
            'cta_banner'=> ['headline' => 'Ready to book?', 'cta_label' => 'Schedule now', 'cta_url' => '/book'],
            'footer'    => ['show_logo' => true, 'copyright_text' => ''],
        ];

        foreach ($defaults as $i => $typeData) {
            [$type, $content] = [array_keys($defaults)[$i] ?? $i, $typeData];
            // $type is actually the key because we used string keys
        }

        // Re-iterate correctly
        $i = 0;
        foreach ($defaults as $type => $content) {
            TenantPageSection::create([
                'page_id'      => $page->id,
                'tenant_id'    => $tenant->id,
                'section_type' => $type,
                'content'      => $content,
                'padding'      => 'normal',
                'is_visible'   => true,
                'sort_order'   => $i++,
            ]);
        }
    }
}
