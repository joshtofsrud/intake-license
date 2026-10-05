<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-MKT-HIDE-TABLET — "desktop" now starts at 1025px and tablets
 * (769–1024px) get their own Hide switch. intake.works sections already
 * hidden on desktop were hidden on tablets too, so they get Hide on tablet
 * switched on and nothing reappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        $platform = DB::table('tenants')->where('is_platform', true)->value('id');
        if (! $platform) return;

        DB::table('tenant_page_sections')->where('tenant_id', $platform)->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $r) {
                    $c = json_decode((string) $r->content, true);
                    if (! is_array($c) || empty($c['hide_on_desktop']) || array_key_exists('hide_on_tablet', $c)) continue;
                    if (in_array((string) $c['hide_on_desktop'], ['0', 'false'], true)) continue;
                    $c['hide_on_tablet'] = '1';
                    DB::table('tenant_page_sections')->where('id', $r->id)->update(['content' => json_encode($c)]);
                }
            });
    }

    public function down(): void
    {
        // Data-only; the extra key is harmless if rolled back.
    }
};
