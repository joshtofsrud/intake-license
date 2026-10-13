<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PRICING-SECTION-RESTORE: the plan-by-plan table is opt-in now, so
 * the home page goes back to three cards. Switch it on for the pricing
 * section(s) on intake.works/pricing only. Leaves any section that already
 * has the setting alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_page_sections') || ! Schema::hasTable('tenant_pages')) {
            return;
        }
        $platformId = DB::table('tenants')->where('is_platform', true)->value('id');
        if (! $platformId) {
            return;
        }
        $pageIds = DB::table('tenant_pages')->where('tenant_id', $platformId)->where('slug', 'pricing')->pluck('id');
        $rows = DB::table('tenant_page_sections')->whereIn('page_id', $pageIds)->where('section_type', 'pricing_table')->get(['id', 'content']);
        foreach ($rows as $r) {
            $c = json_decode((string) $r->content, true);
            if (! is_array($c)) {
                $c = [];
            }
            if (array_key_exists('show_matrix', $c)) {
                continue;
            }
            $c['show_matrix'] = '1';
            DB::table('tenant_page_sections')->where('id', $r->id)->update(['content' => json_encode($c)]);
        }
    }

    public function down(): void
    {
        // data-only; nothing to undo safely
    }
};
