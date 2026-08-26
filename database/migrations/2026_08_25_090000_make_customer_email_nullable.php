<?php
// MARKER-CUSTOMER-EMAIL-NULLABLE — a walk-in has no email. The unique index
// on (tenant_id, email) is unaffected: MySQL allows repeated NULLs under a
// unique index, so email-less customers coexist and real emails stay unique.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversible: by the time this runs there may be
        // customers with a null email, and NOT NULL would fail or force us to
        // invent addresses for real people. Leaving the column nullable is
        // harmless; tightening it is a data decision, not a schema rollback.
    }
};
