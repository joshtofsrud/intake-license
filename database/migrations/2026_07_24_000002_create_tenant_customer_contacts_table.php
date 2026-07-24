<?php

// MARKER-BIZ-CUSTOMER — the fleet manager who books, the rider who drops off,
// and accounts payable are three different people. One email field on the
// customer cannot hold them.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_customer_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignUuid('customer_id')->constrained('tenant_customers')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('role', 64)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'customer_id']);
            $t->index(['customer_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_customer_contacts');
    }
};
