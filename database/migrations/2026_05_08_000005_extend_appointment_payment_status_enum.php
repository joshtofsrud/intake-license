<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend tenant_appointments.payment_status enum to support the new
 * balance-aware lifecycle.
 *
 *   pending_balance — appointment hit Completed with balance > 0;
 *                     a draft register sale is awaiting payment.
 *   overage         — prepayments exceed final total; tenant owes the
 *                     customer money back.
 *
 * MySQL/MariaDB-specific MODIFY COLUMN. Both supported.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE tenant_appointments
            MODIFY COLUMN payment_status ENUM(
                'unpaid',
                'partial',
                'pending_balance',
                'paid',
                'refunded',
                'overage'
            ) NOT NULL DEFAULT 'unpaid'
        ");
    }

    public function down(): void
    {
        // Will fail if any rows have new values. By design — caller must
        // remap before rolling back.
        DB::statement("
            ALTER TABLE tenant_appointments
            MODIFY COLUMN payment_status ENUM(
                'unpaid',
                'partial',
                'paid',
                'refunded'
            ) NOT NULL DEFAULT 'unpaid'
        ");
    }
};
