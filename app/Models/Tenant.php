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
        'font_heading', 'font_body', 'tagline',
        'email_from_name', 'email_from_address', 'email_reply_to',
        'sms_enabled', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token',
        'onboarding_status', 'onboarded_at', 'onboarding_step', 'industry_pack',
        'payment_processor', 'payment_processor_status',
        'payment_processor_account_id', 'payment_processor_connected_at',
        'notification_email', 'currency', 'currency_symbol', 'timezone',
        'booking_window_days', 'min_notice_hours', 'booking_mode', 'last_booking_mode_switch_at', 'classes_enabled',
        'stripe_customer_id', 'stripe_subscription_id', 'stripe_subscription_cadence',
        'trial_ends_at', 'subscription_status',
    ];

    protected $casts = [
        'last_booking_mode_switch_at' => 'datetime',
        'is_active'           => 'boolean',
        'sms_enabled'         => 'boolean',
        'settings'            => 'array',
        'onboarded_at'        => 'datetime',
        'booking_window_days' => 'integer',
        'min_notice_hours'    => 'integer',
        'booking_mode'        => 'string',
        'classes_enabled'     => 'boolean',
        'trial_ends_at'       => 'datetime',
        'payment_processor_connected_at' => 'datetime',
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

    public function publicUrl(): string
    {
        if ($this->custom_domain) {
            return 'https://' . $this->custom_domain;
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
        return $this->email_from_address
            ?: ($this->subdomain . '@intake.works');
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
