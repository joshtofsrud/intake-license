<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CATEGORY-SLUG-FREED: deleted categories still held their slug, so
 * adding a category with a deleted one's name hit the unique index (500).
 * One statement, however many rows: each deleted row's slug gets a suffix
 * from its own id, which can't collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_inventory_categories')) return;
        DB::statement("UPDATE tenant_inventory_categories SET slug = CONCAT(LEFT(slug, 110), '~del-', RIGHT(id, 12)) WHERE deleted_at IS NOT NULL AND slug NOT LIKE '%~del-%'");
    }

    public function down(): void
    {
        // nothing to undo: the old slugs were only blocking new names
    }
};
