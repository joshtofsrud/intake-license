<?php

// MARKER-SO-AUTOVENDOR — records WHICH rule chose a vendor, so an automatic
// assignment is explainable on the row rather than mysterious, and so
// hand-picked choices are distinguishable from automatic ones.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            // preferred | lowest_price | manual | null (none assigned)
            $t->string('vendor_assigned_rule', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->dropColumn('vendor_assigned_rule');
        });
    }
};
