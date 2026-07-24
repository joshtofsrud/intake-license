<?php

// MARKER-SO-PLACEMENT — the threshold at which a vendor ships free. Optional:
// the placement board only shows a freight bar for vendors that have one set,
// rather than inventing a number.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->integer('free_freight_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_vendors', function (Blueprint $t) {
            $t->dropColumn('free_freight_cents');
        });
    }
};
