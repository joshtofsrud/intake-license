<?php

// MARKER-QBP-FIXES

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QBP category ids are descriptive slugs, not numbers:
 *   c1232_tubeless_system_enhancements
 *   c_k1044_electronic_shift_part_sram
 *
 * 32 chars truncated them, which MySQL refused in strict mode — 152 rows lost
 * on the first full sync. Truncation would have been worse than the error:
 * two categories sharing a prefix would silently become one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            $t->string('category_id', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not narrowing again — rows written since would fail.
    }
};
