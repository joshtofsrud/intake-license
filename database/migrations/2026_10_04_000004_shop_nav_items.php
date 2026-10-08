<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * shop menus move to page rows + style/side (columns were
 * added by the intake.works navigation migration). Per shop, so the header
 * looks the same on day one:
 *   - a link whose address is one of the shop's pages becomes a page row;
 *   - the Nav section's own button ("CTA", now removed from the editor)
 *     becomes a right-side button row, if it was showing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('tenants')->where(fn ($w) => $w->where('is_platform', false)->orWhereNull('is_platform'))->pluck('id');
        $now = now();

        foreach ($tenants as $tid) {
            if (DB::table('tenant_nav_items')->where('tenant_id', $tid)->where('side', 'right')->exists()) continue; // done

            $pages = DB::table('tenant_pages')->where('tenant_id', $tid)->get(['id', 'slug', 'is_home'])
                ->mapWithKeys(fn ($p) => [($p->is_home ? '/' : '/' . $p->slug) => $p->id]);

            $rows = DB::table('tenant_nav_items')->where('tenant_id', $tid)->orderBy('sort_order')->get();
            $sort = 0;
            foreach ($rows as $r) {
                $url  = (string) $r->url;
                $path = preg_match('#^https?://#i', $url) ? null : (rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/');
                DB::table('tenant_nav_items')->where('id', $r->id)->update([
                    'page_id' => $path !== null ? ($pages[$path] ?? null) : null,
                    'style' => 'link', 'side' => 'left', 'sort_order' => $sort++,
                ]);
            }

            // The Nav section's button, as the header drew it: shown when show_cta
            // isn't off and a label was set.
            $homeId = DB::table('tenant_pages')->where('tenant_id', $tid)->where('is_home', true)->value('id');
            $nav = DB::table('tenant_page_sections')->where('tenant_id', $tid)->where('section_type', 'nav')
                ->orderByRaw('page_id = ? desc', [$homeId])->first(['content']);
            if (! $nav) continue;
            $c = json_decode((string) $nav->content, true) ?: [];
            $show = ! isset($c['show_cta']) || ! in_array((string) $c['show_cta'], ['0', 'false', ''], true);
            $label = trim((string) ($c['cta_label'] ?? ''));
            if (! $show || $label === '') continue;
            $style = ($c['cta_style'] ?? 'primary') === 'primary' ? 'button' : 'outline';
            $url = trim((string) ($c['cta_url'] ?? '/book')) ?: '/book';
            DB::table('tenant_nav_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tid, 'page_id' => null, 'label' => $label, 'url' => $url,
                'style' => $style, 'side' => 'right', 'is_external' => (bool) preg_match('#^https?://#i', $url),
                'open_in_new_tab' => false, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Additive data conversion; nothing to undo safely.
    }
};
