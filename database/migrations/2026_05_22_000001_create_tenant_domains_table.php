<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_domains
 *
 * One row per (tenant, hostname). Replaces the single
 * tenants.custom_domain column with proper multi-domain support
 * plus state machine for verification + cert issuance.
 *
 * Architecture spec: see Custom Domains design doc, Section 3.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // The hostname itself. Globally unique — no two tenants can
            // claim the same domain.
            $table->string('hostname', 253)->unique();

            // Exactly one per tenant when any exist. Enforced via observer
            // since MySQL doesn't support partial unique indexes.
            $table->boolean('is_primary')->default(false);

            // What this domain is used for. Most tenants use 'both'.
            // 'admin' / 'booking' lets a tenant split admin from public
            // booking onto different domains.
            $table->enum('role', ['admin', 'booking', 'both'])->default('both');

            // When the primary is hit, this domain redirects to it (default)
            // or serves directly (rare — bilingual / multi-brand cases).
            // Ignored when is_primary=true.
            $table->enum('alias_mode', ['redirect', 'serve_direct'])
                ->default('redirect');

            // State machine. See design doc Section 4.
            $table->enum('status', [
                'pending_dns',
                'verifying',
                'issuing_cert',
                'active',
                'error',
                'suspended',
            ])->default('pending_dns');

            // Random token. Tenant adds this as a TXT record at
            // _intake-verify.theirdomain.com to prove ownership.
            $table->string('verification_token', 64);

            // Cloudflare's custom-hostname ID. NULL until we register
            // the domain via the CF API (happens in patch 117/118).
            $table->string('cloudflare_hostname_id', 64)->nullable();

            // Polling / health observability.
            $table->timestamp('last_check_at')->nullable();
            $table->string('last_check_status', 40)->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->text('last_error_message')->nullable();

            // Lifecycle timestamps.
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();

            $table->timestamps();

            // Indexes for the background poller and admin dashboard.
            $table->index(['status', 'last_check_at']);
            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
