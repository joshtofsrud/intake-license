<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-PATCH-204 — customer-facing invoice fields on the work order.
 *
 * invoice_note  : shop-authored note that prints ON the invoice. Distinct from
 *                 staff_notes (internal) and the customer's own note.
 * invoice_terms : 'due_now' | 'on_completion'. ('paid' is NOT stored here —
 *                 it derives from payment_status so the two can't drift.)
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tenant_appointments')) return;

        Schema::table('tenant_appointments', function (Blueprint $t) {
            if (!Schema::hasColumn('tenant_appointments', 'invoice_note')) {
                $t->text('invoice_note')->nullable()->after('staff_notes');
            }
            if (!Schema::hasColumn('tenant_appointments', 'invoice_terms')) {
                $t->string('invoice_terms', 24)->nullable()->after('invoice_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointments', function (Blueprint $t) {
            foreach (['invoice_note', 'invoice_terms'] as $c) {
                if (Schema::hasColumn('tenant_appointments', $c)) $t->dropColumn($c);
            }
        });
    }
};
