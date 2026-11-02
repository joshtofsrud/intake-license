<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REPORT-BUILDER-V2: when a saved report was last opened, and each
// person's own calculations. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_saved_reports') && !Schema::hasColumn('tenant_saved_reports', 'last_run_at')) {
            Schema::table('tenant_saved_reports', function (Blueprint $table) {
                $table->timestamp('last_run_at')->nullable();
            });
        }

        if (!Schema::hasTable('tenant_report_calcs')) {
            Schema::create('tenant_report_calcs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('user_id')->nullable();
                $table->string('name', 80);
                $table->string('formula', 300);
                $table->string('type', 2)->default('d');
                $table->timestamps();
                $table->index(['tenant_id', 'user_id'], 'trc_tenant_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_report_calcs');
        if (Schema::hasColumn('tenant_saved_reports', 'last_run_at')) {
            Schema::table('tenant_saved_reports', function (Blueprint $table) {
                $table->dropColumn('last_run_at');
            });
        }
    }
};
