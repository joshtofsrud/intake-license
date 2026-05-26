#!/usr/bin/env python3
"""
patch-152-a-edits.py — small Python helper for the parts of 152-a that
cannot be done with simple file writes: the route registration and
injecting the schedule-tabs component into the two capacity-mode views.

All anchors are RAW strings so dollar-signs don't get interpreted.
str.replace is used (no regex), so no backref issues either.

Idempotent.
"""
import argparse
import pathlib
import sys


# ============================================================
# Route addition
# ============================================================

OLD_ROUTES = r"""            Route::get('/calendar',             [TenantControllers\CalendarController::class, 'index'])->name('calendar.index');"""

NEW_ROUTES = r"""            Route::get('/calendar',             [TenantControllers\CalendarController::class, 'index'])->name('calendar.index');

            // MARKER-PATCH-152A — Deliveries (internal pickup/dropoff schedule)
            Route::get('/deliveries',                           [TenantControllers\DeliveriesController::class, 'index'])->name('deliveries.index');
            Route::get('/deliveries/resources',                 [TenantControllers\DeliveryResourcesController::class, 'index'])->name('deliveries.resources.index');
            Route::post('/deliveries/resources',                [TenantControllers\DeliveryResourcesController::class, 'store'])->name('deliveries.resources.store');
            Route::patch('/deliveries/resources/{id}',          [TenantControllers\DeliveryResourcesController::class, 'update'])->name('deliveries.resources.update');
            Route::delete('/deliveries/resources/{id}',         [TenantControllers\DeliveryResourcesController::class, 'destroy'])->name('deliveries.resources.destroy');"""


# ============================================================
# Inject schedule-tabs into capacity-mode dropoff views
# ============================================================
# Anchor on the closing tags after the Week link in each view.
# Raw strings so $date isn't interpreted by Python.

OLD_DROPOFF_DAY = r"""      <a href="?view=day&date={{ $date->copy()->addDay()->format('Y-m-d') }}" class="cal-date-btn" title="Next day">›</a>
    </div>
  </div>
</div>"""

NEW_DROPOFF_DAY = r"""      <a href="?view=day&date={{ $date->copy()->addDay()->format('Y-m-d') }}" class="cal-date-btn" title="Next day">›</a>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-152A — capacity-mode was missing the schedule sub-toggle --}}
<x-tenant.schedule-tabs active="calendar" />"""


OLD_DROPOFF_WEEK = r"""      <a href="?view=week&date={{ $weekStart->copy()->addWeek()->format('Y-m-d') }}" class="cal-date-btn" title="Next week">›</a>
    </div>
  </div>
</div>"""

NEW_DROPOFF_WEEK = r"""      <a href="?view=week&date={{ $weekStart->copy()->addWeek()->format('Y-m-d') }}" class="cal-date-btn" title="Next week">›</a>
    </div>
  </div>
</div>

{{-- MARKER-PATCH-152A — capacity-mode was missing the schedule sub-toggle --}}
<x-tenant.schedule-tabs active="calendar" />"""


EDITS = [
    ('routes/web.php',                                          OLD_ROUTES,       NEW_ROUTES,       'routes: deliveries + delivery resources'),
    ('resources/views/tenant/calendar/dropoff.blade.php',       OLD_DROPOFF_DAY,  NEW_DROPOFF_DAY,  'inject schedule-tabs into dropoff (day) view'),
    ('resources/views/tenant/calendar/dropoff-week.blade.php',  OLD_DROPOFF_WEEK, NEW_DROPOFF_WEEK, 'inject schedule-tabs into dropoff-week view'),
]

MARKER = 'MARKER-PATCH-152A'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)

    for rel, old, new, label in EDITS:
        p = root / rel
        if not p.exists():
            print(f'  ERROR: file missing for {label}: {rel}', file=sys.stderr); sys.exit(2)
        t = p.read_text()
        # Idempotence: if the marker is already present, skip regardless of whether
        # the old anchor still exists in the file.
        if MARKER in t:
            print(f'  already_applied: {label}')
            continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
