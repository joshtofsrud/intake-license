#!/usr/bin/env python3
"""
patch-152-b-edits.py — register new routes for store/update/complete/cancel.
The view edits in 152-b are done by full file overwrite in the shell script.

Idempotent.
"""
import argparse
import pathlib
import sys


# Routes added: store, update, complete, cancel.
# Anchor: the existing /deliveries GET route from 152-a.

OLD = r"""            Route::get('/deliveries',                           [TenantControllers\DeliveriesController::class, 'index'])->name('deliveries.index');"""

NEW = r"""            Route::get('/deliveries',                           [TenantControllers\DeliveriesController::class, 'index'])->name('deliveries.index');
            // MARKER-PATCH-152B — create + edit + complete + cancel
            Route::post('/deliveries',                           [TenantControllers\DeliveriesController::class, 'store'])->name('deliveries.store');
            Route::patch('/deliveries/{id}',                     [TenantControllers\DeliveriesController::class, 'update'])->name('deliveries.update');
            Route::patch('/deliveries/{id}/complete',            [TenantControllers\DeliveriesController::class, 'complete'])->name('deliveries.complete');
            Route::patch('/deliveries/{id}/cancel',              [TenantControllers\DeliveriesController::class, 'cancel'])->name('deliveries.cancel');"""

MARKER = 'MARKER-PATCH-152B'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)

    p = root / 'routes' / 'web.php'
    t = p.read_text()
    if MARKER in t:
        print('  already_applied: routes')
        return
    if OLD not in t:
        print('  ERROR: anchor missing for routes', file=sys.stderr); sys.exit(2)
    if t.count(OLD) > 1:
        print('  ERROR: anchor not unique for routes', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
    print(f'  {"applied" if a.apply else "would_apply"}: routes (store/update/complete/cancel)')


if __name__ == '__main__':
    main()
