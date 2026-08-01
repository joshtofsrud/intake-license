<?php

// MARKER-PENDING-STATUS-WIDEN — see apply-pending-booking-status-widen.sh.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel 11 changes columns natively; no doctrine/dbal needed.
        // The (tenant_id, status) index rides along fine.
        Schema::table('tenant_pending_bookings', function (Blueprint $t) {
            $t->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Rows holding a value outside the original enum would truncate on
        // the way back down, so park them somewhere the old enum allows
        // first. 'expired' is the closest terminal state the old shape had.
        DB::table('tenant_pending_bookings')
            ->whereNotIn('status', ['pending', 'materialized', 'expired'])
            ->update(['status' => 'expired']);

        Schema::table('tenant_pending_bookings', function (Blueprint $t) {
            $t->enum('status', ['pending', 'materialized', 'expired'])
              ->default('pending')->change();
        });
    }
};
