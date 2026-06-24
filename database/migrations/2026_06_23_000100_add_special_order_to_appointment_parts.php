<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line "add to special orders" support on appointment parts.
 *
 *   is_special_order  — the checkbox state. Default TRUE: every inventory part
 *                       on a work order is assumed special-order unless the
 *                       front desk unchecks it. (Ground Control is mobile
 *                       service — most parts get ordered, not pulled from van
 *                       stock.) Existing rows inherit the default = ON.
 *   special_order_id  — link to the 'needed' SO this line spawned, so toggling
 *                       the box off can retract that SO and we never
 *                       double-create one for the same line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_appointment_parts', function (Blueprint $t) {
            $t->boolean('is_special_order')->default(true)->after('is_taxable');
            $t->foreignUuid('special_order_id')
                ->nullable()
                ->after('is_special_order')
                ->constrained('tenant_special_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_appointment_parts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('special_order_id');
            $t->dropColumn('is_special_order');
        });
    }
};
