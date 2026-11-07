<?php

use App\Support\Dashboard\DashboardTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-DASH-STUDIO: the Studio dashboard — three new built-in blocks and a
 * fourth template, live with a beta badge and not the default. Nobody's
 * dashboard changes until they pick it. Uses the same seeding as the other
 * built-ins, which only adds what's missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DashboardTemplates::seedBuiltIns();
    }

    public function down(): void
    {
        $id = DB::table('dashboard_templates')->where('key', 'studio')->value('id');
        if ($id) {
            DB::table('dashboard_templates')->where('id', $id)->update(['live_version_id' => null]);
            DB::table('dashboard_template_versions')->where('dashboard_template_id', $id)->delete();
            DB::table('dashboard_templates')->where('id', $id)->delete();
        }
        DB::table('dashboard_blocks')->whereIn('key', ['studio_tiles', 'studio_rail', 'studio_queue'])->delete();
    }
};
