#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 2
# additional_users capability + Tenant accessors
#
# CONTEXT
#   Wires the tier gate that subsequent chunks use to decide whether the PIN
#   tier is active for a tenant. Follows the six-layer gating pattern (see
#   gating-system-handoff-2026-05-16.md).
#
# WHAT THIS PATCH ADDS
#   1. Addon catalog row 'additional_users' — included in Branded + Scale.
#      Not self-serve. Bundled, not sold separately.
#   2. Two accessors on the Tenant model:
#      - getAdditionalUsersEnabledAttribute()  — "tenant CAN add more users"
#      - getPinTierActiveAttribute()            — "PIN tier IS active right now"
#        (capability on AND tenant has 2+ users)
#
#   Two separate accessors because they answer two different questions:
#   - "Should the Add User button be visible?"  → additional_users_enabled
#   - "Should this device use PIN auth?"        → pin_tier_active
#
#   A Branded tenant with only one user has the CAPABILITY but isn't yet
#   USING it, so PIN tier stays off until they add the second user. This
#   keeps the upgrade moment legible.
#
# NO BEHAVIOR CHANGE YET — neither accessor is consumed by middleware yet.
# Subsequent chunks read these to decide whether to enforce PIN flows.
#
# IDEMPOTENCY: migration is Laravel-tracked. Accessor injection checks for
# the method name before editing the model file.
# ============================================================================

set -euo pipefail

APP_ROOT="${INTAKE_APP_ROOT:-/var/www/intake}"
if [ ! -d "$APP_ROOT" ]; then
    if [ -f "./artisan" ] && [ -d "./app/Models" ]; then
        APP_ROOT="$(pwd)"
    else
        echo "ERROR: APP_ROOT '$APP_ROOT' does not exist." >&2
        exit 1
    fi
fi
cd "$APP_ROOT"

echo "=========================================="
echo "Auth Refactor — Chunk 2 (additional_users capability)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — Migration: seed the 'additional_users' addon row.
# ----------------------------------------------------------------------------

MIGRATION=database/migrations/2026_05_18_000006_add_additional_users_addon.php

if [ -f "$MIGRATION" ]; then
    echo "STEP 1: SKIP (migration file already exists)"
else
    cat > "$MIGRATION" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the 'additional_users' addon row.
 *
 * Bundled with Branded + Scale, not separately sold. Gates two things:
 *   1. The "Add staff member" action on the staff admin screen
 *   2. The PIN tier auth flow (Layers 2 + 3 of the auth refactor)
 *
 * Starter tenants are hard-capped at 1 user. They never see Add User,
 * never see PIN flows. Branded+ tenants can add users; once they have
 * 2+, the PIN tier activates automatically.
 *
 * Pattern matches 'extended_reports' (and 'retail') — capability flag,
 * not a sellable product, hence is_self_serve=0 and price 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('addons')->where('code', 'additional_users')->exists();
        if ($exists) {
            return;
        }

        $now = now();

        DB::table('addons')->insert([
            'code' => 'additional_users',
            'name' => 'Additional Users',
            'category' => 'team',
            'description' => 'Add team members with their own sign-in. Each staff member gets a 4-digit PIN for quick sign-in on shared devices. Included free with Branded and Scale.',
            'tooltip' => 'Multiple staff sign-ins with PIN auth on shared devices. Idle lock and per-action confirmation for sensitive operations.',
            'price_cents' => 0,
            'billing_cadence' => 'monthly',
            'price_display_override' => 'Included',
            'included_in_plans' => json_encode(['branded', 'scale']),
            'sort_order' => 110,
            'status' => 'active',
            'is_self_serve' => 0,
            'is_new' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('addons')->where('code', 'additional_users')->delete();
    }
};
PHP
    echo "STEP 1: OK (created migration $MIGRATION)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — Add two accessors to the Tenant model:
#   - additional_users_enabled  (capability check)
#   - pin_tier_active           (capability + count ≥ 2)
# ----------------------------------------------------------------------------

