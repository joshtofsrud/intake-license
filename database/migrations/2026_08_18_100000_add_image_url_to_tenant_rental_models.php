<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RENTAL-MODEL-PHOTOS — one marketing photo per rental model,
// rendered on every public rental surface.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('subtitle');
        });
    }
    public function down(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
