<?php
// MARKER-PATCH-612 — labor spine. One migration for the whole time-clock +
// scheduling data model so we never re-migrate as stages ship.
//
// TIMEZONE CONTRACT (read before touching this):
//   • Every *_at / instant column stores a UTC instant (Laravel 'datetime' cast).
//   • Every date-boundary (pay period start/end, shift start/end, time-off
//     range) is computed from the TENANT's timezone (tenant()->timezone())
//     at write time, then stored as the resulting UTC instant. Never store a
//     naive wall-clock. Never compute a boundary against server-local time.
//   • Display always via tlocal()/tlocal_date(). Never raw now().
//   • DST: because boundaries are resolved through the tenant zone at write,
//     "9:00 AM" and "midnight on the 1st" stay correct across DST shifts.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Pay periods: the payroll-truth anchor ------------------------
        Schema::create('tenant_pay_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->timestamp('starts_at');            // UTC instant = tenant-local period start
            $table->timestamp('ends_at');              // UTC instant = tenant-local period end
            $table->string('status', 12)->default('open'); // open | locked
            $table->timestamp('locked_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->string('reopen_reason', 500)->nullable(); // set when a locked period is reopened
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
        });

        // ---- Per-person per-period sign-off -------------------------------
        Schema::create('tenant_time_punch_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('pay_period_id')->index();
            $table->uuid('tenant_user_id')->index();   // the person being approved
            $table->uuid('approved_by');               // who signed off
            $table->timestamp('approved_at');
            $table->integer('minutes_at_approval')->default(0); // snapshot of total minutes signed off
            $table->timestamps();

            $table->unique(['pay_period_id', 'tenant_user_id']);
        });

        // ---- Extend punches: breaks, audit, period link -------------------
        Schema::table('tenant_time_punches', function (Blueprint $table) {
            $table->integer('break_minutes')->default(0)->after('clock_out_at');
            $table->uuid('pay_period_id')->nullable()->after('location_id')->index();
            $table->uuid('edited_by')->nullable()->after('created_by');
            $table->string('edit_reason', 500)->nullable()->after('edited_by');
            $table->timestamp('edited_at')->nullable()->after('edit_reason');
        });

        // ---- Shifts: the schedule ----------------------------------------
        Schema::create('tenant_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->timestamp('starts_at');            // UTC instant from tenant-local wall time
            $table->timestamp('ends_at');
            $table->string('label', 80)->nullable();   // "Routes", "Shop", etc.
            $table->string('color', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('published_at')->nullable(); // null = draft, not visible to staff
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);            // week queries
            $table->index(['tenant_id', 'tenant_user_id', 'starts_at'], 'shift_tenant_user_start_idx');
            $table->index(['tenant_id', 'published_at']);
        });

        // ---- Shift templates: "copy last week" / reusable patterns --------
        Schema::create('tenant_shift_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 80);
            // pattern is a JSON array of relative shifts:
            // [{day_of_week:1, start:"09:00", end:"17:00", user_id, label, location_id}, ...]
            // start/end are tenant-local wall-clock strings; resolved to UTC at apply time.
            $table->json('pattern');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        // ---- Time-off requests -------------------------------------------
        Schema::create('tenant_time_off_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id')->index();
            $table->timestamp('starts_at');            // UTC instant (tenant-local day start)
            $table->timestamp('ends_at');              // UTC instant (tenant-local day end)
            $table->boolean('all_day')->default(true);
            $table->string('type', 30)->default('personal'); // vacation | personal | sick | unavailable
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | denied
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'tenant_user_id', 'starts_at'], 'toff_tenant_user_start_idx');
        });

        // ---- Availability: recurring day-of-week bands --------------------
        Schema::create('tenant_availability', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('tenant_user_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sun .. 6=Sat (tenant-local week)
            $table->string('band', 12);                 // morning | afternoon | evening
            $table->string('preference', 12)->default('available'); // available | prefer | unavailable
            $table->timestamps();

            $table->unique(['tenant_id', 'tenant_user_id', 'day_of_week', 'band'], 'tenant_avail_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_availability');
        Schema::dropIfExists('tenant_time_off_requests');
        Schema::dropIfExists('tenant_shift_templates');
        Schema::dropIfExists('tenant_shifts');

        Schema::table('tenant_time_punches', function (Blueprint $table) {
            $table->dropColumn(['break_minutes', 'pay_period_id', 'edited_by', 'edit_reason', 'edited_at']);
        });

        Schema::dropIfExists('tenant_time_punch_approvals');
        Schema::dropIfExists('tenant_pay_periods');
    }
};

