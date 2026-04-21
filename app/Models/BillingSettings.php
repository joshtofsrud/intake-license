<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BillingSettings — single-row table holding Intake's Stripe credentials.
 *
 * Always accessed via BillingSettings::current() which returns the single
 * row (creates on first call if somehow missing).
 *
 * Secret keys are encrypted at rest via Laravel's `encrypted` cast.
 */
class BillingSettings extends Model
{
    protected $fillable = [
        'stripe_test_publishable_key',
        'stripe_test_secret_key',
        'stripe_test_webhook_secret',
        'stripe_live_publishable_key',
        'stripe_live_secret_key',
        'stripe_live_webhook_secret',
        'stripe_mode',
        'stripe_price_starter_monthly',
        'stripe_price_starter_annual',
        'stripe_price_branded_monthly',
        'stripe_price_branded_annual',
        'stripe_price_scale_monthly',
        'stripe_price_scale_annual',
        'last_verified_at',
        'last_verified_status',
        'last_verified_message',
    ];

    protected $casts = [
        // All 6 key columns encrypted via APP_KEY
        'stripe_test_publishable_key' => 'encrypted',
        'stripe_test_secret_key' => 'encrypted',
        'stripe_test_webhook_secret' => 'encrypted',
        'stripe_live_publishable_key' => 'encrypted',
        'stripe_live_secret_key' => 'encrypted',
        'stripe_live_webhook_secret' => 'encrypted',
        'last_verified_at' => 'datetime',
    ];

    /**
     * Get the single billing settings record.
     * Creates it on first call if it doesn't exist.
     */
    public static function current(): self
    {
        $row = self::find(1);
        if (! $row) {
            $row = self::create(['id' => 1, 'stripe_mode' => 'test']);
        }
        return $row;
    }

    /**
     * Returns whether we're in test or live mode.
     */
    public function isLive(): bool
    {
        return $this->stripe_mode === 'live';
    }

    /**
     * Get the active secret key based on current mode.
     * Returns null if not configured.
     */
    public function activeSecretKey(): ?string
    {
        return $this->isLive()
            ? $this->stripe_live_secret_key
            : $this->stripe_test_secret_key;
    }

    /**
     * Get the active publishable key based on current mode.
     */
    public function activePublishableKey(): ?string
    {
        return $this->isLive()
            ? $this->stripe_live_publishable_key
            : $this->stripe_test_publishable_key;
    }

    /**
     * Get the active webhook secret based on current mode.
     */
    public function activeWebhookSecret(): ?string
    {
        return $this->isLive()
            ? $this->stripe_live_webhook_secret
            : $this->stripe_test_webhook_secret;
    }

    /**
     * Lookup a Stripe price ID by tier + cadence.
     * $tier = starter|branded|scale, $cadence = monthly|annual
     */
    public function priceIdFor(string $tier, string $cadence): ?string
    {
        $key = "stripe_price_{$tier}_{$cadence}";
        return $this->$key ?? null;
    }

    /**
     * Is this billing configuration complete enough to process payments?
     */
    public function isConfigured(): bool
    {
        return filled($this->activeSecretKey())
            && filled($this->activePublishableKey());
    }

    /**
     * Mask a key for display (shows last 4 chars only).
     * Returns '(not set)' if empty.
     */
    public function maskedKey(string $attribute): string
    {
        $value = $this->$attribute;
        if (! $value) return '(not set)';
        $len = strlen($value);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($value, 0, 7) . str_repeat('•', 5) . substr($value, -4);
    }
}
