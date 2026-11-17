<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SHOP-CAMPAIGN-REPORT: bounces, spam complaints and unsubscribes are
 * stamped on the shop campaign send that caused them, so each campaign's
 * report can count them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_campaign_sends', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_campaign_sends', 'bounced_at'))      $t->timestamp('bounced_at')->nullable();
            if (! Schema::hasColumn('tenant_campaign_sends', 'bounce_type'))     $t->string('bounce_type', 64)->nullable();
            if (! Schema::hasColumn('tenant_campaign_sends', 'complained_at'))   $t->timestamp('complained_at')->nullable();
            if (! Schema::hasColumn('tenant_campaign_sends', 'unsubscribed_at')) $t->timestamp('unsubscribed_at')->nullable();
        });
        try {
            Schema::table('tenant_campaign_sends', fn (Blueprint $t) => $t->index(['customer_id', 'sent_at'], 'tcs_customer_sent'));
        } catch (\Throwable $e) {
            // index already there
        }
    }

    public function down(): void
    {
        Schema::table('tenant_campaign_sends', function (Blueprint $t) {
            foreach (['bounced_at', 'bounce_type', 'complained_at', 'unsubscribed_at'] as $c) {
                if (Schema::hasColumn('tenant_campaign_sends', $c)) $t->dropColumn($c);
            }
        });
    }
};
