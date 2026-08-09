<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-BRAND-TOTALS — additive only (expand/contract rule): delta runs need
// somewhere truthful to put "seen but unchanged".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_brand_sync_status', function (Blueprint $table) {
            $table->unsignedInteger('skipped')->default(0)->after('written');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_brand_sync_status', function (Blueprint $table) {
            $table->dropColumn('skipped');
        });
    }
};
