<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add PIN columns to tenant_users.
 *
 * Layer 2 of the auth refactor. Each staff member sets their own 4-digit
 * PIN on first sign-in (see auth-refactor-spec-v2.md §4.2). The columns
 * are nullable so existing rows get the correct "PIN not set yet" state.
 *
 * - pin_hash:          bcrypt of the 4 digits. Nullable until set.
 * - pin_set_at:        when the staff member set their current PIN.
 * - pin_failed_count:  rolling failure counter for lockout (resets on
 *                      successful entry).
 * - pin_locked_until:  if non-null, PIN entry is rejected until this
 *                      timestamp passes. Cooldown ladder lives in code,
 *                      not in the schema.
 * - pin_last_used_at:  most recent successful PIN entry. Used by idle
 *                      lock checks and admin diagnostics.
 *
 * These columns are tier-gated at the application layer (Starter never
 * uses them). The schema is unconditional — the cost of carrying five
 * NULL columns per row is negligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            // bcrypt hashes are 60 chars; use char(60) for exact fit.
            $table->char('pin_hash', 60)->nullable()->after('password');
            $table->timestamp('pin_set_at')->nullable()->after('pin_hash');
            $table->smallInteger('pin_failed_count')->unsigned()->default(0)->after('pin_set_at');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_count');
            $table->timestamp('pin_last_used_at')->nullable()->after('pin_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn([
                'pin_hash',
                'pin_set_at',
                'pin_failed_count',
                'pin_locked_until',
                'pin_last_used_at',
            ]);
        });
    }
};
