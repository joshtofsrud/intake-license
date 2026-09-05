<?php
// MARKER-BRAND-ECHO

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Strip a doubled leading brand from stored names. These rows predate the
 * composer's MARKER-TITLE-DEDUP (or came through the raw-name fallback), so
 * the feed's own "Maxxis Maxxis Minion DHF Tire" is sitting on items today.
 * Scoped to names that literally begin with that row's brand twice, so a
 * shop's own naming is never touched. Idempotent: once stripped, the
 * condition no longer matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = DB::update("
            UPDATE platform_distributor_catalogs
            SET display_name = SUBSTRING(display_name, CHAR_LENGTH(TRIM(manufacturer)) + 2)
            WHERE TRIM(COALESCE(manufacturer, '')) <> ''
              AND LOWER(display_name) LIKE LOWER(CONCAT(TRIM(manufacturer), ' ', TRIM(manufacturer), ' %'))
        ");

        $items = DB::update("
            UPDATE tenant_inventory_items i
            JOIN platform_distributor_catalogs c ON c.id = i.distributor_catalog_id
            SET i.name = SUBSTRING(i.name, CHAR_LENGTH(TRIM(c.manufacturer)) + 2)
            WHERE TRIM(COALESCE(c.manufacturer, '')) <> ''
              AND LOWER(i.name) LIKE LOWER(CONCAT(TRIM(c.manufacturer), ' ', TRIM(c.manufacturer), ' %'))
        ");

        Log::info("MARKER-BRAND-ECHO: de-echoed {$catalog} catalog display_names, {$items} tenant item names");
    }

    public function down(): void
    {
        // The echo was a defect; there is nothing to restore.
    }
};
