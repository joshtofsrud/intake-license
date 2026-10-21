<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One active booking per person per class, and one active membership per
 * customer, enforced without putting `status` in the unique key.
 *
 * The old keys were unique on (session, customer, status) and
 * (tenant, customer, status). That also made "cancelled" unique, so the
 * second cancellation of the same class by the same customer, or a second
 * cancelled membership, failed with a duplicate-key 500.
 *
 * active_slot holds the customer id while the row is active and is NULL
 * otherwise; MySQL allows any number of NULLs in a unique index. The models
 * keep it in step on every save. Old code running briefly against this
 * schema (migrate runs before the release swap) leaves it NULL, which only
 * means no constraint for those rows; the backfill below covers existing ones.
 *
 * Re-runnable: each step checks whether it already happened.
 *
 * MARKER-CLASSES-P0
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- registrations ------------------------------------------------
        if (! Schema::hasColumn('tenant_class_registrations', 'active_slot')) {
            Schema::table('tenant_class_registrations', function (Blueprint $t) {
                $t->char('active_slot', 36)->nullable()->after('customer_id');
            });
        }
        DB::statement("UPDATE tenant_class_registrations
            SET active_slot = CASE WHEN status IN ('registered','waitlisted','checked_in') THEN customer_id ELSE NULL END");

        $dupes = DB::select("SELECT class_session_id, active_slot, COUNT(*) n
            FROM tenant_class_registrations WHERE active_slot IS NOT NULL
            GROUP BY class_session_id, active_slot HAVING n > 1");
        if ($dupes) {
            throw new RuntimeException('Classes migration stopped: a customer has more than one active booking on the same class. '
                . 'Resolve these first (session : customer): '
                . collect($dupes)->map(fn ($d) => $d->class_session_id . ' : ' . $d->active_slot)->implode(', '));
        }

        if (! $this->hasIndex('tenant_class_registrations', 'tcr_one_active_booking')) {
            Schema::table('tenant_class_registrations', function (Blueprint $t) {
                $t->unique(['class_session_id', 'active_slot'], 'tcr_one_active_booking');
            });
        }
        if ($this->hasIndex('tenant_class_registrations', 'one_active_registration')) {
            Schema::table('tenant_class_registrations', function (Blueprint $t) {
                $t->dropUnique('one_active_registration');
            });
        }

        // ---- memberships ----------------------------------------------------
        if (! Schema::hasColumn('tenant_customer_memberships', 'active_slot')) {
            Schema::table('tenant_customer_memberships', function (Blueprint $t) {
                $t->char('active_slot', 36)->nullable()->after('customer_id');
            });
        }
        DB::statement("UPDATE tenant_customer_memberships
            SET active_slot = CASE WHEN status = 'active' AND deleted_at IS NULL THEN customer_id ELSE NULL END");

        $dupes = DB::select("SELECT tenant_id, active_slot, COUNT(*) n
            FROM tenant_customer_memberships WHERE active_slot IS NOT NULL
            GROUP BY tenant_id, active_slot HAVING n > 1");
        if ($dupes) {
            throw new RuntimeException('Classes migration stopped: a customer has more than one active membership. '
                . 'Resolve these first (tenant : customer): '
                . collect($dupes)->map(fn ($d) => $d->tenant_id . ' : ' . $d->active_slot)->implode(', '));
        }

        if (! $this->hasIndex('tenant_customer_memberships', 'tcm_one_active_membership')) {
            Schema::table('tenant_customer_memberships', function (Blueprint $t) {
                $t->unique(['tenant_id', 'active_slot'], 'tcm_one_active_membership');
            });
        }
        if ($this->hasIndex('tenant_customer_memberships', 'one_active_membership')) {
            Schema::table('tenant_customer_memberships', function (Blueprint $t) {
                $t->dropUnique('one_active_membership');
            });
        }
    }

    public function down(): void
    {
        // Intentionally not restoring the status-based keys: they are the bug.
        if ($this->hasIndex('tenant_class_registrations', 'tcr_one_active_booking')) {
            Schema::table('tenant_class_registrations', fn (Blueprint $t) => $t->dropUnique('tcr_one_active_booking'));
        }
        if ($this->hasIndex('tenant_customer_memberships', 'tcm_one_active_membership')) {
            Schema::table('tenant_customer_memberships', fn (Blueprint $t) => $t->dropUnique('tcm_one_active_membership'));
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return (bool) DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$name]);
    }
};

