<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MARKER-POS-ITEM-FIELDS: whether the shop's own photo has gone to the Connected POS for this item. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_pos_items', 'image_sent_at')) {
            Schema::table('tenant_pos_items', function (Blueprint $t) {
                $t->timestamp('image_sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenant_pos_items', function (Blueprint $t) {
            $t->dropColumn('image_sent_at');
        });
    }
};
