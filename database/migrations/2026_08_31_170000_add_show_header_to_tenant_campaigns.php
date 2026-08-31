<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-CAMPAIGN-HDR — per-campaign shop header toggle. Default true so
// existing campaigns keep the header they were built with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_campaigns', 'show_header')) {
                $table->boolean('show_header')->default(true)->after('preheader');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_campaigns', function (Blueprint $table) {
            $table->dropColumn('show_header');
        });
    }
};
