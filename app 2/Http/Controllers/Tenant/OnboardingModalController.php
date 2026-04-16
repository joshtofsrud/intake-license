<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantItemTierPrice;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantServiceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * OnboardingModalController
 *
 * Endpoints for the in-dashboard onboarding modal. Each endpoint:
 *   - Accepts a JSON/form POST
 *   - Saves the step's data
 *   - Returns JSON with updated progress state
 *
 * The modal opens on the dashboard until the tenant has satisfied all three
 * "required" checks: branding, services, hours. These are soft checks —
 * the tenant can dismiss the modal any time via POST /admin/onboarding/dismiss,
 * which sets a cookie for the session. Progress itself is derived from the
 * actual data in the database, never from the cookie, so dismissing doesn't
 * hide or fake completion.
 */
class OnboardingModalController extends Controller
{
    /**
     * POST /admin/onboarding/branding
     * Save tenant branding (name, tagline, accent color, logo)
     */
    public function saveBranding(Request $request): JsonResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'tagline'      => ['nullable', 'string', 'max:255'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'         => ['nullable', 'image', 'max:2048'],
        ]);

        $update = array_filter([
            'name'         => $data['name'] ?? null,
            'tagline'      => $data['tagline'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
        ], fn ($v) => $v !== null);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('tenant-logos', 'public');
            $update['logo_url'] = Storage::url($path);
        }

        if (!empty($update)) {
            $tenant->update($update);
        }

        return $this->progressResponse('branding', true);
    }

    /**
     * POST /admin/onboarding/services
     * Create a service tier + category + item + price.
     * Accepts sensible defaults — any missing fields are auto-filled.
     */
    public function saveServices(Request $request): JsonResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'tier_name'     => ['required', 'string', 'max:100'],
            'category_name' => ['required', 'string', 'max:100'],
            'item_name'     => ['required', 'string', 'max:100'],
            'price'         => ['nullable', 'numeric', 'min:0'],
        ]);

        // Tier
        $tier = TenantServiceTier::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($data['tier_name'])],
            ['name' => $data['tier_name'], 'is_active' => true, 'sort_order' => 0]
        );

        // Category
        $category = TenantServiceCategory::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($data['category_name'])],
            ['name' => $data['category_name'], 'is_active' => true, 'sort_order' => 0]
        );

        // Item
        $item = TenantServiceItem::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($data['item_name'])],
            [
                'category_id' => $category->id,
                'name'        => $data['item_name'],
                'is_active'   => true,
                'sort_order'  => 0,
            ]
        );

        // Price (if provided)
        if (!empty($data['price'])) {
            TenantItemTierPrice::updateOrCreate(
                ['item_id' => $item->id, 'tier_id' => $tier->id],
                [
                    'tenant_id'   => $tenant->id,
                    'price_cents' => (int) round($data['price'] * 100),
                ]
            );
        }

        return $this->progressResponse('services', true);
    }

    /**
     * POST /admin/onboarding/hours
     * Create capacity rules for the tenant.
     *
     * Two modes:
     *   - always_open = true — single rule covering all days, always open
     *   - weekly_hours — array of day => [open, close]
     */
    public function saveHours(Request $request): JsonResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'always_open'        => ['sometimes', 'boolean'],
            'hours'              => ['sometimes', 'array'],
            'hours.*.day'        => ['required_with:hours', 'integer', 'between:0,6'],
            'hours.*.open_time'  => ['nullable', 'string'],
            'hours.*.close_time' => ['nullable', 'string'],
        ]);

        // Nuke any existing 'default' capacity rules first so this step is
        // idempotent (leave 'override' rules alone in case staff added them)
        TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->delete();

        if (!empty($data['always_open'])) {
            // Single "always open" rule covering every day of the week
            for ($day = 0; $day <= 6; $day++) {
                TenantCapacityRule::create([
                    'tenant_id'             => $tenant->id,
                    'rule_type'             => 'default',
                    'day_of_week'           => $day,
                    'open_time'             => '00:00:00',
                    'close_time'            => '23:59:59',
                    'max_appointments'      => 0, // 0 = unlimited in this schema
                    'slot_interval_minutes' => 60,
                ]);
            }
        } else {
            foreach ($data['hours'] ?? [] as $entry) {
                if (empty($entry['open_time']) || empty($entry['close_time'])) {
                    continue;
                }
                TenantCapacityRule::create([
                    'tenant_id'             => $tenant->id,
                    'rule_type'             => 'default',
                    'day_of_week'           => $entry['day'],
                    'open_time'             => $entry['open_time'],
                    'close_time'            => $entry['close_time'],
                    'max_appointments'      => 0,
                    'slot_interval_minutes' => 60,
                ]);
            }
        }

        // If nothing got saved (empty form), don't mark done
        $saved = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        return $this->progressResponse('hours', $saved);
    }

    /**
     * POST /admin/onboarding/dismiss
     * User clicked "Skip for now" — set a cookie to hide the modal for
     * this session. Progress is not marked done; modal reopens on next login.
     */
    public function dismiss(Request $request): JsonResponse
    {
        return response()
            ->json(['dismissed' => true])
            ->withCookie(cookie('onboarding_dismissed_at', (string) now()->timestamp, 60));
    }

    /**
     * POST /admin/onboarding/complete
     * User finished all steps — mark the tenant complete + set cookie.
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = tenant();
        $tenant->update([
            'onboarding_status' => 'complete',
            'onboarded_at'      => now(),
        ]);

        return response()->json(['complete' => true, 'redirect' => route('tenant.dashboard')]);
    }

    /**
     * Standard JSON response shape for step saves.
     */
    private function progressResponse(string $step, bool $stepDone): JsonResponse
    {
        $tenant = tenant();

        // Recompute full progress every time — DB is source of truth
        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();
        $hoursDone    = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        $allDone = $brandingDone && $servicesDone && $hoursDone;

        // Auto-mark tenant complete if all three are satisfied
        if ($allDone && $tenant->onboarding_status !== 'complete') {
            $tenant->update([
                'onboarding_status' => 'complete',
                'onboarded_at'      => now(),
            ]);
        }

        return response()->json([
            'ok'       => true,
            'step'     => $step,
            'progress' => [
                'branding' => $brandingDone,
                'services' => $servicesDone,
                'hours'    => $hoursDone,
                'all_done' => $allDone,
            ],
        ]);
    }
}
