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
        // MARKER-PATCH-125
        'cf_validation_records',
        'cf_dcv_delegation_records',
        'cf_validation_synced_at',
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
        'is_primary'                 => 'boolean',
        'last_check_at'              => 'datetime',
        'verified_at'                => 'datetime',
        'activated_at'               => 'datetime',
        'suspended_at'               => 'datetime',
        // MARKER-PATCH-125
        'cf_validation_records'      => 'array',
        'cf_dcv_delegation_records'  => 'array',
        'cf_validation_synced_at'    => 'datetime',
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

    /**
     * MARKER-PATCH-125 — domains stuck mid-validation for over 24 hours.
     * Almost always means the tenant added Intake's records but missed
     * Cloudflare's gate-2 DCV records, leaving the cert unable to issue.
     */
    public function scopeStuckVerifying($query)
    {
        return $query->whereIn('status', ['verifying', 'issuing_cert'])
            ->where('updated_at', '<', now()->subHours(24));
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

    /**
     * MARKER-PATCH-125 — preferred CF DCV record for the UI.
     * Returns ['type' => 'CNAME'|'TXT', 'name' => ..., 'value' => ...] or null.
     *
     * CNAME delegation is preferred — single record, no rotation on renewal.
     * TXT fallback works but the value rotates at every cert renewal and
     * the tenant must manually update it.
     */
    public function preferredDcvRecord(): ?array
    {
        $delegation = $this->cf_dcv_delegation_records ?? [];
        if (!empty($delegation) && !empty($delegation[0]['cname'])) {
            return [
                'type'  => 'CNAME',
                'name'  => $delegation[0]['cname'],
                'value' => $delegation[0]['cname_target'] ?? '',
            ];
        }

        $validation = $this->cf_validation_records ?? [];
        if (!empty($validation) && !empty($validation[0]['txt_name'])) {
            return [
                'type'  => 'TXT',
                'name'  => $validation[0]['txt_name'],
                'value' => $validation[0]['txt_value'] ?? '',
            ];
        }

        return null;
    }

    /**
     * MARKER-PATCH-125 — TXT fallback record when CNAME delegation is shown
     * as the primary recommendation. Returns the same shape as
     * preferredDcvRecord(), or null when no TXT fallback is available.
     */
    public function dcvTxtFallbackRecord(): ?array
    {
        $validation = $this->cf_validation_records ?? [];
        if (!empty($validation) && !empty($validation[0]['txt_name'])) {
            return [
                'type'  => 'TXT',
                'name'  => $validation[0]['txt_name'],
                'value' => $validation[0]['txt_value'] ?? '',
            ];
        }
        return null;
    }
}
