#!/usr/bin/env python3
"""
Patch 134 — fix every schema mismatch in OperationalHealthWidget.

Three errors:
  - stripe_webhook_events.created_at → received_at
  - debug_logs.action → event (column never existed; real name is 'event')
  - debug_logs.action → event (same fix in the mail-sent tile)

Idempotent.
"""
import argparse, pathlib, sys

EDITS = [
    (
        "->where('created_at', '<', now()->subMinutes(5))",
        "->where('received_at', '<', now()->subMinutes(5))  // MARKER-PATCH-134",
        'stripe webhook column',
    ),
    (
        "->where('action', 'auth.login_failed')",
        "->where('event', 'auth.login_failed')  // MARKER-PATCH-134",
        'failed logins column',
    ),
    (
        "->where('action', 'mail.sent')",
        "->where('event', 'mail.sent')  // MARKER-PATCH-134",
        'mail sent column',
    ),
]

def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    p = pathlib.Path(a.root) / 'app' / 'Filament' / 'Widgets' / 'OperationalHealthWidget.php'
    t = p.read_text()
    for old, new, label in EDITS:
        if new in t and old not in t:
            print('already_applied: ' + label); continue
        if old not in t:
            print('ERROR: anchor missing for ' + label, file=sys.stderr); sys.exit(2)
        t = t.replace(old, new, 1)
        print('applied: ' + label)
    if a.apply:
        p.write_text(t)
    else:
        print('(dry-run — not written)')

if __name__ == '__main__': main()
