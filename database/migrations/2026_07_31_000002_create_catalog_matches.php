<?php

// MARKER-CATALOG-MATCHES — a LINK between two distributor catalog rows for
// the same physical product. Rows are never merged; matching is reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_matches', function (Blueprint $t) {
            $t->id();

            // Ordered: lower uuid first, so one pair is one row.
            $t->uuid('row_a_id');
            $t->uuid('row_b_id');
            $t->string('code_a', 32);
            $t->string('code_b', 32);

            // auto | held | confirmed | rejected
            // confirmed/rejected are human decisions and are never
            // overwritten by a later run.
            $t->string('status', 12)->default('held');

            // barcode | mpn
            $t->string('matched_on', 12);

            // Which identifier values agreed — so review can see the evidence
            // without recomputing it.
            $t->json('evidence')->nullable();

            // Percentage gap between the two MSRPs, null when either is
            // missing. The pack-size signal.
            $t->unsignedSmallInteger('msrp_spread_pct')->nullable();

            // Why it was held, for the queue's grouping.
            $t->string('hold_reason', 32)->nullable();

            $t->timestamp('decided_at')->nullable();
            $t->timestamps();

            $t->unique(['row_a_id', 'row_b_id'], 'cmatch_pair_unique');
            $t->index(['status', 'hold_reason'], 'cmatch_status_idx');
            $t->index('row_a_id', 'cmatch_a_idx');
            $t->index('row_b_id', 'cmatch_b_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_matches');
    }
};
