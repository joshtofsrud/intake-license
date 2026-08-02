<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Services\FeatureAccessService;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'license_id', 'subdomain', 'custom_domain', 'plan_tier', 'name',
        'is_active', 'settings',
        'logo_url', 'logo_light_url', 'favicon_url', 'accent_color', 'text_color', 'bg_color',
        'logo_size_admin', 'logo_size_booking',
        'font_heading', 'font_body', 'tagline',
        'site_template', 'design_tokens', // MARKER-PATCH-260
        'email_from_name', 'email_from_address', 'email_reply_to',
        'sms_enabled', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token',
        'twilio_number_sid', // MARKER-PATCH-224
        'direct_payments_enabled', // MARKER-PATCH-169B
        'direct_payments_enabled', // MARKER-PATCH-169B
        'onboarding_status', 'onboarded_at', 'onboarding_step', 'industry_pack',
        'payment_processor', 'payment_processor_status',
        'payment_processor_account_id', 'payment_processor_connected_at',
        'notification_email', 'currency', 'currency_symbol', 'timezone',
        'booking_window_days', 'min_notice_hours', 'booking_mode', 'booking_flow_mode', 'last_booking_mode_switch_at', 'classes_enabled', 'deliveries_enabled', 'multi_asset_enabled',
        'asset_label_singular', 'asset_label_plural', // MARKER-PATCH-215
        'asset_label_singular', 'asset_label_plural', // MARKER-PATCH-215
        'asset_label_singular', 'asset_label_plural', // MARKER-PATCH-215
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_subscription_cadence',
        'trial_ends_at', 'subscription_status',
        // Tax (Path B onboarding)
        'default_tax_rate', 'tax_services_default', 'tax_supports_exempt',
        // Card surcharge (Path 2)
        'passthrough_card_fees', 'card_surcharge_percent', 'card_surcharge_label',
        'surcharge_disclaimer_ack_at',
        // Tips (off by default)
        'tips_enabled', 'tip_default_method', 'tip_default_options',
        'tip_allow_custom', 'tip_attributable',
    ];

    protected $casts = [
        'last_booking_mode_switch_at' => 'datetime',
        'is_active'           => 'boolean',
        'sms_enabled'         => 'boolean',
        'settings'            => 'array',
        'design_tokens'       => 'array', // MARKER-PATCH-260
        'onboarded_at'        => 'datetime',
        'booking_window_days' => 'integer',
        'min_notice_hours'    => 'integer',
        'booking_mode'        => 'string',
        'booking_flow_mode'   => 'string',
        'classes_enabled'     => 'boolean',
        // MARKER-PATCH-169 — Direct Payments bridge toggle
        'direct_payments_enabled' => 'boolean',
        // MARKER-PATCH-168 — Stripe Connect casts
        'stripe_connect_charges_enabled'        => 'boolean',
        'stripe_connect_payouts_enabled'        => 'boolean',
        'stripe_connect_details_submitted_at'   => 'datetime',
        'stripe_connect_requirements_due'       => 'array',
        'stripe_connect_last_synced_at'         => 'datetime',
        'deliveries_enabled'  => 'boolean', // MARKER-PATCH-156
        'multi_asset_enabled' => 'boolean', // MARKER-PATCH-158-B
        'trial_ends_at'       => 'datetime',
        'payment_processor_connected_at' => 'datetime',
        // Logo display heights (px)
        'logo_size_admin'                => 'integer',
        'logo_size_booking'              => 'integer',
        // POS / tax / surcharge / tips
        'default_tax_rate'            => 'decimal:3',
        'tax_services_default'        => 'boolean',
        'tax_supports_exempt'         => 'boolean',
        'passthrough_card_fees'       => 'boolean',
        'card_surcharge_percent'      => 'decimal:2',
        'surcharge_disclaimer_ack_at' => 'datetime',
        'tips_enabled'                => 'boolean',
        'tip_default_options'         => 'array',
        'tip_allow_custom'            => 'boolean',
        'tip_attributable'            => 'boolean',
    ];

    /**
     * IANA timezone for this tenant. Falls back to America/Los_Angeles if
     * the column is empty (older rows pre-migration, or if a tenant clears it).
     */
    public function timezone(): string
    {
        return $this->attributes['timezone'] ?: 'America/Los_Angeles';
    }

    /** "Now" in the tenant's local timezone. */
    public function localNow(): \Carbon\Carbon
    {
        return \Carbon\Carbon::now($this->timezone());
    }

    /** "Today" in the tenant's local timezone, at start-of-day. */
    public function localToday(): \Carbon\Carbon
    {
        return \Carbon\Carbon::today($this->timezone());
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(Tenant\TenantUser::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(Tenant\TenantUser::class)->where('role', 'owner');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Tenant\TenantCustomer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Tenant\TenantAppointment::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(\App\Models\Tenant\TenantResource::class)->orderBy('sort_order');
    }

    public function serviceCategories(): HasMany
    {
        return $this->hasMany(Tenant\TenantServiceCategory::class);
    }

    public function serviceItems(): HasMany
    {
        return $this->hasMany(Tenant\TenantServiceItem::class);
    }

    public function formSections(): HasMany
    {
        return $this->hasMany(Tenant\TenantFormSection::class);
    }

    public function capacityRules(): HasMany
    {
        return $this->hasMany(Tenant\TenantCapacityRule::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Tenant\TenantPage::class);
    }

    public function navItems(): HasMany
    {
        return $this->hasMany(Tenant\TenantNavItem::class)->orderBy('sort_order');
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(Tenant\TenantEmailTemplate::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Tenant\TenantCampaign::class);
    }

    public function supportConversations(): HasMany
    {
        return $this->hasMany(SupportConversation::class);
    }

    /**
     * Is a transactional notification enabled for this tenant?
     * Defaults to ON for booking confirmations + status updates + reminders so
     * shipping a new tenant doesn't accidentally suppress critical comms.
     * Disable explicitly via settings: { "notify_booking_confirmation_email": false }
     */
    public function notificationEnabled(string $key): bool
    {
        $settings = $this->settings ?? [];
        $settingsKey = 'notify_' . $key;
        return (bool) ($settings[$settingsKey] ?? true);
    }

    // MARKER-PATCH-116 — multi-domain support
    /**
     * All domains attached to this tenant.
     */
    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Tenant\TenantDomain::class);
    }

    /**
     * The primary domain, if any. Null when the tenant is subdomain-only.
     */
    public function primaryDomain(): ?\App\Models\Tenant\TenantDomain
    {
        return $this->domains()->primary()->first();
    }

    /**
     * The primary domain's hostname, if any.
     * Falls back to legacy custom_domain column during the transition.
     */
    public function primaryHostname(): ?string
    {
        $primary = $this->primaryDomain();
        if ($primary && $primary->isLive()) {
            return $primary->hostname;
        }
        // Legacy fallback. Removed in a future patch once tenant_domains is canonical.
        return $this->custom_domain ?: null;
    }

    public function publicUrl(): string
    {
        $primary = $this->primaryHostname();
        if ($primary) {
            return 'https://' . $primary;
        }
        return 'https://' . $this->subdomain . '.intake.works';
    }

    public function bookingUrl(): string
    {
        return $this->publicUrl() . '/book';
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_status === 'complete';
    }

    public function emailFromName(): string
    {
        return $this->email_from_name ?: $this->name;
    }

    public function emailFromAddress(): string
    {
        // MARKER-FROM-REPLYABLE — prefer the inbound domain so the From is an
        // address customers can actually write to. The old apex fallback
        // stays last: it keeps sending working if inbound is unconfigured,
        // and it is the address Postmark is already verified for.
        return $this->email_from_address
            ?: ($this->inboundAddress()
            ?: ($this->subdomain . '@intake.works'));
    }

    /**
     * MARKER-TXN-THREADING — this shop's public inbound address.
     *
     * {subdomain}@{inbound domain}, derived from POSTMARK_INBOUND_ADDRESS so
     * there is one place to change the domain. Mail here routes by localpart
     * (shop) plus From (customer) — see PostmarkInboundController's cold path.
     *
     * Null when inbound isn't configured, which keeps every caller on their
     * existing fallback rather than printing an address that receives nothing.
     */
    public function inboundAddress(): ?string
    {
        $base = trim((string) config('services.postmark.inbound_address'));
        if ($base === '' || ! str_contains($base, '@') || empty($this->subdomain)) {
            return null;
        }

        return $this->subdomain . '@' . explode('@', $base, 2)[1];
    }

    /**
     * MARKER-PATCH-411 — the logo shown on the dark header of every email.
     * Single source driving both render paths (EmailService::renderHtml and the
     * emails/layout Blade). Defaults to the light logo (the header is dark),
     * falling back to the main logo, then to null (the text shop name renders).
     */
    public function emailLogoUrl(): ?string
    {
        $choice = $this->settings['email_logo_choice'] ?? 'light';
        return match ($choice) {
            'none'   => null,
            'main'   => $this->logo_url ?: null,
            'custom' => ($this->settings['email_logo_custom_url'] ?? null) ?: null,
            default  => ($this->logo_light_url ?: $this->logo_url) ?: null,
        };
    }

    /**
     * Does this tenant currently have access to the given feature?
     *
     * This is the canonical feature gate. Every controller, view, and job
     * that conditionally enables a feature goes through this method.
     */
    public function hasAddon(string $code): bool
    {
        return app(FeatureAccessService::class)->hasAddon($this, $code);
    }

    /**
     * All currently-accessible addon codes.
     */
    public function activeAddonCodes(): array
    {
        return app(FeatureAccessService::class)->activeAddonCodes($this);
    }

    /**
     * @deprecated Use $tenant->hasAddon('waitlist') instead.
     * Kept for backward compat until all callers are migrated.
     */
    public function hasWaitlistFeature(): bool
    {
        return $this->hasAddon('waitlist');
    }

    /**
     * Relationship: all tenant_feature_addons rows (including expired, for history).
     */
    public function addons(): HasMany
    {
        return $this->hasMany(Tenant\TenantFeatureAddon::class);
    }

    /**
     * Relationship: only currently-active addons.
     */
    public function activeAddons(): HasMany
    {
        return $this->hasMany(Tenant\TenantFeatureAddon::class)
            ->whereIn('status', ['active', 'canceling', 'failed_payment']);
    }

    public function waitlistSettings()
    {
        return $this->hasOne(\App\Models\Tenant\TenantWaitlistSettings::class, 'tenant_id');
    }
    // ─── Capability accessors (delegate to FeatureAccessService) ──────
    // These let blade templates and existing nav code use a simple boolean
    // syntax (e.g. $tenant->retail_enabled) while the resolution still
    // flows through the addons table + tier inclusion logic.

    public function getRetailEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'retail');
    }

    public function getExtendedReportsEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'extended_reports');
    }

    // MARKER-PATCH-567 — online store gate (nav + blades)
    public function getOnlineStoreEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'online_store');
    }

    // MARKER-PATCH-HLC7A — distributor catalog/sync addon gate.
    public function getDistributorSyncEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'bike_distributor_sync');
    }

    // MARKER-PATCH-217 — rentals-stack capability accessors. Same delegation
    // pattern; FeatureAccessService memoizes per request.
    public function getRentalsEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'rentals');
    }

    public function getRentalExtensionsEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'rental_extensions');
    }

    public function getStaffAlertsEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'staff_alerts');
    }

    /**
     * MARKER-PATCH-226 — leasing is a PLAN-TIER capability (floor: Scale),
     * not an addon, and it depends on rentals being active. "Available"
     * means the shop *could* turn it on; "enabled" means they have.
     */
    public function getLeasingAvailableAttribute(): bool
    {
        $rank = ['starter' => 0, 'branded' => 1, 'scale' => 2, 'custom' => 3];
        $tier = $rank[$this->plan_tier ?? 'starter'] ?? 0;
        return $tier >= 2 && $this->rentals_enabled;
    }

    public function getLeasesEnabledAttribute(): bool
    {
        if (!$this->leasing_available) {
            return false;
        }
        return (bool) ($this->settings['leases_enabled'] ?? false);
    }

    /**
     * MARKER-PATCH-228B — rentals visibility toggle. Entitlement (rentals
     * addon) is the hard gate; this is the shop's on/off preference on top.
     * Defaults ON so existing shops are unaffected.
     */
    public function getRentalsVisibleAttribute(): bool
    {
        if (!$this->rentals_enabled) {
            return false;
        }
        return (bool) ($this->settings['rentals_visible'] ?? true);
    }

    public function getUnifiedInboxEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'unified_inbox');
    }

    /**
     * additional_users — does this tenant have the capability to add more
     * than one user? Drives the "Add staff member" button on the staff
     * admin screen, and Layer 1 of the pin_tier_active check below.
     */
    public function getAdditionalUsersEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'additional_users');
    }

    /**
     * pin_tier_active — is the PIN tier authentication flow active for
     * this tenant right now?
     *
     * Two conditions:
     *   1. additional_users capability is on (plan permits multiple users)
     *   2. The tenant actually has 2+ users (otherwise there's no one to
     *      distinguish between)
     *
     * A Branded tenant with one user has the capability but not the active
     * tier — they get the old email/password flow until they add a second
     * staff member, at which point the PIN tier turns on automatically.
     *
     * This is the check that the EnsureTrustedDevice and EnsurePinFresh
     * middleware will use to decide whether to enforce PIN flows. Starter
     * tenants always evaluate to false here.
     */
    public function getPinTierActiveAttribute(): bool
    {
        if (! $this->additional_users_enabled) {
            return false;
        }

        return $this->users()->count() >= 2;
    }

    public function getPosEnabledAttribute(): bool
    {
        return app(\App\Services\FeatureAccessService::class)->hasAddon($this, 'pos');
    }

    /**
     * MARKER-PATCH-162 — multi_location_active
     * True when the tenant can meaningfully operate across locations:
     * retail capability is on AND there are 2+ active locations to move
     * stock between. Used to gate the Transfer Requests UI.
     *
     * Single-location tenants get this as false even with retail on —
     * a transfer-from-nowhere is nonsensical.
     */
    public function getMultiLocationActiveAttribute(): bool
    {
        if (! $this->retail_enabled) {
            return false;
        }
        return $this->locations()->where('is_active', true)->count() >= 2;
    }

    /**
     * MARKER-PATCH-168 — Stripe Connect state.
     *
     * stripe_connect_status returns one of:
     *   not_connected      — no account_id stored
     *   onboarding         — account exists but details not submitted
     *   restricted         — account submitted but charges disabled (requirements due)
     *   live               — charges enabled
     */
    public function getStripeConnectStatusAttribute(): string
    {
        if (! $this->stripe_connect_account_id) return 'not_connected';
        if (! $this->stripe_connect_details_submitted_at) return 'onboarding';
        if (! $this->stripe_connect_charges_enabled) return 'restricted';
        return 'live';
    }

    public function getCardPaymentsEnabledAttribute(): bool
    {
        return $this->stripe_connect_status === 'live';
    }

    /**
     * Application fee in basis points (100 = 1%). Default 0 = pass-through.
     * Used when creating PaymentIntents in Session B.
     */
    public function applicationFeeBps(): int
    {
        return (int) ($this->stripe_application_fee_bps ?? 0);
    }

    public function getMultiLocationEnabledAttribute(): bool
    {
        $svc = app(\App\Services\FeatureAccessService::class);
        return $svc->hasAddon($this, 'multi_location_calendar')
            || $svc->hasAddon($this, 'multi_location_pos');
    }

    // ─── POS + Multi-Location Relationships ───────────────────────────

    public function locations(): HasMany
    {
        return $this->hasMany(Tenant\TenantLocation::class)->orderBy('sort_order');
    }

    public function activeLocations(): HasMany
    {
        return $this->hasMany(Tenant\TenantLocation::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    public function defaultLocation(): HasOne
    {
        return $this->hasOne(Tenant\TenantLocation::class)->where('is_default', true);
    }

    public function inventoryCategories(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryCategory::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryMovement::class);
    }

    public function receiveShipments(): HasMany
    {
        return $this->hasMany(Tenant\TenantInventoryReceiveShipment::class);
    }

    public function distributorCatalogSubscriptions(): HasMany
    {
        return $this->hasMany(Tenant\TenantDistributorCatalogSubscription::class);
    }

}
