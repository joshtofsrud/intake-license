<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->boolean('needs_time_review')->default(false)->after('cleanup_after_minutes_snapshot');
            $table->index(['tenant_id', 'needs_time_review'], 'tenant_appts_needs_review_idx');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dateTime('last_booking_mode_switch_at')->nullable()->after('booking_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $table) {
            $table->dropIndex('tenant_appts_needs_review_idx');
            $table->dropColumn('needs_time_review');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('last_booking_mode_switch_at');
        });
    }
};
