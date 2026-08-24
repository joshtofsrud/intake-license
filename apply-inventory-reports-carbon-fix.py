#!/usr/bin/env python3
"""Fix the reports page 500: wrong Carbon class in the typehints.

I typehinted Illuminate\\Support\\Carbon, but Tenant::localToday() returns
\\Carbon\\Carbon. Illuminate's Carbon EXTENDS Carbon\\Carbon rather than the
other way round, so a base Carbon instance does not satisfy the narrower
hint and every call fataled with a TypeError.

Fix: hint \\Carbon\\Carbon, which accepts both — the tenant helpers return
base Carbon, and an Illuminate\\Support\\Carbon would still pass because it
is a subclass.

Lesson for future patches: I verified localToday() EXISTS but not what it
RETURNS. A typehint is a contract with the caller; checking the signature
is part of checking the method.
Run from repo root: python3 apply-inventory-reports-carbon-fix.py
"""
import sys

SVC = 'app/Services/Tenant/InventoryReportService.php'

s = open(SVC).read()

if 'MARKER-INV-REPORTS-CARBON' in s:
    print("SKIP (already applied)"); sys.exit(0)

if 'use Illuminate\\Support\\Carbon;' not in s:
    print("FAIL: expected import not found"); sys.exit(1)

# Base Carbon accepts both classes; Illuminate's subclass does not.
s = s.replace(
    'use Illuminate\\Support\\Carbon;',
    "// MARKER-INV-REPORTS-CARBON — Tenant::localToday() returns \\Carbon\\Carbon.\n"
    "// Illuminate\\Support\\Carbon EXTENDS it, so hinting the Illuminate class\n"
    "// rejects a plain Carbon and every call fataled. The base class accepts both.\n"
    'use Carbon\\Carbon;',
    1
)

open(SVC, 'w').write(s)
print("OK: import switched to Carbon\\Carbon")

# Confirm the IMPORT is gone — matching the bare string also hits the
# explanatory comment above, which is not a code reference.
s = open(SVC).read()
if 'use Illuminate\\Support\\Carbon;' in s:
    print("FAIL: the Illuminate\\Support\\Carbon import survived"); sys.exit(1)

print("Done. No migration needed. optimize:clear after deploy.")
