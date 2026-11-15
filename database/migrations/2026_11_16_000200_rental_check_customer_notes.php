<?php
// MARKER-PORTAL-RENTALS: a condition-check note is staff-only unless staff tick
// "Customer note", which shows it on the customer's rental page in their portal.
// Additive only; existing notes stay hidden.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rental_condition_checks', function (Blueprint $t) {
            if (! Schema::hasColumn('tenant_rental_condition_checks', 'notes_customer_visible')) {
                $t->boolean('notes_customer_visible')->default(false)->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rental_condition_checks', function (Blueprint $t) {
            if (Schema::hasColumn('tenant_rental_condition_checks', 'notes_customer_visible')) {
                $t->dropColumn('notes_customer_visible');
            }
        });
    }
};
