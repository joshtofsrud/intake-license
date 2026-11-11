<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-ECOM-STOCK-GUARD: a special order can now start from an online order
 * (a shopper bought more of an oversell-enabled item than was on the shelf).
 * Widening the enum only: every existing value stays.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE tenant_special_orders MODIFY COLUMN created_from ENUM('register','appointment','item','manual','booking','online') NOT NULL DEFAULT 'manual'");
        }
    }

    public function down(): void
    {
        // Left wide on purpose: narrowing would fail if any online rows exist.
    }
};
