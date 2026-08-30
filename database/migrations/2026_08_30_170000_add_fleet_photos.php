<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-FLEET-PHOTOS — model photo set + the photo a unit uses.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_rental_models', 'photos')) {
                $table->json('photos')->nullable();
            }
        });
        Schema::table('tenant_rental_units', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_rental_units', 'photo_url')) {
                $table->string('photo_url', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->dropColumn('photos');
        });
        Schema::table('tenant_rental_units', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
};
