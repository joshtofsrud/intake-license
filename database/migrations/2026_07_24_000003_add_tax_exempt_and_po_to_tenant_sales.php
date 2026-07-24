<?php

// MARKER-BIZ-CUSTOMER — the certificate is snapshotted onto the sale so a
// later edit to the customer cannot rewrite what was true at the time of
// sale, which is the whole point of an audit trail.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->boolean('tax_exempt_applied')->default(false);
            $t->string('tax_exempt_certificate', 64)->nullable();
            $t->string('po_number', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales', function (Blueprint $t) {
            $t->dropColumn(['tax_exempt_applied', 'tax_exempt_certificate', 'po_number']);
        });
    }
};
