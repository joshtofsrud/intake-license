<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-OUTREACH-SCANNERS: each visit through a prospect's email link says
 * whether a person made it, and why we think so. Email security filters
 * (Microsoft, Proofpoint, Google...) open every link in a hidden browser
 * seconds after delivery; on Oct 22-28, 2026 about 245 of 253 "visits" were
 * those. See App\Services\Sales\OutreachVisitJudge.
 *
 * Stores no IP address: only the name of the network a visit came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_prospect_visits')) return;

        Schema::table('sales_prospect_visits', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospect_visits', 'sent_at'))         $t->timestamp('sent_at')->nullable()->index(); // the email this visit followed
            if (! Schema::hasColumn('sales_prospect_visits', 'secs_after_send')) $t->integer('secs_after_send')->nullable();
            if (! Schema::hasColumn('sales_prospect_visits', 'network'))         $t->string('network', 24)->nullable();    // microsoft|google|proofpoint|...|home|unknown
            if (! Schema::hasColumn('sales_prospect_visits', 'interacted_at'))   $t->timestamp('interacted_at')->nullable(); // moved, scrolled, tapped or typed
            if (! Schema::hasColumn('sales_prospect_visits', 'verdict'))         $t->string('verdict', 10)->default('unsure')->index();  // person|scanner|unsure
            if (! Schema::hasColumn('sales_prospect_visits', 'verdict_reason'))  $t->string('verdict_reason', 160)->nullable();
        });

        // judge what's already there: timing is all the old rows have
        try {
            \App\Services\Sales\OutreachVisitJudge::backfill();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::table('sales_prospect_visits', function (Blueprint $t) {
            foreach (['sent_at', 'secs_after_send', 'network', 'interacted_at', 'verdict', 'verdict_reason'] as $c) {
                if (Schema::hasColumn('sales_prospect_visits', $c)) $t->dropColumn($c);
            }
        });
    }
};
