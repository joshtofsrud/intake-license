<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REPORT-BUILDER-CORE: saved custom reports (additive, nothing else touched).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_saved_reports')) {
            return;
        }

        Schema::create('tenant_saved_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();          // who built it
            $table->string('name', 120);
            $table->string('dataset', 24);
            $table->json('config');                       // validated against the catalog on every run
            $table->string('visibility', 12)->default('me'); // me | team
            $table->timestamps();

            $table->index(['tenant_id', 'user_id'], 'tsr_tenant_user_idx');
            $table->index(['tenant_id', 'visibility'], 'tsr_tenant_vis_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_saved_reports');
    }
};
