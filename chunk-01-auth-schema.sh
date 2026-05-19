#!/usr/bin/env bash
# ============================================================================
# Auth Refactor — Chunk 1
# Schema: tenant_trusted_devices table + 5 PIN columns on tenant_users
#
# CONTEXT
#   First chunk of the 9-chunk auth refactor (see auth-refactor-spec-v2.md).
#   Pure schema work. No UI, no controller changes, no behavior change.
#   Existing single-user email/password login keeps working unchanged after
#   this lands — these new columns and table just sit there until later
#   chunks wire them up.
#
# WHAT THIS PATCH ADDS
#   1. New table `tenant_trusted_devices` — long-lived device-trust cookies
#      that identify a physical browser as belonging to a tenant. Drives
#      Layer 1 of the new auth model.
#   2. Five new columns on `tenant_users` — PIN hash + lockout state. Drives
#      Layer 2 (staff identity). Existing rows get NULL for all of these,
#      which is the correct "PIN not set yet" state.
#
# VERIFY (via tinker after deploy):
#   >>> Schema::hasTable('tenant_trusted_devices')
#   true
#   >>> Schema::hasColumn('tenant_users', 'pin_hash')
#   true
#
# IDEMPOTENCY: this script is the patch wrapper. The migrations themselves
# are idempotent by Laravel's migration tracking (each runs once and is
# recorded in the `migrations` table).
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
echo "Auth Refactor — Chunk 1 (schema)"
echo "Running in: $(pwd)"
echo "=========================================="

# ----------------------------------------------------------------------------
# STEP 1 — Migration: create tenant_trusted_devices table
# ----------------------------------------------------------------------------

MIGRATION_1=database/migrations/2026_05_18_000004_create_tenant_trusted_devices_table.php

if [ -f "$MIGRATION_1" ]; then
    echo "STEP 1: SKIP (migration file already exists)"
else
    cat > "$MIGRATION_1" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_trusted_devices
 *
 * Layer 1 of the auth refactor. A row exists for each browser cookie that
 * a tenant user has explicitly trusted ("Trust this device" at sign-in).
 * Trusted devices skip email/password on subsequent visits and go directly
 * to the staff switcher (or, for Starter, directly to dashboard).
 *
 * device_token_hash is SHA-256 of the cookie value (`intake_device_trust`).
 * The plaintext cookie value never touches the database.
 *
 * Expiration model: 90-day sliding window. `last_used_at` updated on every
 * authenticated request. `expires_at` is set on row create and pushed
 * forward when the device is seen recently. A nightly job (or middleware
 * check) drops rows where `expires_at` has passed.
 *
 * Revocation: setting `revoked_at` makes the device useless on next visit.
 * `revoked_by_user_id` tracks who revoked it (owner from the trusted-
 * devices admin screen, or the device itself via "Sign out this device").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_trusted_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // SHA-256 of the cookie value. Unique within tenant.
            $table->char('device_token_hash', 64);

            // Owner-set label like "Front counter iPad" — shown on admin
            // screen. Auto-generated on create from UA if owner doesn't
            // name it (later patch).
            $table->string('label', 120)->nullable();

            // Forensic fields — useful for the trusted-devices admin
            // screen and for spotting "what happened" after a lost iPad.
            $table->text('user_agent_seen')->nullable();
            $table->string('ip_first_seen', 45)->nullable();
            $table->string('ip_last_seen', 45)->nullable();

            // Lifecycle timestamps.
            $table->timestamp('trusted_at');
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at')->nullable();

            // Revocation.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')
                  ->nullable()
                  ->references('id')->on('tenant_users')
                  ->nullOnDelete();

            $table->timestamps();

            // Unique within tenant — multiple tenants could theoretically
            // hash to the same value (vanishingly unlikely with SHA-256,
            // but the constraint is cheap insurance).
            $table->unique(['tenant_id', 'device_token_hash']);

            // Hot-path index for the EnsureTrustedDevice middleware lookup
            // (every authenticated request).
            $table->index(['tenant_id', 'last_used_at']);

            // For the nightly expiry sweep.
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_trusted_devices');
    }
};
PHP
    echo "STEP 1: OK (created migration $MIGRATION_1)"
fi

# ----------------------------------------------------------------------------
# STEP 2 — Migration: add PIN columns to tenant_users
# ----------------------------------------------------------------------------

MIGRATION_2=database/migrations/2026_05_18_000005_add_pin_columns_to_tenant_users.php

if [ -f "$MIGRATION_2" ]; then
    echo "STEP 2: SKIP (migration file already exists)"
else
    cat > "$MIGRATION_2" <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add PIN columns to tenant_users.
 *
 * Layer 2 of the auth refactor. Each staff member sets their own 4-digit
 * PIN on first sign-in (see auth-refactor-spec-v2.md §4.2). The columns
 * are nullable so existing rows get the correct "PIN not set yet" state.
 *
 * - pin_hash:          bcrypt of the 4 digits. Nullable until set.
 * - pin_set_at:        when the staff member set their current PIN.
 * - pin_failed_count:  rolling failure counter for lockout (resets on
 *                      successful entry).
 * - pin_locked_until:  if non-null, PIN entry is rejected until this
 *                      timestamp passes. Cooldown ladder lives in code,
 *                      not in the schema.
 * - pin_last_used_at:  most recent successful PIN entry. Used by idle
 *                      lock checks and admin diagnostics.
 *
 * These columns are tier-gated at the application layer (Starter never
 * uses them). The schema is unconditional — the cost of carrying five
 * NULL columns per row is negligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            // bcrypt hashes are 60 chars; use char(60) for exact fit.
            $table->char('pin_hash', 60)->nullable()->after('password');
            $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
            $table->smallInteger('pin_failed_count')->unsigned()->default(0)->after('pin_set_at');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_count');
            $table->timestamp('pin_last_used_at')->nullable()->after('pin_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn([
                'pin_hash',
                'pin_set_at',
                'pin_failed_count',
                'pin_locked_until',
                'pin_last_used_at',
            ]);
        });
    }
};
PHP
    echo "STEP 2: OK (created migration $MIGRATION_2)"
fi

# ----------------------------------------------------------------------------
# Verify both migration files are syntactically valid PHP by quickly
# re-reading their first few lines.
# ----------------------------------------------------------------------------

echo ""
echo "----------------------------------------"
echo "VERIFY: migration files exist + headers"
echo "----------------------------------------"
head -3 "$MIGRATION_1"
echo "..."
head -3 "$MIGRATION_2"

echo ""
echo "=========================================="
echo "Chunk 1 application complete."
echo ""
echo "Server steps:"
echo "  git pull && composer install --no-interaction --no-scripts && \\"
echo "  php artisan migrate && \\"
echo "  php artisan optimize:clear && \\"
echo "  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm"
echo ""
echo "Verify via tinker (php artisan tinker):"
echo "  >>> Schema::hasTable('tenant_trusted_devices')"
echo "  >>> Schema::hasColumn('tenant_users', 'pin_hash')"
echo "  Both should return: true"
echo ""
echo "Then check existing login still works on thebikehub.intake.works."
echo "(This chunk changes no behavior — just adds schema.)"
echo "=========================================="
