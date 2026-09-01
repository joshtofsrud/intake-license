<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MARKER-CUST-ADMIN — an erased customer keeps its id so sales and bookings
// still resolve, but is hidden everywhere a person would be listed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_customers', 'erased_at')) {
                $table->timestamp('erased_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_customers', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
    }
};
