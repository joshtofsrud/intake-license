<?php

// MARKER-BIZ-CUSTOMER — business customers live on the customer record rather
// than a separate entity: a business still has assets, appointments, sales,
// history and a login, and splitting it would fork every query in the app.
// customer_type defaults to 'individual', so every existing record and every
// existing query behaves exactly as it does today.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $t) {
            $t->string('customer_type', 16)->default('individual')->index();
            $t->string('business_name', 191)->nullable();
            $t->boolean('tax_exempt')->default(false);
            $t->string('tax_exempt_certificate', 64)->nullable();
            // due_now (today's behaviour) | net_15 | net_30 | net_60
            $t->string('payment_terms', 16)->nullable();
            $t->boolean('po_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $t) {
            $t->dropColumn([
                'customer_type', 'business_name', 'tax_exempt',
                'tax_exempt_certificate', 'payment_terms', 'po_required',
            ]);
        });
    }
};
