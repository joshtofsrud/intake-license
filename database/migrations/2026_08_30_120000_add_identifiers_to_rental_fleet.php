<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-FLEET-IDENT — per-model identifier definitions + per-unit values.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_rental_models', 'identifiers')) {
                $table->json('identifiers')->nullable();
            }
        });
        Schema::table('tenant_rental_units', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_rental_units', 'identifier_values')) {
                $table->json('identifier_values')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rental_models', function (Blueprint $table) {
            $table->dropColumn('identifiers');
        });
        Schema::table('tenant_rental_units', function (Blueprint $table) {
            $table->dropColumn('identifier_values');
        });
    }
};
