<?php

namespace App\Models\Tenant;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantDomain — one row per (tenant, hostname).
 *
 * State machine values (status column):
 *   pending_dns   - waiting for tenant's DNS records to appear
 *   verifying     - DNS detected, validating ownership + setting up CF
 *   issuing_cert  - Cloudflare provisioning the cert
 *   active        - serving HTTPS traffic
 *   error         - last check failed; see last_error_*
 *   suspended     - admin-disabled
 *
 * Role values:
 *   admin   - admin only; public booking still uses subdomain
 *   booking - public booking only; admin still uses subdomain
 *   both    - both (most common)
 */
class TenantDomain extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'hostname',
        'is_primary',
        'role',
        'alias_mode',
        'status',
        'verification_token',
        'cloudflare_hostname_id',
        'last_check_at',
        'last_check_status',
        'last_error_code',
        'last_error_message',
        'verified_at',
        'activated_at',
        'suspended_at',
        'suspended_reason',
    ];

    protected $casts = [
        'is_primary'    => 'boolean',
        'last_check_at' => 'datetime',
        'verified_at'   => 'datetime',
        'activated_at'  => 'datetime',
        'suspended_at'  => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeServing($query)
    {
        // Anything actively serving traffic OR being set up. Excludes
        // error/suspended which need attention.
        return $query->whereIn('status', [
            'pending_dns', 'verifying', 'issuing_cert', 'active',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Verification record's hostname (where the TXT goes).
     */
    public function verificationRecordName(): string
    {
        return '_intake-verify.' . $this->hostname;
    }

    /**
     * Verification record's value.
     */
    public function verificationRecordValue(): string
    {
        return 'intake-verify=' . $this->verification_token;
    }

    /**
     * Is this domain currently working for end users?
     */
    public function isLive(): bool
    {
        return $this->status === 'active';
    }
}
