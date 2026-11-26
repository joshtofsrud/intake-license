<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-RENT-TO-BUY: a discount code can belong to one customer and come
// from a rental. Rent to Buy credits and their same-day parts codes are
// ordinary single-use codes underneath, so every surface that takes a code
// (register, drafts, checkout) honours them with no new money path.
// Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_discounts', 'customer_id')) {
                $table->uuid('customer_id')->nullable()->after('campaign_id');
                $table->index(['tenant_id', 'customer_id']);
            }
            if (! Schema::hasColumn('tenant_discounts', 'rental_id')) {
                $table->uuid('rental_id')->nullable()->after('customer_id');
                $table->index('rental_id');
            }
            if (! Schema::hasColumn('tenant_discounts', 'source')) {
                // null = made on the Discounts page; rent_to_buy | rent_to_buy_parts
                $table->string('source', 24)->nullable()->after('rental_id');
            }
            if (! Schema::hasColumn('tenant_discounts', 'parent_id')) {
                // the parts code points at the credit whose use created it
                $table->uuid('parent_id')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_discounts', function (Blueprint $table) {
            foreach (['parent_id', 'source', 'rental_id', 'customer_id'] as $c) {
                if (Schema::hasColumn('tenant_discounts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
