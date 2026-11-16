<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CAMPAIGN-REPORT: bounces, spam complaints and unsubscribes are
 * stamped on the send row that caused them, so each campaign can report them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_campaign_sends', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_campaign_sends', 'bounced_at'))      $t->timestamp('bounced_at')->nullable();
            if (! Schema::hasColumn('platform_campaign_sends', 'bounce_type'))     $t->string('bounce_type', 64)->nullable();
            if (! Schema::hasColumn('platform_campaign_sends', 'complained_at'))   $t->timestamp('complained_at')->nullable();
            if (! Schema::hasColumn('platform_campaign_sends', 'unsubscribed_at')) $t->timestamp('unsubscribed_at')->nullable();
        });
        try {
            Schema::table('platform_campaign_sends', fn (Blueprint $t) => $t->index(['email', 'sent_at'], 'pcs_email_sent'));
        } catch (\Throwable $e) {
            // index already there
        }
    }

    public function down(): void
    {
        Schema::table('platform_campaign_sends', function (Blueprint $t) {
            foreach (['bounced_at', 'bounce_type', 'complained_at', 'unsubscribed_at'] as $c) {
                if (Schema::hasColumn('platform_campaign_sends', $c)) $t->dropColumn($c);
            }
        });
    }
};
