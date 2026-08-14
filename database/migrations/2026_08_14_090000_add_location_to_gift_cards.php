<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-GC-LOCATION — which location issued a card, and which location a
 * redemption happened at.
 *
 * Nullable on purpose: a card bought online has no location until the
 * checkout-level pickup choice exists, and a tenant may have no locations
 * configured at all. Nothing reads these as required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_gift_cards', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('issued_by_user_id'); // issuing location
            $table->index(['tenant_id', 'location_id'], 'tgc_tenant_location_idx');
        });

        Schema::table('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('sale_id'); // where this movement happened
        });

        // Backfill from the sale each row already points at, so existing
        // cards are not blank for everything sold to date.
        DB::statement("
            UPDATE tenant_gift_cards gc
            JOIN tenant_sales s ON s.id = gc.issued_sale_id
            SET gc.location_id = s.location_id
            WHERE gc.location_id IS NULL AND s.location_id IS NOT NULL
        ");

        DB::statement("
            UPDATE tenant_gift_card_transactions t
            JOIN tenant_sales s ON s.id = t.sale_id
            SET t.location_id = s.location_id
            WHERE t.location_id IS NULL AND s.location_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('tenant_gift_cards', function (Blueprint $table) {
            $table->dropIndex('tgc_tenant_location_idx');
            $table->dropColumn('location_id');
        });
        Schema::table('tenant_gift_card_transactions', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
