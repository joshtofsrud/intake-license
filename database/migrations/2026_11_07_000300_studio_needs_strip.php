<?php

use App\Support\Dashboard\DashboardTemplates;
use App\Support\Dashboard\Layout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-STUDIO-NEEDS: the Needs you strip — a new built-in block, added to
 * the Studio template as a new version with the strip as its own row above
 * the rest. Whatever master admin already changed in Studio is kept, and an
 * unpublished draft is left alone. Skipped if Studio already has the strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        DashboardTemplates::seedBuiltIns();

        $t = DB::table('dashboard_templates')->where('key', 'studio')->first();
        if (! $t || ! $t->live_version_id) {
            return;
        }
        $live = json_decode((string) DB::table('dashboard_template_versions')->where('id', $t->live_version_id)->value('layout'), true) ?: [];
        if (in_array('studio_needs', Layout::blockKeys($live), true)) {
            return;
        }
        array_unshift($live, ['split' => [12], 'cols' => [[['b' => 'studio_needs']]]]);
        $now = now();
        $n = (int) DB::table('dashboard_template_versions')->where('dashboard_template_id', $t->id)->max('version') + 1;
        $vid = DB::table('dashboard_template_versions')->insertGetId([
            'dashboard_template_id' => $t->id, 'version' => $n, 'layout' => json_encode($live),
            'note' => 'Added the Needs you strip above the tiles.',
            'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('dashboard_templates')->where('id', $t->id)->update(['live_version_id' => $vid, 'updated_at' => $now]);
        DashboardTemplates::flush();
    }

    public function down(): void
    {
        $t = DB::table('dashboard_templates')->where('key', 'studio')->first();
        if ($t) {
            $v = DB::table('dashboard_template_versions')->where('dashboard_template_id', $t->id)
                ->where('note', 'Added the Needs you strip above the tiles.')->orderByDesc('version')->first();
            if ($v && (int) $t->live_version_id === (int) $v->id) {
                $prev = DB::table('dashboard_template_versions')->where('dashboard_template_id', $t->id)
                    ->where('version', '<', $v->version)->orderByDesc('version')->value('id');
                DB::table('dashboard_templates')->where('id', $t->id)->update(['live_version_id' => $prev]);
                DB::table('dashboard_template_versions')->where('id', $v->id)->delete();
            }
        }
        DB::table('dashboard_blocks')->where('key', 'studio_needs')->delete();
        DashboardTemplates::flush();
    }
};
