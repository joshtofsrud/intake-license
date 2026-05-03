<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_sale_counters', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Tenant scope
            $table->foreignUuid('tenant_id')->constrained('tenants')->onDelete('cascade');
            // cascade: deleting a tenant wipes their counters (they're worthless without the tenant)

            // The day this counter tracks
            $table->date('counter_date');

            // Last sequence number used for this tenant+date
            $table->unsignedInteger('last_seq')->default(0);

            $table->timestamps();

            // Lock target — must be unique per tenant per day
            // SaleService does SELECT ... FOR UPDATE on this row inside a transaction
            $table->unique(['tenant_id', 'counter_date'], 'tsc_tenant_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_sale_counters');
    }
};
