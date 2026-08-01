#!/usr/bin/env bash
# apply-pending-booking-status-widen.sh
# MARKER-PENDING-STATUS-WIDEN — the safety net could never write its own status.
#
# tenant_pending_bookings.status shipped as enum('pending','materialized',
# 'expired'). The July 22 hold-release / failed-paid work then wrote two
# values that were never added to it:
#
#   BookingController::releaseHold()      -> 'released'
#   BookingService::recordFailedPaid()    -> 'failed_paid'
#
# config/database.php sets 'strict' => true, so MySQL raised 1265 "Data
# truncated" instead of storing them. recordFailedPaid() flips the status
# BEFORE emitting the staff alert, so the throw meant the alert never fired;
# its own catch(\Throwable) then swallowed the error into a log line. The
# rows stayed 'pending' and BookingsReapHolds deleted them two hours later
# — the exact cleanup that patch exempted failed_paid rows from.
#
# Confirmed live: two paid bookings on 2026-07-30 01:32 UTC logged
# booking.failed_paid_record_error and produced no alert and no record.
#
# Fix is the column, not the logic. string(20) rather than a wider enum:
# this state list has already grown twice, and an enum means a migration
# every time someone adds a state — which is the failure mode above.
#
# Values the code actually writes, verified by sweep:
#   pending · materialized · released · failed_paid
#   ('expired' is in the original enum but nothing writes it.)
#
# Migration only. No logic touched — releaseHold, recordFailedPaid and the
# reaper's whereIn(['pending','released']) branch all start working as
# written the moment the column accepts the values.
set -e

python3 <<'PY'
import os

MIG = 'database/migrations/2026_08_01_000100_widen_pending_booking_status.php'
assert not os.path.exists(MIG), 'migration already exists — patch already applied?'

open(MIG, 'w', encoding='utf-8').write('''<?php

// MARKER-PENDING-STATUS-WIDEN — see apply-pending-booking-status-widen.sh.

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

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
''')
print('created', MIG)
PY

echo
echo "--- braces ---"
python3 - <<'PY'
import io
s = io.open('database/migrations/2026_08_01_000100_widen_pending_booking_status.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('braces', d, 'parens', par)
PY

echo
echo "apply-pending-booking-status-widen: OK"
