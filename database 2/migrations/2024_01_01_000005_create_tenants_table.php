<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // A tenant must be backed by an active premium license with saas_access=true
            $table->foreignUuid('license_id')->constrained()->cascadeOnDelete();

            // Subdomain on intake.works — e.g. "spokes" → spokes.intake.works
            $table->string('subdomain', 63)->unique()->nullable();

            // Custom domain for branded/custom tier — e.g. "book.spokescycles.com"
            $table->string('custom_domain')->unique()->nullable();

            // Which feature tier this tenant is on (may differ from license tier over time)
            $table->enum('plan_tier', ['basic', 'branded', 'custom'])->default('basic');

            // Tenant display name — shown in the master admin
            $table->string('name');

            $table->boolean('is_active')->default(true)->index();

            // JSON for any tenant-specific config that doesn't warrant its own column yet
            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
