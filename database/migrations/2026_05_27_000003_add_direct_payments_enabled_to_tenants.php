<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-169 — Direct Payments bridge feature.
 *
 * Boolean toggle controlled by master-admin. When true, the tenant\'s
 * Settings -> Payments tab reveals a "Register card payments" section
 * where they paste their own Stripe keys for register card-sales.
 *
 * This is the bridge that gets us to launch while we work out the
 * Connect architecture. Tenants enabled here run direct (their Stripe,
 * their money, their liability) instead of through Connect.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('direct_payments_enabled')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('direct_payments_enabled');
        });
    }
};
