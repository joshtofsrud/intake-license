<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-383 — a hold needs its occupied duration to block the right window.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_pending_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_pending_bookings', 'total_duration_minutes')) {
                $table->unsignedInteger('total_duration_minutes')->default(0)->after('slot_weight');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_pending_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_pending_bookings', 'total_duration_minutes')) {
                $table->dropColumn('total_duration_minutes');
            }
        });
    }
};
