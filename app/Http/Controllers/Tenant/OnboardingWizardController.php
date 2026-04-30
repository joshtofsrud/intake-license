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
        // TODO Phase 3: validate + save weekly hours.
        // Existing modal::saveHours() has working logic but a known gap —
        // it persists day-of-week without actual times. Fix here; write
        // open_time + close_time to TenantCapacityRule.
        return $this->stepResponse(4, $subdomain, 'services');
    }

    public function saveServices(string $subdomain, Request $request): JsonResponse
    {
        // TODO Phase 3: validate + save first service.
        // Existing modal::saveServices() has the working logic.
        return $this->stepResponse(5, $subdomain, 'team');
    }

    public function saveTeam(string $subdomain, Request $request): JsonResponse
    {
        // TODO Phase 3: solo vs multi. If multi, create additional
        // TenantResource rows with auto-assigned curated colors.
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
     * AI Quick Setup endpoint. Stub for now; wired in Phase 4 with the
     * Claude API call + structured-output parser.
     */
    public function saveAiPrefill(string $subdomain, Request $request): JsonResponse
    {
        return response()->json([
            'ok'    => false,
            'error' => 'AI Quick Setup not yet implemented. Coming in Phase 4.',
        ], 501);
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
