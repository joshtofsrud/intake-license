<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 8-step onboarding wizard. Replaces the 3-step OnboardingModalController
 * for new tenants. Per-step submit: each save writes to DB, bumps
 * tenant.onboarding_step, and returns JSON so the frontend can advance
 * client-side without a full page reload.
 *
 * Step order (locked):
 *   1. Industry  - tag for analytics + progressive disclosure
 *   2. Identity  - name, tagline, logo, brand color
 *   3. Booking   - time-slot vs drop-off, classes toggle
 *   4. Hours     - weekly hours per day, single block per day
 *   5. Services  - first service(s) for booking page
 *   6. Team      - solo vs multi, names + auto-assigned colors
 *   7. Payment   - processor intent (stripe / paypal / square / offline)
 *   8. Done      - review + booking URL + jobs-to-be-done CTAs
 *
 * AI Quick Setup: a special POST to saveAiPrefill() runs Claude API,
 * writes prefilled data across steps 3-6, and lands the user on step 3
 * (Booking) for review. Stub for now; wired in Phase 4.
 */
class OnboardingWizardController extends Controller
{
    private const TOTAL_STEPS = 8;

    /** GET routes */

    public function showIndustry(string $subdomain): View
    {
        return $this->render('industry', 1);
    }

    public function showIdentity(string $subdomain): View
    {
        return $this->render('identity', 2);
    }

    public function showBooking(string $subdomain): View
    {
        return $this->render('booking', 3);
    }

    public function showHours(string $subdomain): View
    {
        return $this->render('hours', 4);
    }

    public function showServices(string $subdomain): View
    {
        return $this->render('services', 5);
    }

    public function showTeam(string $subdomain): View
    {
        return $this->render('team', 6);
    }

    public function showPayment(string $subdomain): View
    {
        return $this->render('payment', 7);
    }

    public function showDone(string $subdomain): View
    {
        return $this->render('done', 8);
    }

    /** POST routes */

