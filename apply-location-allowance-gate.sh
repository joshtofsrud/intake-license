#!/usr/bin/env bash
set -euo pipefail
# apply-location-allowance-gate.sh — MARKER-LOCGATE
# Locations were COMPLETELY UNGATED. LocationController::store() carried only a
# comment — "NOTE: gate (Branded+ tier / additional_locations addon / quantity)
# is deferred" — so any owner could create unlimited locations for free. Since a
# location is priced to double the base subscription, that is a live revenue
# leak.
#
# THIS IS THE SMALL VERSION Josh asked for: an allowance set by master admin.
#   tenants.licensed_locations (default 1)
#   Tenant::canAddLocation()  = active locations < licensed_locations
#   store() refuses past it; the +Add button disappears at the cap
#   master admin sets the number on the tenant record
#
# It is deliberately a STEPPING STONE, not a throwaway. The Aug 6 decision was
# that a location is a multiplier on the base subscription, billed as quantity
# on the tenant's own Stripe subscription item — not an add-on row. When that
# lands, licensed_locations becomes DERIVED from the subscription quantity
# instead of hand-set, and canAddLocation()/store() keep working untouched.
#
# BACKFILL MATTERS: any tenant already running 2+ locations would instantly be
# over cap. The migration sets each tenant's allowance to at least their current
# active location count, so nobody is retroactively in violation. New tenants
# get 1.

MIG=database/migrations/2026_08_09_180000_add_licensed_locations_to_tenants.php
MODEL=app/Models/Tenant.php
CTRL=app/Http/Controllers/Tenant/LocationController.php
VIEW=resources/views/tenant/locations/index.blade.php
RES=app/Filament/Resources/TenantResource.php

for f in "$MODEL" "$CTRL" "$VIEW" "$RES"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-LOCGATE" "$CTRL"; then
  echo "Already applied (MARKER-LOCGATE present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- migration
if [ -f "$MIG" ]; then echo "ok   migration already present"; else
cat <<'EOF' > "$MIG"
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// MARKER-LOCGATE — how many locations a tenant is licensed for.
// Hand-set by master admin today; derived from the base subscription quantity
// once per-location billing lands (Aug 6 decision).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('licensed_locations')->default(1)->after('plan_tier');
        });

        // Nobody becomes retroactively over-cap: grant each tenant at least
        // what they are already running.
        DB::statement("
            UPDATE tenants t
            SET licensed_locations = GREATEST(1, (
                SELECT COUNT(*) FROM tenant_locations l
                WHERE l.tenant_id = t.id AND l.is_active = 1
            ))
        ");
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('licensed_locations');
        });
    }
};
EOF
echo "ok   migration created"; fi

# ---------------------------------------------------------------- model
python3 - "$MODEL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """        'license_id', 'subdomain', 'custom_domain', 'plan_tier', 'name',"""
new = """        'license_id', 'subdomain', 'custom_domain', 'plan_tier', 'licensed_locations', 'name',"""
if src.count(old) != 1:
    print(f"FAIL fillable: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   licensed_locations fillable")

anchor = """    /**
     * MARKER-PATCH-162 — multi_location_active"""
helper = """    /**
     * MARKER-LOCGATE — can this tenant create another location?
     *
     * Counts ACTIVE locations only, so archiving one frees a slot. The
     * allowance is hand-set in master admin today; when per-location billing
     * lands it becomes derived from the subscription quantity and this method
     * does not change.
     */
    public function canAddLocation(): bool
    {
        return $this->activeLocationCount() < (int) ($this->licensed_locations ?? 1);
    }

    /** MARKER-LOCGATE */
    public function activeLocationCount(): int
    {
        return (int) $this->locations()->where('is_active', true)->count();
    }

    /**
     * MARKER-PATCH-162 — multi_location_active"""

if src.count(anchor) != 1:
    print(f"FAIL helper anchor: found {src.count(anchor)} times"); sys.exit(1)
src = src.replace(anchor, helper, 1)
print("ok   canAddLocation() + activeLocationCount()")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """        // NOTE: gate (Branded+ tier / additional_locations addon / quantity)
        // is deferred. When ready, add the check here."""

new = """        // MARKER-LOCGATE — the gate this comment used to defer. A location is
        // priced at the base subscription, so an ungated create is revenue
        // straight out the door. Server-side because hiding the button is not
        // a control.
        if (! $tenant->canAddLocation()) {
            $allowed = (int) ($tenant->licensed_locations ?? 1);

            return back()->with('error',
                'This account is licensed for ' . $allowed . ' ' .
                \\Illuminate\\Support\\Str::plural('location', $allowed) .
                '. Get in touch to add another.'
            );
        }"""

if src.count(old) != 1:
    print(f"FAIL store gate: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   store() gate")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- tenant view
python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="loc-add-toggle">+ Add location</button>
  </div>"""

new = """  {{-- MARKER-LOCGATE — at the cap the button goes away and says why, rather
       than letting someone fill in a form that the server will refuse. --}}
  <div class="ia-page-actions">
    @if($currentTenant->canAddLocation())
      <button type="button" class="ia-btn ia-btn--primary" id="loc-add-toggle">+ Add location</button>
    @else
      <span style="font-size:12.5px;opacity:.55">
        Licensed for {{ (int) ($currentTenant->licensed_locations ?? 1) }}
        {{ \\Illuminate\\Support\\Str::plural('location', (int) ($currentTenant->licensed_locations ?? 1)) }}
        &middot; get in touch to add another
      </span>
    @endif
  </div>"""

if src.count(old) != 1:
    print(f"FAIL add button: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   +Add button gated")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- master admin
python3 - "$RES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """                Forms\\Components\\Select::make('onboarding_status')"""
new = """                // MARKER-LOCGATE — the allowance. A location bills at the base
                // subscription rate, so raising this is a pricing decision.
                Forms\\Components\\TextInput::make('licensed_locations')
                    ->label('Licensed locations')
                    ->helperText('Each location beyond the first bills at the tenant\\'s plan rate.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(1)
                    ->required(),
                Forms\\Components\\Select::make('onboarding_status')"""

if src.count(old) != 1:
    print(f"FAIL admin field: anchor found {src.count(old)} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   master admin allowance field")

open(path, 'w').write(src)
PY

php -l "$MODEL"
php -l "$CTRL"
php -l "$RES"

echo ""
echo "SUCCESS — apply-location-allowance-gate applied."
echo "Existing multi-location tenants keep what they have (backfilled)."
echo "Set the number on the tenant record in master admin to allow more."
