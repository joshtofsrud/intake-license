#!/usr/bin/env bash
# apply-notify-closure-scope-fix.sh
# MARKER-NOTIFY-CLOSURE-SCOPE — $notify never crossed the closure boundaries.
#
#   createAppointment(array $data, string $tenantId, bool $notify = true)
#     └─ withLock(..., function () use ($tenant, $mode, $data, ...))   L180
#          └─ DB::transaction(function () use ($data, $tenantId, ...)) L205
#               └─ if ($notify) { ... }                               L309
#
# MARKER-NOTIFY-CHOICE added the guard but not the captures, so every
# createAppointment() call fataled with "Undefined variable $notify" the
# moment it reached the confirmation dispatch — after the appointment row
# was already written inside the transaction, so the whole thing rolled
# back and the modal showed a bare "Server error."
#
# Both lists need it: the inner closure can only capture what the outer
# one already holds.
set -e

python3 <<'PY'
import io

p = 'app/Services/BookingService.php'
s = io.open(p, encoding='utf-8').read()

# --- outer: withLock closure
old = """        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $plan,
            $totalCents, $totalDuration, $customerFacingDur, $slotWeight,
            $appointmentTime, $appointmentEndTime, $resourceId
        ) {"""
assert s.count(old) == 1, 'N1 outer closure use-list anchor'
s = s.replace(old, """        return $lock->withLock($lockKey, function () use (
            $tenant, $mode, $data, $tenantId, $plan,
            $totalCents, $totalDuration, $customerFacingDur, $slotWeight,
            $appointmentTime, $appointmentEndTime, $resourceId,
            $notify // MARKER-NOTIFY-CLOSURE-SCOPE — must pass through here
                    // for the nested transaction closure to be able to take it.
        ) {""")

# --- inner: DB::transaction closure
old = """            return DB::transaction(function () use (
                $data, $tenantId, $plan,
                $totalCents, $totalDuration, $slotWeight,
                $appointmentTime, $appointmentEndTime, $resourceId
            ) {"""
assert s.count(old) == 1, 'N2 inner closure use-list anchor'
s = s.replace(old, """            return DB::transaction(function () use (
                $data, $tenantId, $plan,
                $totalCents, $totalDuration, $slotWeight,
                $appointmentTime, $appointmentEndTime, $resourceId,
                $notify // MARKER-NOTIFY-CLOSURE-SCOPE — consumed at the
                        // SendBookingConfirmationJob dispatch below.
            ) {""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- verify \$notify is now captured at both levels ---"
grep -n 'notify' app/Services/BookingService.php

echo
echo "--- balance check ---"
python3 - <<'PY'
import io
p = 'app/Services/BookingService.php'
s = io.open(p, encoding='utf-8').read()
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
echo "apply-notify-closure-scope-fix: OK"
