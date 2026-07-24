<?php

// MARKER-SO-SALE-LINK — special orders created from the register had NO link
// to the sale or line that requested them (only the browser's cart held the
// id), so removing a line, discarding a draft, or abandoning a cart left the
// order stranded in "needed" forever. These columns are what any cleanup —
// immediate or swept — has to hang off.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->uuid('sale_id')->nullable()->index();
            $t->uuid('sale_item_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $t) {
            $t->dropColumn(['sale_id', 'sale_item_id']);
        });
    }
};
