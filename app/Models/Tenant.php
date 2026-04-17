<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_id', 'subdomain', 'custom_domain', 'plan_tier', 'name',
        'is_active', 'settings',
        'logo_url', 'logo_light_url', 'favicon_url', 'accent_color', 'text_color', 'bg_color',
        'font_heading', 'font_body', 'tagline',
        'email_from_name', 'email_from_address', 'email_reply_to',
        'sms_enabled', 'sms_from_number', 'twilio_account_sid', 'twilio_auth_token',
        'onboarding_status', 'onboarded_at',
        'notification_email', 'currency', 'currency_symbol',
        'booking_window_days', 'min_notice_hours', 'booking_mode',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'sms_enabled'         => 'boolean',
        'settings'            => 'array',
        'onboarded_at'        => 'datetime',
        'booking_window_days' => 'integer',
        'min_notice_hours'    => 'integer',
        'booking_mode'        => 'string',
    ];

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

    public function serviceCategories(): HasMany
    {
        return $this->hasMany(Tenant\TenantServiceCategory::class);
    }

    public function serviceTiers(): HasMany
    {
        return $this->hasMany(Tenant\TenantServiceTier::class);
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
}
