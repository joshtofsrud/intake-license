<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OnboardingModalController extends Controller
{
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

    public function saveServices(Request $request): JsonResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:100'],
            'item_name'     => ['required', 'string', 'max:100'],
            'price'         => ['nullable', 'numeric', 'min:0'],
        ]);

        $category = TenantServiceCategory::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($data['category_name'])],
            ['name' => $data['category_name'], 'is_active' => true, 'sort_order' => 0]
        );

        $priceCents = !empty($data['price']) ? (int) round($data['price'] * 100) : 0;

        TenantServiceItem::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug($data['item_name'])],
            [
                'category_id'           => $category->id,
                'name'                  => $data['item_name'],
                'price_cents'           => $priceCents,
                'prep_before_minutes'   => 0,
                'duration_minutes'      => 30,
                'cleanup_after_minutes' => 0,
                'slot_weight'           => 1,
                'is_active'             => true,
                'sort_order'            => 0,
            ]
        );

        return $this->progressResponse('services', true);
    }

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

        TenantCapacityRule::where('tenant_id', $tenant->id)
            ->where('rule_type', 'default')
            ->delete();

        if (!empty($data['always_open'])) {
            for ($day = 0; $day <= 6; $day++) {
                TenantCapacityRule::create([
                    'tenant_id'        => $tenant->id,
                    'rule_type'        => 'default',
                    'day_of_week'      => $day,
                    'specific_date'    => null,
                    'max_appointments' => 8,
                    'note'             => null,
                ]);
            }
        } else {
            foreach ($data['hours'] ?? [] as $entry) {
                if (empty($entry['open_time']) || empty($entry['close_time'])) {
                    continue;
                }
                TenantCapacityRule::create([
                    'tenant_id'        => $tenant->id,
                    'rule_type'        => 'default',
                    'day_of_week'      => $entry['day'],
                    'specific_date'    => null,
                    'max_appointments' => 8,
                    'note'             => null,
                ]);
            }
        }

        $saved = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        return $this->progressResponse('hours', $saved);
    }

    public function dismiss(Request $request): JsonResponse
    {
        return response()
            ->json(['dismissed' => true])
            ->withCookie(cookie('onboarding_dismissed_at', (string) now()->timestamp, 60));
    }

    public function complete(Request $request): JsonResponse
    {
        $tenant = tenant();
        $tenant->update([
            'onboarding_status' => 'complete',
            'onboarded_at'      => now(),
        ]);

        return response()->json(['complete' => true, 'redirect' => route('tenant.dashboard')]);
    }

    private function progressResponse(string $step, bool $stepDone): JsonResponse
    {
        $tenant = tenant();

        $brandingDone = !empty($tenant->logo_url)
            || (!empty($tenant->accent_color) && $tenant->accent_color !== '#BEF264')
            || !empty($tenant->tagline);

        $servicesDone = TenantServiceItem::where('tenant_id', $tenant->id)->exists();
        $hoursDone    = TenantCapacityRule::where('tenant_id', $tenant->id)->exists();

        $allDone = $brandingDone && $servicesDone && $hoursDone;

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
