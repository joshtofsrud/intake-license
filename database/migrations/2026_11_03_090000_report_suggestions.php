<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-REPORT-SUGGEST: "Suggested builds" on Reports › Custom, refreshed nightly. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_report_suggestions')) {
            Schema::create('tenant_report_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->unsignedTinyInteger('position')->default(0);
                $table->string('icon', 30);
                $table->string('tone', 10)->default('warn');            // good | warn | bad
                $table->string('amount', 30);
                $table->string('amount_label', 60);
                $table->string('title', 200);
                $table->string('sub', 255);
                $table->string('why', 400);
                $table->string('dataset', 20);
                $table->json('config');
                $table->boolean('managers_only')->default(false);     // built from staff data
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'position']);
            });
        }
        if (!Schema::hasTable('tenant_report_suggest_checks')) {
            Schema::create('tenant_report_suggest_checks', function (Blueprint $table) {
                $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
                $table->date('checked_on');                            // shop's local date of the last check
                $table->timestamp('checked_at')->nullable();
                $table->unsignedTinyInteger('found')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_report_suggestions');
        Schema::dropIfExists('tenant_report_suggest_checks');
    }
};
