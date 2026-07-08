<?php
// MARKER-LEDGER-CORE — one row per collected platform invoice for an
// attributed tenant. stripe_invoice_id is UNIQUE: webhook retries and
// replays can never double-accrue. rate + commission are snapshotted at
// accrual time (design principle 13) — changing an agency's rate later
// affects future entries only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_commission_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agency_id');
            $t->uuid('sales_rep_id')->nullable();
            $t->uuid('sales_prospect_id')->nullable();
            $t->uuid('tenant_id');

            $t->string('stripe_invoice_id', 255)->unique();
            $t->unsignedInteger('amount_collected_cents');
            $t->decimal('rate', 5, 4);
            $t->unsignedInteger('commission_cents');
            $t->string('basis', 12);                       // year1 | residual
            $t->timestamp('collected_at');

            $t->string('status', 12)->default('accrued');  // accrued | paid | void
            $t->timestamp('paid_at')->nullable();

            $t->timestamps();

            $t->foreign('agency_id', 'commission_entries_agency_fk')
              ->references('id')->on('sales_agencies')->cascadeOnDelete();
            $t->index(['agency_id', 'status']);
            $t->index('tenant_id');
            $t->index('sales_rep_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_commission_entries');
    }
};
