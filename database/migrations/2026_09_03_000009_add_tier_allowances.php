<?php
// MARKER-ALLOWANCE-TIERS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // { "starter": 250, "branded": 750, "scale": 2000, "custom": 0 }
            // A column per tier would need a migration every time a tier is
            // added; this does not.
            $table->json('email_free_by_tier')->nullable()->after('email_free_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('email_free_by_tier');
        });
    }
};
