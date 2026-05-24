#!/usr/bin/env python3
"""
Patch 125 — Cloudflare DCV record capture, display, and stuck-verifying filter.

Background: Cloudflare for SaaS has two validation gates for a custom hostname.
Gate 1 is Intake's own ownership check (the _intake-verify TXT). Gate 2 is
Cloudflare's cert-authority validation, which Cloudflare returns in the
getCustomHostname response as ssl.validation_records and ssl.dcv_delegation_records.

Before this patch, only gate-1 records were stored locally and shown to tenants.
Gate 2 records were thrown away by syncFromCloudflare, so tenants reaching
the verifying state had no UI guidance on the second set of DNS records needed.
Domains would stay in verifying indefinitely.

This patch:

  1. Adds 3 columns to tenant_domains (JSON x2 + timestamp).
  2. Extends CloudflareForSaasService::createCustomHostname() and
     getCustomHostname() to expose ssl.validation_records and
     ssl.dcv_delegation_records.
  3. Updates DomainProvisioningService to persist them on create and sync.
  4. Adds a stuckVerifying scope on the TenantDomain model.
  5. Renders a new "Cert validation" section in the tenant domain show view
     during pending_dns / verifying / issuing_cert states. Prefers CNAME
     delegation (one record, handles renewals); shows TXT fallback in a
     <details>.
  6. Adds a "Stuck in verifying >24h" filter to the master admin Filament
     resource, matching the existing "errored >24h" pattern.

No backfill required: DomainController::show triggers syncFromCloudflare on
every page view, so existing rows populate the new columns the next time
their detail page is opened (or the next poller tick).

Usage:
    python3 patch-125.py /path/to/intake-license             # dry-run
    python3 patch-125.py /path/to/intake-license --apply     # write changes

Idempotent. Safe to re-run.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────────────────
# 1. Migration — new file
# ─────────────────────────────────────────────────────────────────────

MIGRATION_FILENAME = '2026_05_24_000001_add_cf_validation_columns_to_tenant_domains.php'

MIGRATION_BODY = """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * MARKER-PATCH-125
 *
 * Cloudflare for SaaS returns two sets of DNS records that tenants must
 * add to validate cert issuance with the CA (in addition to Intake's own
 * ownership TXT):
 *
 *   ssl.validation_records       - TXT pair (txt_name / txt_value).
 *                                  Must be re-added on every cert renewal.
 *   ssl.dcv_delegation_records   - CNAME delegation (cname / cname_target).
 *                                  Set-and-forget; handles future renewals.
 *
 * We store the raw arrays as returned by the CF API so the view layer can
 * pick the preferred record shape without parsing assumptions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->json('cf_validation_records')->nullable()->after('cloudflare_hostname_id');
            $table->json('cf_dcv_delegation_records')->nullable()->after('cf_validation_records');
            $table->timestamp('cf_validation_synced_at')->nullable()->after('cf_dcv_delegation_records');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn([
                'cf_validation_records',
                'cf_dcv_delegation_records',
                'cf_validation_synced_at',
            ]);
        });
    }
};
"""


# ─────────────────────────────────────────────────────────────────────
# 2. CloudflareForSaasService — extract validation_records + dcv_delegation_records
# ─────────────────────────────────────────────────────────────────────

CF_CREATE_OLD = """        $response = $this->request('POST', "zones/{$this->zoneId}/custom_hostnames", $payload);

        $result = $response['result'] ?? [];
        return [
            'id'                     => (string) ($result['id'] ?? ''),
            'hostname'               => (string) ($result['hostname'] ?? $hostname),
            'status'                 => (string) ($result['status'] ?? 'pending'),
            'ownership_verification' => (array) ($result['ownership_verification'] ?? []),
            'raw'                    => $result,
        ];
    }"""

CF_CREATE_NEW = """        $response = $this->request('POST', "zones/{$this->zoneId}/custom_hostnames", $payload);

        $result = $response['result'] ?? [];
        $ssl    = (array) ($result['ssl'] ?? []);
        return [
            'id'                         => (string) ($result['id'] ?? ''),
            'hostname'                   => (string) ($result['hostname'] ?? $hostname),
            'status'                     => (string) ($result['status'] ?? 'pending'),
            'ownership_verification'     => (array) ($result['ownership_verification'] ?? []),
            // MARKER-PATCH-125 — gate-2 (cert authority) validation records.
            // Returned even on first create when CF needs the tenant to prove
            // ownership to the CA before issuing the cert.
            'ssl_validation_records'     => (array) ($ssl['validation_records'] ?? []),
            'ssl_dcv_delegation_records' => (array) ($ssl['dcv_delegation_records'] ?? []),
            'raw'                        => $result,
        ];
    }"""

CF_GET_OLD = """        $response = $this->request('GET', "zones/{$this->zoneId}/custom_hostnames/{$cfHostnameId}");

        $result = $response['result'] ?? [];
        return [
            'id'       => (string) ($result['id'] ?? ''),
            'hostname' => (string) ($result['hostname'] ?? ''),
            'status'   => (string) ($result['status'] ?? ''),
            'ssl'      => (array) ($result['ssl'] ?? []),
            'raw'      => $result,
        ];
    }"""

CF_GET_NEW = """        $response = $this->request('GET', "zones/{$this->zoneId}/custom_hostnames/{$cfHostnameId}");

        $result = $response['result'] ?? [];
        $ssl    = (array) ($result['ssl'] ?? []);
        return [
            'id'                         => (string) ($result['id'] ?? ''),
            'hostname'                   => (string) ($result['hostname'] ?? ''),
            'status'                     => (string) ($result['status'] ?? ''),
            'ssl'                        => $ssl,
            // MARKER-PATCH-125 — gate-2 (cert authority) validation records.
            // CF re-emits these whenever the cert is in pending_validation
            // or near renewal; persisted on every sync so the show view can
            // surface the latest set.
            'ssl_validation_records'     => (array) ($ssl['validation_records'] ?? []),
            'ssl_dcv_delegation_records' => (array) ($ssl['dcv_delegation_records'] ?? []),
            'raw'                        => $result,
        ];
    }"""


# ─────────────────────────────────────────────────────────────────────
# 3. DomainProvisioningService — persist new fields on create + sync
# ─────────────────────────────────────────────────────────────────────

PROVISIONING_CREATE_OLD = """            $domain = new TenantDomain([
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
            ]);
            $domain->save();"""

PROVISIONING_CREATE_NEW = """            $domain = new TenantDomain([
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
            $domain->save();"""

PROVISIONING_SYNC_OLD = """        $updates = [
            'last_check_at'     => now(),
            'last_check_status' => $cfData['status'] ?? 'unknown',
        ];"""

PROVISIONING_SYNC_NEW = """        $updates = [
            'last_check_at'     => now(),
            'last_check_status' => $cfData['status'] ?? 'unknown',
            // MARKER-PATCH-125 — refresh gate-2 records on every sync.
            // CF rotates these around renewals, so we want the freshest set
            // surfaced on the show view.
            'cf_validation_records'     => ($cfData['ssl_validation_records']     ?? []) ?: null,
            'cf_dcv_delegation_records' => ($cfData['ssl_dcv_delegation_records'] ?? []) ?: null,
            'cf_validation_synced_at'   => now(),
        ];"""


# ─────────────────────────────────────────────────────────────────────
# 4. TenantDomain model — JSON casts, scope, helper
# ─────────────────────────────────────────────────────────────────────

MODEL_FILLABLE_OLD = """    protected $fillable = [
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
    ];"""

MODEL_FILLABLE_NEW = """    protected $fillable = [
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
    ];"""

MODEL_CASTS_OLD = """    protected $casts = [
        'is_primary'    => 'boolean',
        'last_check_at' => 'datetime',
        'verified_at'   => 'datetime',
        'activated_at'  => 'datetime',
        'suspended_at'  => 'datetime',
    ];"""

MODEL_CASTS_NEW = """    protected $casts = [
        'is_primary'                 => 'boolean',
        'last_check_at'              => 'datetime',
        'verified_at'                => 'datetime',
        'activated_at'               => 'datetime',
        'suspended_at'               => 'datetime',
        // MARKER-PATCH-125
        'cf_validation_records'      => 'array',
        'cf_dcv_delegation_records'  => 'array',
        'cf_validation_synced_at'    => 'datetime',
    ];"""

MODEL_SCOPE_OLD = """    public function scopeServing($query)
    {
        // Anything actively serving traffic OR being set up. Excludes
        // error/suspended which need attention.
        return $query->whereIn('status', [
            'pending_dns', 'verifying', 'issuing_cert', 'active',
        ]);
    }"""

MODEL_SCOPE_NEW = """    public function scopeServing($query)
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
    }"""

MODEL_HELPERS_OLD = """    /**
     * Is this domain currently working for end users?
     */
    public function isLive(): bool
    {
        return $this->status === 'active';
    }
}"""

MODEL_HELPERS_NEW = """    /**
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
}"""


# ─────────────────────────────────────────────────────────────────────
# 5. show.blade.php — new Cert validation section
#
# Inserted immediately after the existing "DNS RECORDS" block and before
# the existing "PROGRESS STEPS" block. Visible in pending_dns / verifying
# / issuing_cert. Collapsed-via-<details> when active. Hidden when error
# or suspended (caller has bigger problems).
# ─────────────────────────────────────────────────────────────────────

VIEW_ANCHOR_OLD = """{{-- ───────────── PROGRESS STEPS (during verifying/issuing) ───────────── --}}"""

VIEW_ANCHOR_NEW = """{{-- ───────────── CERT VALIDATION (MARKER-PATCH-125) ───────────── --}}
{{-- Cloudflare for SaaS gate-2 records. Cert can't issue until tenant adds these. --}}
@php
  $preferredDcv = $domain->preferredDcvRecord();
  $txtFallback  = $domain->dcvTxtFallbackRecord();
  $showCertVal  = $preferredDcv !== null
                  && in_array($statusKey, ['pending_dns','verifying','issuing_cert','active'], true);
  $certValMode  = $statusKey === 'active' ? 'collapsed' : 'prominent';
@endphp

@if($showCertVal)
  @if($certValMode === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        Cert validation records <span style="font-weight:400;opacity:.7">— required for automatic cert renewal</span>
      </summary>
      <div style="padding:14px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">
          @if($preferredDcv['type'] === 'CNAME')
            One more record — handles cert renewals automatically
          @else
            One more record — required to issue your HTTPS cert
          @endif
        </span>
      </div>
      <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
        @if($preferredDcv['type'] === 'CNAME')
          This record lets the cert authority validate your domain. Adding the CNAME version (below) is preferred — it's set-and-forget and renews on its own.
        @else
          Add this TXT record so the cert authority can validate your domain. The value rotates at every cert renewal — we'll prompt you when it changes.
        @endif
      </p>
  @endif

      <div class="ds-dns">
        <div class="ds-dns-row head">
          <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">{{ $preferredDcv['type'] }}</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $preferredDcv['name'] }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $preferredDcv['value'] }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $preferredDcv['value'] }}">Copy</button>
        </div>
      </div>

      @if($preferredDcv['type'] === 'CNAME' && $txtFallback && $certValMode !== 'collapsed')
        <details style="margin-top:12px">
          <summary style="cursor:pointer;font-size:12px;color:var(--ia-text-3,#888)">
            Can't add a CNAME under <code style="font-family:var(--ia-font-mono,monospace)">_acme-challenge</code>? Use the TXT alternative.
          </summary>
          <div style="margin-top:10px">
            <p style="font-size:11.5px;color:var(--ia-text-3,#888);margin-bottom:8px;line-height:1.55">
              Some registrars don't permit CNAMEs at this subdomain. The TXT version works but its value changes at every cert renewal (about every 90 days) — you'll need to update it manually each time.
            </p>
            <div class="ds-dns">
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">{{ $txtFallback['type'] }}</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $txtFallback['name'] }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $txtFallback['value'] }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $txtFallback['value'] }}">Copy</button>
              </div>
            </div>
          </div>
        </details>
      @endif

  @if($certValMode === 'collapsed')
      </div>
    </details>
  @else
    </div>
  @endif
@endif

{{-- ───────────── PROGRESS STEPS (during verifying/issuing) ───────────── --}}"""


# ─────────────────────────────────────────────────────────────────────
# 6. Filament — stuck-verifying filter
# ─────────────────────────────────────────────────────────────────────

FILAMENT_FILTER_OLD = """                Tables\\Filters\\Filter::make('needs_attention')
                    ->label('Needs attention (errored >24h)')
                    ->query(fn (Builder $q) => $q->where('status', 'error')
                        ->where('last_check_at', '<=', now()->subHours(24)))
                    ->toggle(),"""

FILAMENT_FILTER_NEW = """                Tables\\Filters\\Filter::make('needs_attention')
                    ->label('Needs attention (errored >24h)')
                    ->query(fn (Builder $q) => $q->where('status', 'error')
                        ->where('last_check_at', '<=', now()->subHours(24)))
                    ->toggle(),

                // MARKER-PATCH-125
                Tables\\Filters\\Filter::make('stuck_verifying')
                    ->label('Stuck in verifying/issuing >24h')
                    ->query(fn (Builder $q) => $q->stuckVerifying())
                    ->toggle(),"""


# ─────────────────────────────────────────────────────────────────────
# Driver
# ─────────────────────────────────────────────────────────────────────

def edit(path: pathlib.Path, old: str, new: str, label: str, summary: dict, apply: bool):
    """Replace a single occurrence in a file. Idempotent: skips if 'new' is
    already present, errors loudly if 'old' isn't found."""
    text = path.read_text()
    if new.strip() and new in text:
        return  # already applied
    if old not in text:
        print(f"ERROR [{label}]: anchor not found in {path}", file=sys.stderr)
        print(f"  expected to find this block:\n    {old.splitlines()[0]!r}", file=sys.stderr)
        sys.exit(2)
    if text.count(old) > 1:
        print(f"ERROR [{label}]: anchor matches {text.count(old)} times in {path} (expected 1)", file=sys.stderr)
        sys.exit(2)
    new_text = text.replace(old, new, 1)
    if apply:
        path.write_text(new_text)
    summary[label] = 1


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}

    # 1. Migration (new file)
    mig_path = root / 'database' / 'migrations' / MIGRATION_FILENAME
    if not mig_path.exists():
        if apply:
            mig_path.write_text(MIGRATION_BODY)
        summary['migration_created'] = 1
    else:
        summary['migration_created'] = 0  # already exists, idempotent

    # 2. CloudflareForSaasService
    cf = root / 'app' / 'Services' / 'CloudflareForSaasService.php'
    edit(cf, CF_CREATE_OLD, CF_CREATE_NEW, 'cf_create_response', summary, apply)
    edit(cf, CF_GET_OLD,    CF_GET_NEW,    'cf_get_response',    summary, apply)

    # 3. DomainProvisioningService
    prov = root / 'app' / 'Services' / 'DomainProvisioningService.php'
    edit(prov, PROVISIONING_CREATE_OLD, PROVISIONING_CREATE_NEW, 'provisioning_create', summary, apply)
    edit(prov, PROVISIONING_SYNC_OLD,   PROVISIONING_SYNC_NEW,   'provisioning_sync',   summary, apply)

    # 4. TenantDomain model
    model = root / 'app' / 'Models' / 'Tenant' / 'TenantDomain.php'
    edit(model, MODEL_FILLABLE_OLD, MODEL_FILLABLE_NEW, 'model_fillable', summary, apply)
    edit(model, MODEL_CASTS_OLD,    MODEL_CASTS_NEW,    'model_casts',    summary, apply)
    edit(model, MODEL_SCOPE_OLD,    MODEL_SCOPE_NEW,    'model_scope',    summary, apply)
    edit(model, MODEL_HELPERS_OLD,  MODEL_HELPERS_NEW,  'model_helpers',  summary, apply)

    # 5. show.blade.php
    view = root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php'
    edit(view, VIEW_ANCHOR_OLD, VIEW_ANCHOR_NEW, 'view_cert_validation', summary, apply)

    # 6. Filament
    filament = root / 'app' / 'Filament' / 'Resources' / 'TenantDomainResource.php'
    edit(filament, FILAMENT_FILTER_OLD, FILAMENT_FILTER_NEW, 'filament_stuck_filter', summary, apply)

    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []

    # Marker presence — proxy for "patch landed"
    checks = [
        (root / 'database' / 'migrations' / MIGRATION_FILENAME, 'cf_validation_records', 'migration'),
        (root / 'app' / 'Services' / 'CloudflareForSaasService.php', 'ssl_validation_records', 'cf service'),
        (root / 'app' / 'Services' / 'DomainProvisioningService.php', 'cf_validation_records', 'provisioning service'),
        (root / 'app' / 'Models' / 'Tenant' / 'TenantDomain.php', 'scopeStuckVerifying', 'model scope'),
        (root / 'app' / 'Models' / 'Tenant' / 'TenantDomain.php', 'preferredDcvRecord', 'model helper'),
        (root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php', 'MARKER-PATCH-125', 'view'),
        (root / 'app' / 'Filament' / 'Resources' / 'TenantDomainResource.php', 'stuckVerifying', 'filament filter'),
    ]
    for path, needle, label in checks:
        if not path.exists():
            failures.append(f"{label}: {path} does not exist")
            continue
        if needle not in path.read_text():
            failures.append(f"{label}: marker '{needle}' not found in {path.name}")

    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true', help='Write changes (default: dry-run)')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f"ERROR: {root} does not look like an intake repo", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-125 [{mode}] target={root} ===\n")

    summary = process(root, apply=args.apply)

    print("Summary:")
    for k, v in summary.items():
        print(f"  {k}: {v}")

    if args.apply:
        print("\nVerifying...")
        failures = verify(root)
        if failures:
            print("\nFAIL — leftovers found:")
            for f in failures:
                print(f"  - {f}")
            sys.exit(1)
        print("  all checks pass")
        print("\nReminder: run `php artisan migrate` on the server after deploy.")
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
