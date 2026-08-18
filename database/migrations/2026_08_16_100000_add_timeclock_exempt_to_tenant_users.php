<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-TIMECLOCK-EXEMPT — owners/salaried staff who never clock in
// shouldn't see the persistent clock-in nudge on every page load.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->boolean('exempt_from_timeclock')->default(false)->after('is_active');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('exempt_from_timeclock');
        });
    }
};
