<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-POS-ITEM-DETAILS: Lightspeed X needs suppliers:read and suppliers:write to match
 * and create the supplier on an item. Added to the X adapter's scopes when they aren't
 * there; the shop reconnects once for Lightspeed to grant them.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('platform_pos_adapters')->where('code', 'lightspeed-x')->get(['id', 'scopes']) as $a) {
            $have = preg_split('/\s+/', trim((string) $a->scopes)) ?: [];
            $add = array_diff(['suppliers:read', 'suppliers:write'], $have);
            if ($add) {
                DB::table('platform_pos_adapters')->where('id', $a->id)->update(['scopes' => trim(implode(' ', array_merge($have, $add)))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('platform_pos_adapters')->where('code', 'lightspeed-x')->get(['id', 'scopes']) as $a) {
            $keep = array_diff(preg_split('/\s+/', trim((string) $a->scopes)) ?: [], ['suppliers:read', 'suppliers:write']);
            DB::table('platform_pos_adapters')->where('id', $a->id)->update(['scopes' => implode(' ', $keep)]);
        }
    }
};
