<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SPLASH-2 — a splash is attached to the page it appears BEFORE.
 *
 * Columns live on tenant_pages rather than in a join table because a visited
 * page has at most one splash: the pairing is a property of that page, and
 * a table would only add a join for a strict one-to-one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->uuid('splash_page_id')->nullable()->after('is_splash');
            $table->string('splash_mode', 12)->default('overlay')->after('splash_page_id');
            $table->string('splash_style', 12)->default('full')->after('splash_mode');
            $table->string('splash_frequency', 12)->default('session')->after('splash_style');
            $table->date('splash_starts_at')->nullable()->after('splash_frequency');
            $table->date('splash_ends_at')->nullable()->after('splash_starts_at');
            $table->index(['tenant_id', 'splash_page_id']);
        });

        // Carry v1 across: the flagged page becomes the homepage's pairing,
        // with the tenant's stored settings. Anyone who configured a splash
        // yesterday keeps exactly what they configured.
        $tenants = DB::table('tenant_pages')
            ->where('is_splash', true)
            ->select('tenant_id', 'id')
            ->get();

        foreach ($tenants as $row) {
            $settings = DB::table('tenants')->where('id', $row->tenant_id)->value('settings');
            $s = is_string($settings) ? (json_decode($settings, true) ?: []) : (array) $settings;

            DB::table('tenant_pages')
                ->where('tenant_id', $row->tenant_id)
                ->where('is_home', true)
                ->update([
                    'splash_page_id'   => $row->id,
                    'splash_mode'      => in_array(($s['splash_mode'] ?? 'overlay'), ['overlay', 'page'], true) ? $s['splash_mode'] : 'overlay',
                    'splash_style'     => in_array(($s['splash_style'] ?? 'full'), ['full', 'sheet'], true) ? $s['splash_style'] : 'full',
                    'splash_frequency' => in_array((string) ($s['splash_frequency'] ?? 'session'), ['session', '7', '30', 'always'], true) ? (string) $s['splash_frequency'] : 'session',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenant_pages', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'splash_page_id']);
            $table->dropColumn([
                'splash_page_id', 'splash_mode', 'splash_style',
                'splash_frequency', 'splash_starts_at', 'splash_ends_at',
            ]);
        });
    }
};
