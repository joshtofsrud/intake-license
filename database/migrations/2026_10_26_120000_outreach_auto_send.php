<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-OUTREACH-AUTO: Email outreach's free-email filter and auto-send.
 *
 *   outreach_free_domains  the domains that count as free email (JSON list);
 *                          null = the built-in list in OutreachQueue
 *   outreach_auto          auto-send's settings and where it's up to (JSON)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'outreach_free_domains')) $t->json('outreach_free_domains')->nullable();
            if (! Schema::hasColumn('platform_settings', 'outreach_auto')) $t->json('outreach_auto')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $t) {
            foreach (['outreach_free_domains', 'outreach_auto'] as $c) {
                if (Schema::hasColumn('platform_settings', $c)) $t->dropColumn($c);
            }
        });
    }
};
