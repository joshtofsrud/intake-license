<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-SEC-SECTIONS: Orders, Gift Cards, Storefront and Discounts
 * became areas in Roles & access. Before this every role could open them, so
 * every role that lists its areas gets them added: nobody loses access on the
 * day, and each shop unticks them where they don't belong. Roles with no list
 * (Owner, and roles never trimmed) already have everything.
 */
return new class extends Migration
{
    private const NEW = ['orders', 'gift_cards', 'storefront', 'discounts'];
    private const EVERYONE = ['orders', 'gift_cards'];
    private const MANAGERS = ['storefront', 'discounts'];

    public function up(): void
    {
        $all = array_keys(\App\Support\SectionRegistry::all());
        DB::table('tenant_roles')->orderBy('id')->chunk(500, function ($roles) use ($all) {
            foreach ($roles as $r) {
                if ($r->is_system && $r->name === 'Owner') continue;
                $staffy = ($r->is_system && $r->name === 'Staff')
                    || DB::table('tenant_users')->where('role_id', $r->id)->where('role', 'staff')->exists();
                $s = $r->sections === null ? null : json_decode($r->sections, true);
                if ($s === null) {
                    // full access: only a Staff-level role changes, so its people
                    // still can't open Discounts and Storefront, as before
                    if ($staffy) {
                        DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode(array_values(array_diff($all, self::MANAGERS)))]);
                    }
                    continue;
                }
                if (! is_array($s)) continue;
                $add = $staffy ? self::EVERYONE : array_merge(self::EVERYONE, self::MANAGERS);
                $merged = array_values(array_unique(array_merge($s, $add)));
                if ($merged !== $s) {
                    DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode($merged)]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('tenant_roles')->whereNotNull('sections')->orderBy('id')->chunk(500, function ($roles) {
            foreach ($roles as $r) {
                $s = json_decode($r->sections, true);
                if (! is_array($s)) continue;
                DB::table('tenant_roles')->where('id', $r->id)->update(['sections' => json_encode(array_values(array_diff($s, self::NEW)))]);
            }
        });
    }
};
