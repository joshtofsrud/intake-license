<?php
// MARKER-BILLING-DISCOUNTS / MARKER-BILLING-DISCOUNTS-RENAME
//
// NOT `tenant_discounts` — that name belongs to the customer discount-codes
// feature and has since Aug 26. This is the billing arrangement between Intake
// and a shop, which is a different thing entirely.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            $table->string('reason', 160);
            $table->string('scope', 16)->default('both');
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_discounts');
    }
};
