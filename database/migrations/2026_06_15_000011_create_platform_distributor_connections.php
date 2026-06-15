<?php
// MARKER-PATCH-HLC6

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PLATFORM (master-admin) credentials per distributor — the key that builds
 * the shared catalog (tier-1). Distinct from a tenant's own key, which lives on
 * tenant_distributor_catalog_subscriptions and unlocks their cost/availability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_distributor_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('distributor_code', 32)->unique();
            $table->text('api_key')->nullable();         // encrypted
            $table->string('region', 8)->default('us');
            $table->string('auth_style', 40)->default('authorization_apikey');
            $table->string('base_url', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 16)->nullable();
            $table->string('last_test_message', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_distributor_connections');
    }
};
