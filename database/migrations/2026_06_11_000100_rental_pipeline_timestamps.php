<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-234 — pipeline timestamps. checked_out_at and cancelled_at
 * give the booking-detail stepper real times for every stage. Nullable on
 * purpose: rentals that moved before this patch simply show no time.
 * "Overdue" remains derived (status=out AND due_at < now) — never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $t) {
            $t->timestamp('checked_out_at')->nullable()->after('returned_at');
            $t->timestamp('cancelled_at')->nullable()->after('checked_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $t) {
            $t->dropColumn(['checked_out_at', 'cancelled_at']);
        });
    }
};
