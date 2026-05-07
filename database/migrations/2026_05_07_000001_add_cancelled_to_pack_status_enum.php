<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'cancelled' to tenant_customer_packs.status enum.
 *
 * The original enum was ['active', 'exhausted', 'expired'] — modeling natural
 * lifecycle states. The admin grant/revoke flow needed a way to manually
 * cancel a pack (e.g. comp gone wrong, refund), so we add 'cancelled' as
 * a distinct terminal state that records human action vs. pack ageing out.
 *
 * Memberships already had 'cancelled' from day one. This is the symmetric fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: ALTER COLUMN with a new enum list keeps existing data.
        // Down migration would risk losing rows, so we leave it one-way.
        DB::statement("ALTER TABLE tenant_customer_packs MODIFY status ENUM('active','exhausted','expired','cancelled') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // Intentionally a no-op. Reverting would error on any rows that have
        // landed on 'cancelled', and there's no clean fallback state for them.
    }
};
