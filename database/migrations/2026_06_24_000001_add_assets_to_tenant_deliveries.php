<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-427 — snapshot of the bikes on a pickup/dropoff run.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant_deliveries', 'assets')) {
                $table->json('assets')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_deliveries', 'assets')) {
                $table->dropColumn('assets');
            }
        });
    }
};
