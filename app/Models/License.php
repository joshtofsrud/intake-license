<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class License extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id',
        'license_key',
        'tier',
        'status',
        'site_limit',
        'feature_flags',
        'saas_access',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'feature_flags' => 'array',
        'saas_access'   => 'boolean',
        'expires_at'    => 'datetime',
        'site_limit'    => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(Activation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }

    public function tenant(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Generate a fresh, unique license key.
     * Format: INTK-XXXX-XXXX-XXXX-XXXX (prefix + 4 groups of 4 hex chars)
     */
    public static function generateKey(): string
    {
        do {
            $key = 'INTK-'
                . implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
        } while (static::where('license_key', $key)->exists());

        return $key;
    }

    /**
     * Is this license currently valid for plugin use?
     */
    public function isValid(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * How many active sites has this license been activated on?
     */
    public function activeActivationCount(): int
    {
        return $this->activations()->count();
    }

    /**
     * Can this license accept another site activation?
     */
    public function canActivate(): bool
    {
        return $this->isValid()
            && $this->activeActivationCount() < $this->site_limit;
    }

    /**
     * Check whether a feature flag is enabled.
     * Falls back to tier defaults if not explicitly set.
     */
    public function hasFeature(string $flag): bool
    {
        $flags = $this->feature_flags ?? [];

        if (array_key_exists($flag, $flags)) {
            return (bool) $flags[$flag];
        }

        // Tier defaults
        $defaults = [
            'premium' => [
                'updates'     => true,
                'saas_access' => false,
                'white_label' => false,
            ],
        ];

        return (bool) ($defaults[$this->tier][$flag] ?? false);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }
}
