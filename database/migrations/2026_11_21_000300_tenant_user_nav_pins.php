<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-NAV-PINS: each person's pinned menu items, in their order. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_users', 'nav_pins')) {
            Schema::table('tenant_users', function (Blueprint $t) {
                $t->json('nav_pins')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_users', 'nav_pins')) {
            Schema::table('tenant_users', fn (Blueprint $t) => $t->dropColumn('nav_pins'));
        }
    }
};
