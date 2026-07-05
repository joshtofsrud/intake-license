<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-PATCH-544 — display_subtitle on the platform catalog was VARCHAR(128);
// composed subtitles (e.g. with {allattr} or several attrs) overflow it and the
// 1406 "Data too long" error killed every recompose run. TEXT to match the
// tenant items column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->text('display_subtitle')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('display_subtitle', 128)->nullable()->change();
        });
    }
};