    public function saveIndustry(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'industry_pack' => ['required', 'string', 'max:64'],
        ]);
        tenant()->update([
            'industry_pack'   => $data['industry_pack'],
            'onboarding_step' => max(2, tenant()->onboarding_step ?? 0),
        ]);
        return $this->stepResponse(1, $subdomain, 'identity');
    }

    public function saveIdentity(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'tagline'      => ['nullable', 'string', 'max:255'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo'         => ['nullable', 'image', 'max:2048'],
        ]);

        $update = [
            'name'         => $data['name'],
            'tagline'      => $data['tagline'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
        ];

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('tenant-logos', 'public');
            $update['logo_url'] = \Illuminate\Support\Facades\Storage::url($path);
        }

        $update['onboarding_step'] = max(3, tenant()->onboarding_step ?? 0);
        tenant()->update($update);

        return $this->stepResponse(2, $subdomain, 'booking');
    }

    public function saveBooking(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_mode'    => ['required', 'in:time_slot,drop_off'],
            'classes_enabled' => ['required', 'boolean'],
        ]);
        tenant()->update([
            'booking_mode'    => $data['booking_mode'],
            'classes_enabled' => $data['classes_enabled'],
            'onboarding_step' => max(4, tenant()->onboarding_step ?? 0),
        ]);
        return $this->stepResponse(3, $subdomain, 'hours');
    }

    public function saveHours(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours'                => ['required', 'array', 'min:7', 'max:7'],
            'hours.*.day'          => ['required', 'integer', 'between:0,6'],
            'hours.*.open_time'    => ['nullable', 'string', 'date_format:H:i'],
            'hours.*.close_time'   => ['nullable', 'string', 'date_format:H:i'],
            'hours.*.closed'       => ['nullable'],
        ]);

        $tenantId = tenant()->id;

        // Idempotent: wipe the default-rule rows and recreate from the
        // submitted state. Single source of truth per save.
        \DB::transaction(function () use ($tenantId, $data) {
            \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenantId)
                ->where('rule_type', 'default')
                ->whereNull('specific_date')
                ->delete();

            foreach ($data['hours'] as $entry) {
                $isClosed = !empty($entry['closed']);
                \App\Models\Tenant\TenantCapacityRule::create([
                    'tenant_id'             => $tenantId,
                    'rule_type'             => 'default',
                    'day_of_week'           => $entry['day'],
                    'specific_date'         => null,
                    'is_closed'             => $isClosed,
                    'open_time'             => $isClosed ? null : ($entry['open_time']  ?? null),
                    'close_time'            => $isClosed ? null : ($entry['close_time'] ?? null),
                    'max_appointments'      => 8,  // sensible default; capacity tuning happens later
                    'slot_interval_minutes' => 30,
                    'note'                  => null,
                ]);
            }
        });

        tenant()->update([
            'onboarding_step' => max(5, tenant()->onboarding_step ?? 0),
        ]);

        return $this->stepResponse(4, $subdomain, 'services');
    }

    public function saveServices(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'duration' => ['required', 'integer', 'min:5', 'max:480'],
            'price'    => ['required', 'numeric', 'min:0'],
        ]);

        $tenantId = tenant()->id;

        // Default category — most tenants only need one bucket. Power users
        // organize from the Services admin later.
        $category = \App\Models\Tenant\TenantServiceCategory::firstOrCreate(
            ['tenant_id' => $tenantId, 'slug' => 'services'],
            ['name' => 'Services', 'is_active' => true, 'sort_order' => 0]
        );

        \App\Models\Tenant\TenantServiceItem::create([
            'tenant_id'             => $tenantId,
            'category_id'           => $category->id,
            'name'                  => $data['name'],
            'slug'                  => \Illuminate\Support\Str::slug($data['name']) . '-' . substr(md5(uniqid()), 0, 6),
            'price_cents'           => (int) round($data['price'] * 100),
            'duration_minutes'      => $data['duration'],
            'prep_before_minutes'   => 0,
            'cleanup_after_minutes' => 0,
            'slot_weight'           => 1,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);

        tenant()->update([
            'onboarding_step' => max(6, tenant()->onboarding_step ?? 0),
        ]);

        return $this->stepResponse(5, $subdomain, 'team');
    }

    public function saveTeam(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode'                 => ['required', 'in:solo,multi'],
            'members'              => ['nullable', 'array', 'max:25'],
            'members.*.name'       => ['required_with:members', 'string', 'max:100'],
            'members.*.subtitle'   => ['nullable', 'string', 'max:100'],
            'members.*.color_hex'  => ['required_with:members', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $tenantId = tenant()->id;

        \DB::transaction(function () use ($tenantId, $data) {
            // Wipe non-owner resources; owner is identified by a non-null
            // staff_user_id (set by TenantUserObserver at signup) and never
            // touched by this method.
            \App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                ->whereNull('staff_user_id')
                ->delete();

            if ($data['mode'] === 'multi' && !empty($data['members'])) {
                $sortStart = (int) (\App\Models\Tenant\TenantResource::where('tenant_id', $tenantId)
                    ->max('sort_order') ?? 0) + 1;

                foreach ($data['members'] as $i => $m) {
                    \App\Models\Tenant\TenantResource::create([
                        'tenant_id'                => $tenantId,
                        'name'                     => $m['name'],
                        'subtitle'                 => $m['subtitle'] ?? null,
                        'color_hex'                => strtoupper($m['color_hex']),
                        'type'                     => 'staff',
                        'staff_user_id'            => null,
                        'sort_order'               => $sortStart + $i,
                        'is_active'                => true,
                        'max_appointments_per_day' => null,
                    ]);
                }
            }
        });

        tenant()->update([
            'onboarding_step' => max(7, tenant()->onboarding_step ?? 0),
        ]);

        return $this->stepResponse(6, $subdomain, 'payment');
    }

    public function savePayment(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_processor' => ['required', 'in:stripe,paypal,square,offline'],
        ]);
        $isOffline = $data['payment_processor'] === 'offline';
        tenant()->update([
            'payment_processor'        => $data['payment_processor'],
            'payment_processor_status' => $isOffline ? 'not_started' : 'intent_recorded',
            'onboarding_step'          => max(8, tenant()->onboarding_step ?? 0),
        ]);
        return $this->stepResponse(7, $subdomain, 'done');
    }

    public function complete(string $subdomain, Request $request): JsonResponse
    {
        tenant()->update([
            'onboarding_status' => 'complete',
            'onboarded_at'      => now(),
            'onboarding_step'   => null,
        ]);
        return response()->json([
            'ok'       => true,
            'complete' => true,
            'redirect' => route('tenant.dashboard', ['subdomain' => $subdomain]),
        ]);
    }

    /**
     * AI Quick Setup endpoint. Takes a free-text business description, calls
     * Claude with the industry context, and writes prefilled state across
     * steps 3-6. Lands the user on Booking (step 3) for review since that's
     * the most consequential AI-decided choice.
     */
    public function saveAiPrefill(string $subdomain, Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $service = app(\App\Services\Tenant\OnboardingAiQuickSetupService::class);
            $prefill = $service->run(tenant(), $data['description']);
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('AI Quick Setup failed', [
                'tenant_id' => tenant()->id,
                'message'   => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Something went wrong setting up. Try a different description, or set things up manually.',
            ], 500);
        }

        return response()->json([
            'ok'       => true,
            'prefill'  => [
                'booking_mode'    => $prefill['booking_mode'],
                'classes_enabled' => $prefill['classes_enabled'],
                'service_count'   => count($prefill['services']),
            ],
            'next_url' => route('tenant.onboarding.wizard.booking', ['subdomain' => $subdomain]),
        ]);
    }

    /** Helpers */

    private function render(string $step, int $stepNumber): View
    {
        return view("tenant.onboarding.{$step}", [
            'currentStep' => $stepNumber,
            'totalSteps'  => self::TOTAL_STEPS,
            'tenant'      => tenant(),
        ]);
    }

    private function stepResponse(int $savedStep, string $subdomain, string $nextStep): JsonResponse
    {
        return response()->json([
            'ok'           => true,
            'saved_step'   => $savedStep,
            'current_step' => tenant()->fresh()->onboarding_step,
            'next_url'     => route("tenant.onboarding.wizard.{$nextStep}", ['subdomain' => $subdomain]),
        ]);
    }
}
