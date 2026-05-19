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
