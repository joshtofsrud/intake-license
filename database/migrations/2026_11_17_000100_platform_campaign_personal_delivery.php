<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PERSONAL-DELIVERY: a campaign can go out one email at a time, each
 * sent by a click as a personal email on the transactional stream, and cold
 * outreach gets its own stream setting so it stops sharing a reputation with
 * Intake's other campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_campaigns', 'delivery')) $t->string('delivery', 16)->nullable(); // bulk | personal | null = automatic
        });
        Schema::table('platform_campaign_sends', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_campaign_sends', 'channel'))    $t->string('channel', 16)->nullable();    // bulk | personal
            if (! Schema::hasColumn('platform_campaign_sends', 'send_count')) $t->unsignedSmallInteger('send_count')->default(0);
        });
        Schema::table('platform_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_settings', 'platform_outreach_stream')) $t->string('platform_outreach_stream', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_campaigns', fn (Blueprint $t) => $t->dropColumn('delivery'));
        Schema::table('platform_campaign_sends', fn (Blueprint $t) => $t->dropColumn(['channel', 'send_count']));
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('platform_outreach_stream'));
    }
};
