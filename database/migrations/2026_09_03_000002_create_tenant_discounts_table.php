<?php
// MARKER-BILLING-DISCOUNTS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Written for the shop: this appears on their statement.
            $table->string('reason', 160);

            // platform | addons | both. Usage is deliberately absent — see the
            // patch header; waiving usage is a written-off charge, not a rule.
            $table->string('scope', 16)->default('both');

            // Exactly one of these is set. Percent covers "free year" and
            // "40% off"; amount covers "$50 off a month" arrangements.
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedInteger('amount_cents')->nullable();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();   // null = no end

            $table->string('created_by', 191)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_discounts');
    }
};
