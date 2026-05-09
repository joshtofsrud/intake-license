#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# Intake — Location everywhere
# Adds location_id to tenant_appointments, fixes appointment-derived sales
# bridge, hardens createRefund's location guard, backfills existing data.
#
# Why: appointment-derived register sales were never given a location_id,
# making them un-refundable. Multi-location tenants will need this anyway.
# Single fix-it-once pass.
#
# Usage on Mac:  bash intake-location-everywhere-patch.sh
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail
[ -f artisan ] || { echo "ABORT: not a Laravel root"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# 1. Migration — add location_id to tenant_appointments + backfill
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Creating migration: add_location_id_to_tenant_appointments"

MIG1="database/migrations/2026_05_09_000003_add_location_id_to_tenant_appointments.php"
if [ -f "$MIG1" ]; then
  echo "    skip: already exists"
else
  cat > "$MIG1" <<'PHP_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add location_id to appointments. Required for multi-location tenants and
 * for appointment-derived register sales to be refundable.
 *
 * Backfill: every existing appointment gets the tenant's default location
 * (is_default=1) or, if none flagged default, the first active location.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->uuid('location_id')->nullable()->after('resource_id');
            $t->index('location_id');
            $t->foreign('location_id')
                ->references('id')->on('tenant_locations')
                ->nullOnDelete();
        });

        // Backfill: per-tenant lookup of default location, then assign.
        $tenantIds = DB::table('tenant_appointments')
            ->whereNull('location_id')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $defaultLoc = DB::table('tenant_locations')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            if ($defaultLoc) {
                DB::table('tenant_appointments')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('location_id')
                    ->update(['location_id' => $defaultLoc]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            $t->dropForeign(['location_id']);
            $t->dropIndex(['location_id']);
            $t->dropColumn('location_id');
        });
    }
};
PHP_FILE
  echo "    wrote: $MIG1"
fi

# ──────────────────────────────────────────────────────────────────────────────
# 2. Migration — backfill location_id on existing tenant_sales
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Creating migration: backfill_location_id_on_tenant_sales"

MIG2="database/migrations/2026_05_09_000004_backfill_location_id_on_tenant_sales.php"
if [ -f "$MIG2" ]; then
  echo "    skip: already exists"
else
  cat > "$MIG2" <<'PHP_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: any sale with null location_id gets the tenant's
 * default location. Same lookup as the appointments backfill.
 *
 * This unblocks refunds on appointment-derived sales that were created
 * before the bridge service was patched to set location_id explicitly.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenantIds = DB::table('tenant_sales')
            ->whereNull('location_id')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $defaultLoc = DB::table('tenant_locations')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            if ($defaultLoc) {
                DB::table('tenant_sales')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('location_id')
                    ->update(['location_id' => $defaultLoc]);
            }
        }
    }

    public function down(): void
    {
        // Backfill is forward-only. Reversing would lose information.
    }
};
PHP_FILE
  echo "    wrote: $MIG2"
fi

# ──────────────────────────────────────────────────────────────────────────────
# 3. Patch TenantAppointment model — add location_id to fillable + relationship
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching TenantAppointment model"

python3 <<'PY'
from pathlib import Path
p = Path("app/Models/Tenant/TenantAppointment.php")
s = p.read_text()

if "'location_id'," in s:
    print("    skip: already patched")
else:
    old_fillable = """    protected $fillable = [
        'tenant_id','customer_id','resource_id','ra_number',"""
    new_fillable = """    protected $fillable = [
        'tenant_id','customer_id','resource_id','location_id','ra_number',"""
    assert s.count(old_fillable) == 1, f"ABORT: fillable matched {s.count(old_fillable)}"
    s = s.replace(old_fillable, new_fillable)

    old_rel = """    public function resource(): BelongsTo  { return $this->belongsTo(TenantResource::class, 'resource_id'); }"""
    new_rel = """    public function resource(): BelongsTo  { return $this->belongsTo(TenantResource::class, 'resource_id'); }
    public function location(): BelongsTo  { return $this->belongsTo(TenantLocation::class, 'location_id'); }"""
    assert s.count(old_rel) == 1
    s = s.replace(old_rel, new_rel)

    p.write_text(s)
    print("    patched: TenantAppointment.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 4. Patch BookingService — set location_id on appointment create
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching BookingService"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/BookingService.php")
s = p.read_text()

if "'location_id'              =>" in s and "BookingService" in s:
    print("    skip: already patched")
