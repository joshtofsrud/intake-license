<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-MERGE-AFTER — where a merged-away item went.
 *
 * Without it a merged item is indistinguishable from an archived one: the
 * page offers Restore, and restoring brings back an empty husk whose stock,
 * history and vendors now belong to another record.
 *
 * Nullable and unconstrained by a foreign key on purpose — the survivor could
 * itself be merged later, and a cascade there would erase the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->uuid('merged_into_id')->nullable()->after('id');
            $table->index(['tenant_id', 'merged_into_id'], 'tii_merged_into_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropIndex('tii_merged_into_idx');
            $table->dropColumn('merged_into_id');
        });
    }
};
