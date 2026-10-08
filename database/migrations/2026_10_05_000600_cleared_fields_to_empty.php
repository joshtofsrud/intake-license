<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * fields cleared in the page builder were stored as
 * null (the framework turns empty form fields into null), and a null reads as
 * "never set", so sections showed their sample text again ("Your headline
 * here"). Stored nulls in section content become empty text, so what you
 * cleared stays cleared. Top-level values only; lists are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_page_sections')->select('id', 'content')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $r) {
                $c = json_decode((string) $r->content, true);
                if (! is_array($c)) continue;
                $changed = false;
                foreach ($c as $k => $v) {
                    if ($v === null) { $c[$k] = ''; $changed = true; }
                }
                if ($changed) DB::table('tenant_page_sections')->where('id', $r->id)->update(['content' => json_encode($c)]);
            }
        });
    }

    public function down(): void {}
};
