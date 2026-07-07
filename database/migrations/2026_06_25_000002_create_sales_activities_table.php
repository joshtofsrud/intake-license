<?php
// MARKER-SALES-ACTIVITY — Sales channel: per-prospect activity log (the playbook in motion).
// Every note / email / call / demo / stage change is one row. This is what a
// spreadsheet can't do: a timestamped trail per shop that also auto-stamps the
// next follow-up date back onto sales_prospects.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('sales_prospect_id');

            $t->string('type', 24);                  // note | email | call | demo | follow_up | stage_change | system
            $t->string('stage_from', 24)->nullable();
            $t->string('stage_to', 24)->nullable();
            $t->text('body')->nullable();
            $t->timestamp('occurred_at')->useCurrent();

            $t->timestamps();

            $t->index(['sales_prospect_id', 'occurred_at']);
            $t->foreign('sales_prospect_id')->references('id')->on('sales_prospects')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('sales_activities'); }
};
