<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_appointment_items', function (Blueprint $t) {
            $t->integer('price_cents_override')->nullable()->after('price_cents');
            $t->integer('duration_minutes_override')->nullable()->after('duration_minutes_snapshot');
        });

        Schema::table('tenant_appointment_addons', function (Blueprint $t) {
            $t->integer('price_cents_override')->nullable()->after('price_cents');
            $t->integer('duration_minutes_override')->nullable()->after('duration_minutes_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_items', function (Blueprint $t) {
            $t->dropColumn(['price_cents_override', 'duration_minutes_override']);
        });

        Schema::table('tenant_appointment_addons', function (Blueprint $t) {
            $t->dropColumn(['price_cents_override', 'duration_minutes_override']);
        });
    }
};
