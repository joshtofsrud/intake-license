<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-CONNECT: a shop keeps its own POS and runs its online store on Intake.
 *
 * platform_pos_adapters  — Intake's own app credentials, one row per POS adapter (master admin).
 * tenant_pos_connections — one shop's authorised link to its POS (tokens, account, what was found).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_pos_adapters', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('label', 120)->nullable();
            $t->boolean('is_active')->default(false);
            $t->text('client_id')->nullable();
            $t->text('client_secret')->nullable();
            $t->string('scopes', 500)->nullable();
            $t->string('authorize_url', 300)->nullable();
            $t->string('token_url', 300)->nullable();
            $t->string('api_base', 300)->nullable();
            $t->timestamps();
        });

        Schema::create('tenant_pos_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('adapter_code', 40);
            $t->string('status', 20)->default('connected');
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestamp('token_expires_at')->nullable();
            $t->string('external_account_id', 100)->nullable();
            $t->string('external_account_name', 200)->nullable();
            $t->json('locations')->nullable();
            $t->json('payment_types')->nullable();
            $t->uuid('connected_by')->nullable();
            $t->timestamp('connected_at')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->unique('tenant_id', 'tpos_one_per_tenant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_pos_connections');
        Schema::dropIfExists('platform_pos_adapters');
    }
};
