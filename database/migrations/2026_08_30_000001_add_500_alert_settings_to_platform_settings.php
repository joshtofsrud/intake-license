<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-500-ALERT — dashboard-controlled 500 alert emails.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_settings', 'alert_500_enabled')) {
                $table->boolean('alert_500_enabled')->default(false);
            }
            if (! Schema::hasColumn('platform_settings', 'alert_500_email')) {
                $table->string('alert_500_email')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['alert_500_enabled', 'alert_500_email']);
        });
    }
};
