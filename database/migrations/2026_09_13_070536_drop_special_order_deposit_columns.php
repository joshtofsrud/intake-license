<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SO-DEPOSIT — remove the special order's private money columns.
 *
 * Refuses rather than destroys: if any special order actually has a deposit
 * recorded, the number is real to whoever typed it, and dropping the column
 * would take it away with no trace. In that case the migration stops and
 * names the rows; the code stops writing to them regardless, so nothing gets
 * worse while the shop decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_special_orders', 'deposit_cents')) {
            return;
        }

        $withDeposits = DB::table('tenant_special_orders')
            ->where('deposit_cents', '>', 0)
            ->count();

        if ($withDeposits > 0) {
            throw new RuntimeException(
                "{$withDeposits} special order(s) have a deposit recorded on the row. "
                . 'Dropping these columns would destroy those figures. Move them onto the sale '
                . 'ledger (or note them elsewhere) first, then re-run. Nothing writes to these '
                . 'columns any more, so they are frozen in the meantime.'
            );
        }

        Schema::table('tenant_special_orders', function (Blueprint $table) {
            $table->dropColumn(['deposit_cents', 'deposit_paid_at', 'deposit_payment_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_special_orders', function (Blueprint $table) {
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->timestamp('deposit_paid_at')->nullable();
            $table->string('deposit_payment_ref', 191)->nullable();
        });
    }
};