else:
    old = """                $appointment = TenantAppointment::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenantId,
                    'customer_id'              => $customer->id,
                    'resource_id'              => $resourceId,
                    'ra_number'                => $raNumber,"""

    new = """                // Resolve location: caller-provided wins; otherwise tenant's default.
                $locationId = $data['location_id'] ?? null;
                if (! $locationId) {
                    $locationId = \\App\\Models\\Tenant\\TenantLocation::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', 1)
                        ->orderByDesc('is_default')
                        ->orderBy('created_at')
                        ->value('id');
                }

                $appointment = TenantAppointment::create([
                    'id'                       => (string) Str::uuid(),
                    'tenant_id'                => $tenantId,
                    'customer_id'              => $customer->id,
                    'resource_id'              => $resourceId,
                    'location_id'              => $locationId,
                    'ra_number'                => $raNumber,"""

    assert s.count(old) == 1, f"ABORT: BookingService matched {s.count(old)}"
    p.write_text(s.replace(old, new))
    print("    patched: BookingService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 5. Patch AppointmentController — same on the manual-create path
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching AppointmentController"

python3 <<'PY'
from pathlib import Path
p = Path("app/Http/Controllers/Tenant/AppointmentController.php")
s = p.read_text()

if "'location_id' => $locationId" in s:
    print("    skip: already patched")
else:
    old = """        $appointment = TenantAppointment::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'ra_number' => $itoNumber,"""

    new = """        $locationId = $data['location_id'] ?? \\App\\Models\\Tenant\\TenantLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->value('id');

        $appointment = TenantAppointment::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'location_id' => $locationId, 'ra_number' => $itoNumber,"""

    assert s.count(old) == 1, f"ABORT: AppointmentController matched {s.count(old)}"
    p.write_text(s.replace(old, new))
    print("    patched: AppointmentController.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 6. Patch AppointmentRegisterBridgeService — set location_id on draft sales
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching AppointmentRegisterBridgeService"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/Tenant/AppointmentRegisterBridgeService.php")
s = p.read_text()

if "'location_id'         =>" in s:
    print("    skip: already patched")
else:
    old = """            $sale = TenantSale::create([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $appointment->tenant_id,
                'sale_number'         => $saleNumber,
                'sale_date'           => now()->toDateString(),
                'status'              => 'pending',
                'payment_status'      => 'draft',
                'customer_id'         => $appointment->customer_id,
                'appointment_id'      => $appointment->id,"""

    new = """            // Resolve location: appointment's wins; tenant default fallback.
            $locationId = $appointment->location_id ?: \\App\\Models\\Tenant\\TenantLocation::query()
                ->where('tenant_id', $appointment->tenant_id)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');

            $sale = TenantSale::create([
                'id'                  => (string) Str::uuid(),
                'tenant_id'           => $appointment->tenant_id,
                'sale_number'         => $saleNumber,
                'sale_date'           => now()->toDateString(),
                'status'              => 'pending',
                'payment_status'      => 'draft',
                'customer_id'         => $appointment->customer_id,
                'appointment_id'      => $appointment->id,
                'location_id'         => $locationId,"""

    assert s.count(old) == 1, f"ABORT: bridge matched {s.count(old)}"
    p.write_text(s.replace(old, new))
    print("    patched: AppointmentRegisterBridgeService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# 7. Patch SaleService::createRefund — soften the location guard
# ──────────────────────────────────────────────────────────────────────────────
echo "==> Patching SaleService::createRefund"

python3 <<'PY'
from pathlib import Path
p = Path("app/Services/Tenant/SaleService.php")
s = p.read_text()

if "resolveRefundLocation" in s:
    print("    skip: already patched")
else:
    old = """        if (empty($original->location_id)) {
            throw new SaleValidationException('Original sale has no location_id; cannot refund.');
        }"""

    new = """        // Resolve a location for the refund through a fallback chain:
        //   1. original sale's location_id
        //   2. original's appointment's location_id (if appointment-derived)
        //   3. tenant's default active location
        // Only error if NO location exists anywhere on the tenant.
        $refundLocationId = $original->location_id;
        if (! $refundLocationId && $original->appointment_id) {
            $refundLocationId = \\App\\Models\\Tenant\\TenantAppointment::where('id', $original->appointment_id)
                ->value('location_id');
        }
        if (! $refundLocationId) {
            $refundLocationId = \\App\\Models\\Tenant\\TenantLocation::query()
                ->where('tenant_id', $original->tenant_id)
                ->where('is_active', 1)
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');
        }
        if (! $refundLocationId) {
            throw new SaleValidationException('Tenant has no active location; cannot refund.');
        }"""

    assert s.count(old) == 1, f"ABORT: createRefund guard matched {s.count(old)}"
    s = s.replace(old, new)

    # Update the two follow-on uses of $original->location_id inside this method
    # to use the resolved $refundLocationId instead.
    old2 = """                'location_id'        => $original->location_id,"""
    new2 = """                'location_id'        => $refundLocationId,"""
    if s.count(old2) >= 1:
        # Only swap the FIRST occurrence (inside createRefund). createTransaction
        # uses its own $data['location_id'] and shouldn't be touched.
        s = s.replace(old2, new2, 1)

    old3 = """                    $this->inventory->incrementForRefund($refund, $line, $original->location_id);"""
    new3 = """                    $this->inventory->incrementForRefund($refund, $line, $refundLocationId);"""
    assert s.count(old3) == 1, f"ABORT: incrementForRefund matched {s.count(old3)}"
    s = s.replace(old3, new3)

    p.write_text(s)
    print("    patched: SaleService.php")
PY

# ──────────────────────────────────────────────────────────────────────────────
# Lint everything
# ──────────────────────────────────────────────────────────────────────────────
echo ""
echo "==> Linting modified PHP"
for f in \
  "$MIG1" \
  "$MIG2" \
  app/Models/Tenant/TenantAppointment.php \
  app/Services/BookingService.php \
  app/Http/Controllers/Tenant/AppointmentController.php \
  app/Services/Tenant/AppointmentRegisterBridgeService.php \
  app/Services/Tenant/SaleService.php; do
  if command -v php >/dev/null 2>&1; then
    php -l "$f"
  else
    echo "    (no php — skip lint of $f)"
  fi
done

echo ""
echo "==> Patch complete. Files touched:"
echo "    $MIG1"
echo "    $MIG2"
echo "    app/Models/Tenant/TenantAppointment.php"
echo "    app/Services/BookingService.php"
echo "    app/Http/Controllers/Tenant/AppointmentController.php"
echo "    app/Services/Tenant/AppointmentRegisterBridgeService.php"
echo "    app/Services/Tenant/SaleService.php"
echo ""
echo "Next:"
echo "  git add -A && git commit -m 'Location everywhere: fix bridge, soften refund guard, backfill data'"
echo "  git push"
echo ""
echo "Server:"
echo "  cd /var/www/intake && git pull"
echo "  php artisan migrate --force"
echo "  php artisan optimize:clear && php artisan view:clear"
echo "  sudo systemctl restart php8.3-fpm"
