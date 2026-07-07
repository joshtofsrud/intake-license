<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantFormSection;
use App\Models\Tenant\TenantReceivingMethod;
use App\Models\Tenant\TenantServiceCategory;

/**
 * BookingFormData — single source of truth for the data the public booking
 * form needs to render (catalog, form sections, receiving methods, payment
 * config, resources, and the $bk display-settings array).
 *
 * MARKER-PATCH-599 — extracted verbatim from BookingController@index so that
 * both the dedicated /book route AND the booking_embed page-builder section
 * render from the same prep. Request-specific flow routing (?flow=, showFork,
 * which view) stays in the controller; this returns pure data only.
 */
class BookingFormData
{
    /**
     * Build the full booking-form view payload for a tenant.
     *
     * @return array<string,mixed> keys: catalog, formSections, receivingMethods,
     *         stripeEnabled, paypalEnabled, stripePublishableKey, paypalClientId,
     *         bookingMode, resources, bk
     */
    public static function for($tenant): array
    {
        $catalog = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)->where('quick_only', false)->orderBy('sort_order') // MARKER-PATCH-546 — quick-only services never appear in the full flow
                  ->with(['serviceAddons' => function ($sa) { $sa->orderBy('sort_order')->with('addon'); }]);
            }])
            ->get();

        $formSections = TenantFormSection::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->with(['fields' => function ($q) { $q->orderBy('sort_order'); }])
            ->get();

        $receivingMethods = TenantReceivingMethod::where('tenant_id', $tenant->id)
            ->where('is_active', true)->orderBy('sort_order')->get();

        $s = $tenant->settings ?? [];
        // MARKER-PATCH-385 — booking deposits run on Direct Payments now, so the
        // publishable key must match the account that creates the PaymentIntent
        // in submit(). Same keys as the register.
        $direct = new \App\Services\Tenant\DirectPaymentsService($tenant);
        $stripeEnabled = $direct->isEnabled();
        $stripePublishableKey = $stripeEnabled ? ($direct->publishableKey() ?? '') : '';
        $paypalEnabled = !empty($s['paypal_enabled']) && !empty($s['paypal_test_client_id'] ?? $s['paypal_live_client_id'] ?? '');
        $paypalClientId = '';
        if ($paypalEnabled) {
            $mode = $s['paypal_mode'] ?? 'sandbox';
            $paypalClientId = $mode === 'live' ? ($s['paypal_live_client_id'] ?? '') : ($s['paypal_test_client_id'] ?? '');
        }

        $bookingMode = $tenant->booking_mode ?? 'drop_off';

        $resources = $tenant->resources()->where('is_active', true)->get([
            'id', 'name', 'subtitle', 'color_hex', 'sort_order',
        ])->map(fn($r) => [
            'id'        => $r->id,
            'name'      => $r->name,
            'subtitle'  => $r->subtitle,
            'color_hex' => $r->color_hex,
        ])->values()->all();

        $bk = [
            'theme'          => $s['booking_theme'] ?? 'light',
            'accent'         => $s['booking_accent'] ?? '',
            'bg_tint'        => $s['booking_bg_tint'] ?? '#FFFFFF',
            'bg_opacity'     => $s['booking_bg_opacity'] ?? '100',
            'progress_bg'    => $s['booking_progress_bg'] ?? '',
            'progress_text'  => $s['booking_progress_text'] ?? '#000000',
            'body_text'      => $s['booking_body_text'] ?? '',
            'show_nav'       => $s['booking_show_nav'] ?? ($s['booking_show_chrome'] ?? '1'),    // MARKER-PATCH-589
            'show_footer'    => $s['booking_show_footer'] ?? ($s['booking_show_chrome'] ?? '1'), // MARKER-PATCH-589
            'hide_cta'       => ($s['booking_hide_cta'] ?? '0') === '1', // MARKER-PATCH-590
            'show_logo'      => $s['booking_show_logo'] ?? '1',                                   // MARKER-PATCH-589
            'step1_label'    => $s['booking_step1_label'] ?? 'Services',
            'step2_label'    => $s['booking_step2_label'] ?? 'Schedule',
            'step3_label'    => $s['booking_step3_label'] ?? 'Details',
            'step4_label'    => $s['booking_step4_label'] ?? 'Review',
            'step1_heading'  => $s['booking_step1_heading'] ?? 'What do you need serviced?',
            'step2_heading'  => $s['booking_step2_heading'] ?? 'Pick a drop-off date',
            'step3_heading'  => $s['booking_step3_heading'] ?? 'Your details',
            'step4_heading'  => $s['booking_step4_heading'] ?? 'Review your order',
            'step1_sub'      => $s['booking_step1_sub'] ?? 'Select one or more services.',
            'step2_sub'      => $s['booking_step2_sub'] ?? 'Choose a date and tell us how you\'re dropping off.',
            'step3_sub'      => $s['booking_step3_sub'] ?? 'Who you are and anything we need to know.',
            'step4_sub'      => $s['booking_step4_sub'] ?? 'Confirm everything looks good.',
        ];

        // MARKER-PATCH-603 — marketing sections live on the real Booking page,
        // split around the booking_embed pivot by drag order.
        $bookingSections = self::bookingPageSections($tenant);

        return compact(
            'catalog', 'formSections', 'receivingMethods',
            'stripeEnabled', 'paypalEnabled', 'stripePublishableKey', 'paypalClientId',
            'bookingMode', 'resources', 'bk', 'bookingSections'
        );
    }

    /**
     * MARKER-PATCH-603 — the Booking page: a real page in the builder (slug
     * "book") holding marketing sections around a booking_embed pivot. The
     * public /book route renders pre-pivot sections, the live form, then
     * post-pivot sections. Created lazily with the pivot seeded.
     */
    public static function bookingPage($tenant)
    {
        $page = \App\Models\Tenant\TenantPage::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'book'],
            ['title' => 'Booking page', 'is_home' => false, 'is_published' => true, 'is_in_nav' => false, 'nav_order' => 99]
        );

        // Seed the pivot once so the builder always shows where the form sits.
        // MARKER-PATCH-606 — if the page pre-existed (adopted at slug "book"),
        // heal it: the pivot must exist AND be visible or the split breaks.
        $pivot = $page->sections()->where('section_type', 'booking_embed')->first();
        if (! $pivot) {
            $page->sections()->create([
                'tenant_id'    => $tenant->id,
                'section_type' => 'booking_embed',
                'content'      => ['heading' => 'Book online', 'is_pivot' => true],
                'is_visible'   => true,
                'sort_order'   => 50,
            ]);
        } elseif (! $pivot->is_visible) {
            $pivot->update(['is_visible' => true]);
        }

        return $page;
    }

    /**
     * Marketing sections split around the booking_embed pivot.
     * Returns ['before' => Collection, 'after' => Collection].
     */
    public static function bookingPageSections($tenant): array
    {
        $sections = self::bookingPage($tenant)->sections()->orderBy('sort_order')->get();
        $pivotIdx = $sections->search(fn($s) => $s->section_type === 'booking_embed');
        if ($pivotIdx === false) {
            return ['before' => $sections->filter(fn($s) => $s->is_visible)->values(), 'after' => collect()];
        }
        return [
            'before' => $sections->slice(0, $pivotIdx)->filter(fn($s) => $s->is_visible)->values(),
            'after'  => $sections->slice($pivotIdx + 1)->filter(fn($s) => $s->is_visible)->values(),
        ];
    }
}

