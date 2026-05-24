<?php

namespace App\Services;

use App\Exceptions\CloudflareException;
use App\Models\Tenant;
use App\Models\Tenant\TenantDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DomainProvisioningService
 *
 * Orchestrates the lifecycle of a custom domain — local DB row +
 * Cloudflare custom hostname — as a unit. This is what UIs (master
 * admin, tenant settings) call.
 *
 * State machine (TenantDomain.status):
 *   pending_dns  - row exists, CF hostname registered, awaiting DNS
 *   verifying    - CF observed DNS, doing TXT validation
 *   issuing_cert - validation passed, CF provisioning the cert
 *   active       - cert live, serving HTTPS
 *   error        - CF reported a failure; last_error_* populated
 *   suspended    - admin-disabled (chargeback, abuse, etc.)
 *
 * Idempotent on retry: createForTenant for the same (tenant, hostname)
 * returns the existing row instead of creating a duplicate.
 */
class DomainProvisioningService
{
    public function __construct(
        private readonly CloudflareForSaasService $cf,
    ) {}

    /**
     * Create a domain for a tenant. Registers with Cloudflare, creates
     * the local row, returns it in 'pending_dns' state.
     *
     * @param array{is_primary?: bool, role?: string, alias_mode?: string} $opts
     */
    public function createForTenant(Tenant $tenant, string $hostname, array $opts = []): TenantDomain
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            throw new \InvalidArgumentException('hostname is required');
        }

        // Idempotency: if a row already exists for this (tenant, hostname),
        // return it instead of creating another.
        $existing = TenantDomain::where('tenant_id', $tenant->id)
            ->where('hostname', $hostname)
            ->first();
        if ($existing) {
            Log::info('[DomainProvisioning] createForTenant - returning existing row', [
                'tenant_id' => $tenant->id,
                'hostname'  => $hostname,
                'status'    => $existing->status,
            ]);
            return $existing;
        }

        // Two-phase: register with Cloudflare first, then save local row.
        // If CF fails, we never persist a local row that points at nothing.
        $cfResult = $this->cf->createCustomHostname($hostname);

        return DB::transaction(function () use ($tenant, $hostname, $cfResult, $opts) {
            $domain = new TenantDomain([
                'tenant_id'              => $tenant->id,
                'hostname'               => $hostname,
                'is_primary'             => (bool) ($opts['is_primary'] ?? false),
                'role'                   => $opts['role'] ?? 'both',
                'alias_mode'             => $opts['alias_mode'] ?? 'redirect',
                'status'                 => 'pending_dns',
                'verification_token'     => Str::random(32),
                'cloudflare_hostname_id' => $cfResult['id'],
                'last_check_at'          => now(),
                'last_check_status'      => 'created',
                // MARKER-PATCH-125 — persist gate-2 validation records emitted
                // at hostname creation time so the tenant sees them immediately.
                'cf_validation_records'     => $cfResult['ssl_validation_records']     ?: null,
                'cf_dcv_delegation_records' => $cfResult['ssl_dcv_delegation_records'] ?: null,
                'cf_validation_synced_at'   => now(),
            ]);
            $domain->save();

            Log::info('[DomainProvisioning] domain created', [
                'tenant_id'              => $tenant->id,
                'hostname'               => $hostname,
                'cloudflare_hostname_id' => $cfResult['id'],
                'is_primary'             => $domain->is_primary,
            ]);

            return $domain;
        });
    }

    /**
     * Pull the latest state from Cloudflare and update the local row.
     * Called by the poller and by webhook handlers.
     *
     * Returns true if the local status changed.
     */
    public function syncFromCloudflare(TenantDomain $domain): bool
    {
        if (!$domain->cloudflare_hostname_id) {
            // Nothing to sync.
            $domain->update([
                'last_check_at'      => now(),
                'last_check_status'  => 'no_cf_hostname_id',
            ]);
            return false;
        }

        $previousStatus = $domain->status;

        try {
            $cfData = $this->cf->getCustomHostname($domain->cloudflare_hostname_id);
        } catch (CloudflareException $e) {
            return $this->recordError($domain, $e->errorCode, $e->getMessage());
        }

        $newStatus = $this->mapCloudflareStatus($cfData['status'] ?? '', $cfData['ssl'] ?? []);

        $updates = [
            'last_check_at'     => now(),
            'last_check_status' => $cfData['status'] ?? 'unknown',
            // MARKER-PATCH-125 — refresh gate-2 records on every sync.
            // CF rotates these around renewals, so we want the freshest set
            // surfaced on the show view.
            'cf_validation_records'     => ($cfData['ssl_validation_records']     ?? []) ?: null,
            'cf_dcv_delegation_records' => ($cfData['ssl_dcv_delegation_records'] ?? []) ?: null,
            'cf_validation_synced_at'   => now(),
        ];

        if ($newStatus !== $previousStatus) {
            $updates['status'] = $newStatus;
            $updates['last_error_code']    = null;
            $updates['last_error_message'] = null;

            if ($newStatus === 'verifying' && !$domain->verified_at) {
                $updates['verified_at'] = now();
            }
            if ($newStatus === 'active' && !$domain->activated_at) {
                $updates['activated_at'] = now();
            }
        }

        $domain->update($updates);

        if ($newStatus !== $previousStatus) {
            Log::info('[DomainProvisioning] status transition', [
                'hostname'   => $domain->hostname,
                'from'       => $previousStatus,
                'to'         => $newStatus,
                'cf_status'  => $cfData['status'] ?? null,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Tear down a domain: remove from Cloudflare, delete local row.
     * If Cloudflare returns 404, we treat it as already-gone and proceed.
     */
    public function remove(TenantDomain $domain): void
    {
        if ($domain->cloudflare_hostname_id) {
            try {
                $this->cf->deleteCustomHostname($domain->cloudflare_hostname_id);
            } catch (CloudflareException $e) {
                // Log but proceed - if CF can't delete, we still want the
                // local row gone so the tenant can re-add or move on.
                Log::warning('[DomainProvisioning] CF delete failed; removing local row anyway', [
                    'hostname'   => $domain->hostname,
                    'error_code' => $e->errorCode,
                    'message'    => $e->getMessage(),
                ]);
            }
        }

        $domain->delete();

        Log::info('[DomainProvisioning] domain removed', [
            'hostname' => $domain->hostname,
        ]);
    }

    /**
     * Mark a domain suspended (admin action).
     */
    public function suspend(TenantDomain $domain, string $reason): void
    {
        $domain->update([
            'status'           => 'suspended',
            'suspended_at'     => now(),
            'suspended_reason' => $reason,
        ]);
        Log::info('[DomainProvisioning] suspended', [
            'hostname' => $domain->hostname,
            'reason'   => $reason,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Map Cloudflare's hostname status + ssl block into our state machine.
     *
     * CF hostname statuses we care about:
     *   - pending           → pending_dns (just created, awaiting validation)
     *   - active            → check ssl.status to disambiguate
     *   - moved             → error (DNS no longer points at us)
     *   - blocked / deleted → error
     */
    private function mapCloudflareStatus(string $cfStatus, array $ssl): string
    {
        $sslStatus = (string) ($ssl['status'] ?? '');

        return match (true) {
            $cfStatus === 'active' && $sslStatus === 'active'      => 'active',
            $cfStatus === 'active' && $sslStatus === 'pending_validation' => 'verifying',
            $cfStatus === 'active' && in_array($sslStatus, ['pending_issuance', 'pending_deployment'], true) => 'issuing_cert',
            $cfStatus === 'pending'                                => 'pending_dns',
            $cfStatus === 'pending_validation'                     => 'verifying',
            in_array($cfStatus, ['moved', 'blocked', 'deleted'], true) => 'error',
            default                                                => 'pending_dns',
        };
    }

    /**
     * Record an error from a sync attempt. Returns true if status changed.
     */
    private function recordError(TenantDomain $domain, string $errorCode, string $message): bool
    {
        $previousStatus = $domain->status;
        $newStatus = $domain->status === 'active' ? 'error' : $domain->status;

        $domain->update([
            'status'             => $newStatus,
            'last_check_at'      => now(),
            'last_check_status'  => 'error',
            'last_error_code'    => $errorCode,
            'last_error_message' => $message,
        ]);

        Log::warning('[DomainProvisioning] sync error', [
            'hostname'   => $domain->hostname,
            'error_code' => $errorCode,
            'message'    => $message,
        ]);

        return $newStatus !== $previousStatus;
    }
}
