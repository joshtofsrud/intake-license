<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantTrustedDevice
 *
 * Represents one browser cookie that a tenant user explicitly trusted
 * ("Trust this device" at sign-in). See auth-refactor-spec-v2.md §3.
 *
 * Lookup is by SHA-256 hash of the cookie value — plaintext never reaches
 * the DB. Mint via DeviceTrustService::mint(); validate via ::validate().
 */
class TenantTrustedDevice extends Model
{
    use HasUuids;

    protected $table = 'tenant_trusted_devices';

    protected $fillable = [
        'tenant_id',
        'device_token_hash',
        'label',
        'user_agent_seen',
        'ip_first_seen',
        'ip_last_seen',
        'trusted_at',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'trusted_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'revoked_by_user_id');
    }

    /**
     * Active = not revoked, not expired.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    /**
     * Scope: only active devices for a given tenant.
     */
    public function scopeActiveForTenant($query, string $tenantId)
    {
        return $query
            ->where('tenant_id', $tenantId)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
}
