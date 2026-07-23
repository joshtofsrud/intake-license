<?php

// MARKER-REFUND-QTY — refund lines now link to the exact original sale line
// (instead of being matched by name) and record where the returned goods
// went. Both nullable: legacy refund rows keep working, and the service
// falls back to best-effort matching when original_sale_item_id is absent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $t) {
            $t->uuid('original_sale_item_id')->nullable()->index();
            $t->string('disposition', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sale_items', function (Blueprint $t) {
            $t->dropColumn(['original_sale_item_id', 'disposition']);
        });
    }
};
