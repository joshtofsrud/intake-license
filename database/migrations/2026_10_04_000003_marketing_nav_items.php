<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-MKT-NAV — menu rows can point at a page and carry a style and side.
 * Additive columns (shop sites ignore them). For intake.works the existing
 * rows are converted so the header looks the same on day one:
 *   - a row whose address matches a marketing page becomes a page row;
 *   - with no rows at all, the five links the header used to fall back to
 *     are written as rows;
 *   - "Sign in" and "Start free trial", hard-coded until now, become two
 *     ordinary rows on the right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_nav_items', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_nav_items', 'page_id')) $t->uuid('page_id')->nullable()->after('tenant_id');
            if (! Schema::hasColumn('tenant_nav_items', 'style'))   $t->string('style', 12)->default('link')->after('url');
            if (! Schema::hasColumn('tenant_nav_items', 'side'))    $t->string('side', 8)->default('left')->after('style');
        });

        $platform = DB::table('tenants')->where('is_platform', true)->value('id');
        if (! $platform) return;
        if (DB::table('tenant_nav_items')->where('tenant_id', $platform)->where('side', 'right')->exists()) return; // already converted

        $pages = DB::table('tenant_pages')->where('tenant_id', $platform)
            ->get(['id', 'slug', 'is_home'])
            ->mapWithKeys(fn ($p) => [($p->is_home ? '/' : '/' . $p->slug) => $p->id]);

        $rows = DB::table('tenant_nav_items')->where('tenant_id', $platform)->orderBy('sort_order')->get();
        $now  = now();
        $sort = 0;

        if ($rows->isEmpty()) {
            foreach ([['Features', '/features'], ['Pricing', '/pricing'], ['Roadmap', '/roadmap'], ['Changelog', '/changelog'], ['Docs', '/docs']] as [$label, $url]) {
                DB::table('tenant_nav_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $platform, 'page_id' => $pages[$url] ?? null, 'label' => $label, 'url' => $url,
                    'style' => 'link', 'side' => 'left', 'is_external' => false, 'open_in_new_tab' => false,
                    'sort_order' => $sort++, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        } else {
            foreach ($rows as $r) {
                $path = rtrim((string) parse_url((string) $r->url, PHP_URL_PATH), '/') ?: '/';
                $host = parse_url((string) $r->url, PHP_URL_HOST);
                $pageId = (! $host || str_ends_with($host, (string) config('intake.domain', 'intake.works'))) ? ($pages[$path] ?? null) : null;
                DB::table('tenant_nav_items')->where('id', $r->id)->update([
                    'page_id' => $pageId, 'style' => 'link', 'side' => 'left', 'sort_order' => $sort++,
                ]);
            }
        }

        // Sign-in and sign-up live on the app host, as they always have.
        $app = 'https://app.' . config('intake.domain', 'intake.works');
        foreach ([['Sign in', $app . '/login', 'link'], ['Start free trial', $app . '/signup', 'button']] as [$label, $url, $style]) {
            DB::table('tenant_nav_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $platform, 'page_id' => null, 'label' => $label, 'url' => $url,
                'style' => $style, 'side' => 'right', 'is_external' => false, 'open_in_new_tab' => false,
                'sort_order' => $sort++, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Additive: columns stay (expand/contract). Nothing to undo safely.
    }
};
