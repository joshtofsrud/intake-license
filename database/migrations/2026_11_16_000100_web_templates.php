<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-WEB-TEMPLATES: site templates move out of the code and into master admin.
 *
 *   web_templates       one row per template: colour roles for a dark and a light version,
 *                       the pieces (menu bar, top of the homepage, cards, fonts, corners,
 *                       buttons, shop photos, footer) and section designs. "draft" holds
 *                       unpublished edits; shops only ever see the published columns.
 *   tenants.site_mode   dark | light (which version of the template the shop shows)
 *   tenants.site_custom the shop's own changes on top of the template:
 *                       {"c": {"dark": {...}, "light": {...}}, "p": {...}, "written": {...}}
 *
 * Additive only. Shops on an old template are moved to Garage dark with their own accent
 * colour and fonts kept as their changes, so nothing they chose is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_templates')) {
            Schema::create('web_templates', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40)->unique();
                $t->string('name', 80);
                $t->text('description')->nullable();
                $t->string('status', 12)->default('draft');   // draft | published
                $t->json('plans')->nullable();
                $t->json('modes')->nullable();
                $t->json('pieces')->nullable();
                $t->json('sections')->nullable();
                $t->json('draft')->nullable();
                $t->unsignedSmallInteger('sort')->default(0);
                $t->timestamp('published_at')->nullable();
                $t->timestamps();
            });
        }

        Schema::table('tenants', function (Blueprint $t) {
            if (! Schema::hasColumn('tenants', 'site_mode'))   $t->string('site_mode', 8)->nullable();
            if (! Schema::hasColumn('tenants', 'site_custom')) $t->json('site_custom')->nullable();
        });

        $now = now();
        foreach (\App\Support\SiteLook::SEED as $i => $row) {
            if (DB::table('web_templates')->where('key', $row['key'])->exists()) continue;
            DB::table('web_templates')->insert([
                'key'          => $row['key'],
                'name'         => $row['name'],
                'description'  => $row['description'],
                'status'       => 'published',
                'plans'        => json_encode(['starter' => true, 'branded' => true, 'scale' => true]),
                'modes'        => json_encode($row['modes']),
                'pieces'       => json_encode($row['pieces']),
                'sections'     => json_encode($row['sections']),
                'sort'         => $i,
                'published_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // Shops on one of the old code templates move to Garage dark. Their accent colour and
        // fonts become their own changes on top of Garage, so their site keeps its identity.
        $keys = DB::table('web_templates')->pluck('key')->all();
        DB::table('tenants')->whereNotNull('site_template')->where('site_template', '!=', 'custom')
            ->whereNotIn('site_template', $keys)->orderBy('id')
            ->each(function ($t) {
                $garage = \App\Support\SiteLook::SEED[0];
                $p = [];
                if ($t->font_heading && $t->font_heading !== $garage['pieces']['fh']) $p['fh'] = $t->font_heading;
                if ($t->font_body && $t->font_body !== $garage['pieces']['fb'])       $p['fb'] = $t->font_body;
                $c = [];
                if (preg_match('/^#[0-9a-fA-F]{6}$/', (string) $t->accent_color)
                    && strtolower($t->accent_color) !== $garage['modes']['dark']['ac']) {
                    $c['dark']['ac'] = strtolower($t->accent_color);
                }
                // booking, the customer portal and email read the five colour/font columns, so they
                // get the new look too, and "written" records it (a later Branding edit then shows
                // up as the shop's own change rather than being ignored)
                $cols = [
                    'accent_color' => $c['dark']['ac'] ?? $garage['modes']['dark']['ac'],
                    'text_color'   => $garage['modes']['dark']['tx'],
                    'bg_color'     => $garage['modes']['dark']['bg'],
                    'font_heading' => $p['fh'] ?? $garage['pieces']['fh'],
                    'font_body'    => $p['fb'] ?? $garage['pieces']['fb'],
                ];
                $custom = ['c' => $c ?: new \stdClass(), 'p' => $p ?: new \stdClass(), 'written' => $cols];
                DB::table('tenants')->where('id', $t->id)->update($cols + [
                    'site_template' => 'garage',
                    'site_mode'     => 'dark',
                    'site_custom'   => json_encode($custom),
                ]);
            });
    }

    public function down(): void
    {
        // additive: nothing is dropped (old code keeps working against the extra columns)
    }
};
