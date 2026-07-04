<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-511 — customer "need it by" deadline (naive tenant-local date).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->date('need_by')->nullable()->after('appointment_time');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropColumn('need_by');
        });
    }
};
