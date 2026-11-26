<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RENTAL-PRICING: "each additional day" rate on rental models.
// Blank = every day at the daily rate (today's behaviour). Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_rental_models', 'additional_day_rate_cents')) {
                $table->unsignedInteger('additional_day_rate_cents')->nullable()->after('daily_rate_cents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_rental_models', 'additional_day_rate_cents')) {
                $table->dropColumn('additional_day_rate_cents');
            }
        });
    }
};
