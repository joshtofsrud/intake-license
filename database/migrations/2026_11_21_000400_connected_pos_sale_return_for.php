<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-POS-RETURN-FOR: which sale a Connected POS return was for. Lets a web order that
 * Intake sent there show that it was refunded in the Connected POS. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_pos_sales', 'return_for_external_id')) {
            Schema::table('tenant_pos_sales', function (Blueprint $t) {
                $t->string('return_for_external_id', 100)->nullable();
                $t->index(['tenant_id', 'return_for_external_id'], 'tps_sale_return_for');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_pos_sales', 'return_for_external_id')) {
            Schema::table('tenant_pos_sales', function (Blueprint $t) {
                $t->dropIndex('tps_sale_return_for');
                $t->dropColumn('return_for_external_id');
            });
        }
    }
};