python3 <<'PY'
from pathlib import Path
p = Path('app/Models/Tenant.php')
s = p.read_text()

if "getAdditionalUsersEnabledAttribute" in s:
    print("STEP 2: SKIP (accessors already present)")
else:
    old = """    public function getExtendedReportsEnabledAttribute(): bool
    {
        return app(\\App\\Services\\FeatureAccessService::class)->hasAddon($this, 'extended_reports');
    }

    public function getPosEnabledAttribute(): bool"""

    new = """    public function getExtendedReportsEnabledAttribute(): bool
    {
        return app(\\App\\Services\\FeatureAccessService::class)->hasAddon($this, 'extended_reports');
    }

    /**
     * additional_users — does this tenant have the capability to add more
     * than one user? Drives the "Add staff member" button on the staff
     * admin screen, and Layer 1 of the pin_tier_active check below.
     */
    public function getAdditionalUsersEnabledAttribute(): bool
    {
        return app(\\App\\Services\\FeatureAccessService::class)->hasAddon($this, 'additional_users');
    }

    /**
     * pin_tier_active — is the PIN tier authentication flow active for
     * this tenant right now?
     *
     * Two conditions:
     *   1. additional_users capability is on (plan permits multiple users)
     *   2. The tenant actually has 2+ users (otherwise there's no one to
     *      distinguish between)
     *
     * A Branded tenant with one user has the capability but not the active
     * tier — they get the old email/password flow until they add a second
     * staff member, at which point the PIN tier turns on automatically.
     *
     * This is the check that the EnsureTrustedDevice and EnsurePinFresh
     * middleware will use to decide whether to enforce PIN flows. Starter
     * tenants always evaluate to false here.
     */
    public function getPinTierActiveAttribute(): bool
    {
        if (! $this->additional_users_enabled) {
            return false;
        }

        return $this->users()->count() >= 2;
    }

    public function getPosEnabledAttribute(): bool"""

    if s.count(old) != 1:
        print(f"STEP 2: ABORT (anchor matches {s.count(old)} times, expected 1)")
        raise SystemExit(1)

    s = s.replace(old, new)
    p.write_text(s)
    print("STEP 2: OK (added additional_users_enabled + pin_tier_active accessors)")
PY

# ----------------------------------------------------------------------------
# STEP 3 — Confirm $tenant->users() relation exists. The pin_tier_active
#          accessor calls $this->users()->count() — that relation needs
#          to exist on the Tenant model. Likely does, but verify.
# ----------------------------------------------------------------------------

if grep -q "public function users()" app/Models/Tenant.php; then
    echo "STEP 3: OK (users() relation exists on Tenant model)"
else
    echo "STEP 3: WARN — users() relation NOT found on Tenant model."
    echo "         pin_tier_active accessor will throw on read. Need to"
    echo "         add a hasMany(\\App\\Models\\Tenant\\TenantUser::class) before this ships."
    # Don't abort — let the patch finish so verification shows the gap clearly.
fi

# ----------------------------------------------------------------------------
# Verification
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: migration file"
echo "----------------------------------------"
head -3 "$MIGRATION"
echo "..."

echo ""
echo "----------------------------------------"
echo "VERIFY: new accessors on Tenant model"
echo "----------------------------------------"
grep -n "AdditionalUsersEnabledAttribute\|PinTierActiveAttribute" app/Models/Tenant.php || true

echo ""
echo "=========================================="
echo "Chunk 2 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan migrate && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Verify via tinker (php artisan tinker):"
echo "  >>> DB::table('addons')->where('code', 'additional_users')->exists()"
echo "  true"
echo "  >>> \\App\\Models\\Tenant::first()->additional_users_enabled"
echo "  (true if your tenant is on Branded/Scale; false on Starter)"
echo "  >>> \\App\\Models\\Tenant::first()->pin_tier_active"
echo "  (true if Branded+ AND 2+ users; false otherwise)"
echo ""
echo "No UI changes yet. Subsequent chunks consume these accessors."
echo "=========================================="
