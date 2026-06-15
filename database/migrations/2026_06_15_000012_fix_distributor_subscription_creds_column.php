<?php
// MARKER-PATCH-HLC7A1

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * credentials_encrypted holds Laravel `encrypted:array` ciphertext — a string,
 * not JSON. A json column type rejects it on MySQL. Retype to text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $table) {
            $table->text('credentials_encrypted')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $table) {
            $table->json('credentials_encrypted')->nullable()->change();
        });
    }
};
