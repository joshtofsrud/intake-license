#!/usr/bin/env bash
# apply-reminder-confirmation-gate.sh
# MARKER-REMINDER-CONFIRMATION-GATE — don't remind a customer about an
# appointment they were never told about.
#
# The problem: a staff-created "work up" — a quote to give someone an idea,
# which may never become a real appointment — sits in `pending` and the
# hourly reminder cron happily texts the customer about it the day before.
# Nobody pressed anything; it just went out.
#
# Why the gate is NOT the appointment status: BookingService creates EVERY
# appointment as 'pending', online customer bookings included. Gating on
# 'confirmed' would have silenced reminders for every booking a customer
# makes themselves. Those must keep working.
#
# The gate is instead "was a booking confirmation ever attempted for this
# appointment", which is exactly the distinction:
#
#   work-up          -> createAppointment(..., notify: false)
#                       -> job never dispatched -> zero log rows -> silent
#   online booking   -> job dispatched -> rows exist -> reminded
#   staff pressed
#   Send confirmation-> job dispatched -> rows exist -> reminded
#
# Status of those rows is deliberately ignored. SendBookingConfirmationJob
# writes a row for every channel on every outcome — sent, failed, AND
# skipped — so presence alone means "this appointment was meant to reach
# the customer". Per Josh: a failed confirmation should still get its
# reminder.
#
# Controller-only change to a console command: optimize:clear + fpm cycle.
set -e

python3 <<'PY'
import io

p = 'app/Console/Commands/SendAppointmentReminders.php'
s = io.open(p, encoding='utf-8').read()

old = """            $rows = TenantAppointment::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNull('reminded_at')"""
assert s.count(old) == 1, 'R1 reminder query anchor'
s = s.replace(old, """            $rows = TenantAppointment::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNull('reminded_at')
                // MARKER-REMINDER-CONFIRMATION-GATE — only appointments the
                // customer was actually told about. Presence of a row is the
                // whole test: status is ignored on purpose, because a failed
                // confirmation should still get its reminder, and because
                // the job logs 'skipped' rows too. A work-up dispatches no
                // job at all, so it has no rows and stays silent.
                // Uses the (tenant_id, related_type, related_id) index.
                ->whereExists(function ($q) use ($tenant) {
                    $q->selectRaw('1')
                      ->from('tenant_notification_log as ncf')
                      ->whereColumn('ncf.related_id', 'tenant_appointments.id')
                      ->where('ncf.tenant_id', $tenant->id)
                      ->where('ncf.related_type', 'appointment')
                      ->where('ncf.event_type', 'booking_confirmation');
                })""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- the gate is in place ---"
grep -n "MARKER-REMINDER-CONFIRMATION-GATE\|whereExists\|booking_confirmation" app/Console/Commands/SendAppointmentReminders.php

echo
echo "--- braces / parens ---"
python3 - <<'PY'
import io
s = io.open('app/Console/Commands/SendAppointmentReminders.php', encoding='utf-8').read()
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
echo "apply-reminder-confirmation-gate: OK"
