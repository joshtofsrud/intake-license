<?php

namespace App\Models\Tenant;

// MARKER-REGISTER-RECON-DISPLAY

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantRegister extends Model
{
    protected $table = 'tenant_registers';

    protected $fillable = [
        'tenant_id', 'location_id', 'number', 'name',
        'display_token', 'display_logo', 'display_cart', 'cart_updated_at', 'is_active',
        // MARKER-RENTAL-WAIVER-DISPLAY — persistent override, see the migration.
        'display_mode', 'display_rental_id', 'display_mode_at', 'display_sign_nonce',
    ];

    protected $casts = [
        'display_cart'    => 'array',
        'cart_updated_at' => 'datetime',
        'is_active'       => 'boolean',
        'display_mode_at' => 'datetime', // MARKER-RENTAL-WAIVER-DISPLAY
    ];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function location(): BelongsTo { return $this->belongsTo(TenantLocation::class, 'location_id'); }

    /**
     * MARKER-RENTAL-WAIVER-DISPLAY — is a waiver currently owning this screen?
     *
     * The 30 minute ceiling is a stranded-screen guard, not a signing deadline:
     * if a customer wanders off mid-waiver the tablet returns to the idle
     * greeting on its own rather than sitting on someone else's agreement.
     */
    public function agreementIsLive(): bool
    {
        return $this->display_mode === 'agreement'
            && $this->display_rental_id !== null
            && $this->display_mode_at !== null
            && $this->display_mode_at->gt(now()->subMinutes(30));
    }

    /** Drop the override and return the screen to normal cart mirroring. */
    public function clearDisplayMode(): void
    {
        $this->update([
            'display_mode'       => null,
            'display_rental_id'  => null,
            'display_mode_at'    => null,
            'display_sign_nonce' => null,
        ]);
    }

    public static function freshToken(): string
    {
        return Str::random(48);
    }

    /** Next register number for a tenant (1, 2, 3, …). */
    public static function nextNumber(string $tenantId): int // tenant ids are UUIDs
    {
        return (int) static::where('tenant_id', $tenantId)->max('number') + 1;
    }
}
