#!/usr/bin/env python3
"""
Patch 151-c — Traffic phase cleanup: retention + external-link panels.

Closes out the traffic reports phase. Three changes:

1. PruneFunnelEvents command (php artisan funnel:prune)
   Deletes tenant_funnel_events rows older than 90 days. Mirrors the
   debug-log:prune pattern — same --dry-run flag, same output style.
   Idempotent. Chunked delete to keep MySQL row-locks small.

2. Schedule the prune at 03:00 nightly (before debug-log:prune at 03:30).

3. Two link-out panels at the bottom of the traffic dashboard:
   - "Top search terms" — points tenants to Google Search Console.
     We don't and shouldn't track search referrer query strings
     (Google strips them anyway since 2013).
   - "Top locations" — points tenants to GA-4. We don't track IP, so
     we have no way to derive country/region. GA-4 does this natively
     once they wire up the measurement ID.

   Both panels render only when there's traffic data (no link-spam
   on empty dashboards).

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW: prune command
# ============================================================

PRUNE_COMMAND = '''<?php
// MARKER-PATCH-151C

namespace App\\Console\\Commands;

use App\\Models\\Tenant\\TenantFunnelEvent;
use Illuminate\\Console\\Command;

/**
 * Prune tenant_funnel_events older than 90 days.
 *
 * Aligned with the privacy notice — we keep aggregate analytics in
 * tenants\' own dashboards for a fixed 90-day window. Older rows have
 * little reporting value and add storage bloat.
 *
 * Chunked delete (5k rows per pass) so we don\'t hold a single huge
 * row-lock or transaction. Safe to run during business hours.
 */
class PruneFunnelEvents extends Command
{
    protected $signature = \'funnel:prune
                            {--days=90  : Retention window in days (default 90)}
                            {--chunk=5000 : Max rows per delete pass}
                            {--dry-run : Show how many rows would be deleted without deleting}\';

    protected $description = \'Delete tenant_funnel_events older than --days (default 90).\';

    public function handle(): int
    {
        $days  = max(1, (int) $this->option(\'days\'));
        $chunk = max(100, (int) $this->option(\'chunk\'));
        $dry   = (bool) $this->option(\'dry-run\');

        $cutoff = now()->subDays($days);

        $this->info($dry ? \'DRY RUN — no rows will be deleted\' : \'Pruning tenant_funnel_events...\');
        $this->line(sprintf(\'  cutoff:  %s (%d days)\', $cutoff->toDateTimeString(), $days));

        $total = 0;
        $passes = 0;

        while (true) {
            // Use a subquery to limit the delete batch. Eloquent\'s ->limit()
            // on delete() is supported in MySQL but not portable; this works.
            $ids = TenantFunnelEvent::where(\'created_at\', \'<\', $cutoff)
                ->orderBy(\'id\')
                ->limit($chunk)
                ->pluck(\'id\');

            if ($ids->isEmpty()) break;

            $count = $ids->count();

            if (! $dry) {
                TenantFunnelEvent::whereIn(\'id\', $ids)->delete();
            }

            $total  += $count;
            $passes += 1;
            $this->line(sprintf(\'  pass %d: %s rows\', $passes, number_format($count)));

            // Safety: never run more than 200 passes (= 1M rows by default).
            if ($passes >= 200) {
                $this->warn(\'  hit safety cap of 200 passes — stopping. Re-run to continue.\');
                break;
            }

            // In dry-run, the rows aren\'t actually deleted, so we\'d loop forever.
            if ($dry) break;
        }

        $this->info(sprintf(\'%s %s rows in %d %s.\',
            $dry ? \'Would prune\' : \'Pruned\',
            number_format($total),
            $passes,
            $passes === 1 ? \'pass\' : \'passes\'
        ));

        return self::SUCCESS;
    }
}
'''


# ============================================================
# EDIT 1: append schedule entry to routes/console.php
# ============================================================
# We append BEFORE the last domains:poll block — simple anchor at the
# domains:poll comment header so we land in a stable spot.

OLD_SCHEDULE_ANCHOR = """// ----------------------------------------------------------------
// MARKER-PATCH-118 - Custom domain state polling"""

NEW_SCHEDULE_ANCHOR = """// ----------------------------------------------------------------
// MARKER-PATCH-151C — Prune tenant_funnel_events older than 90 days.
// Cheap (single composite-indexed delete in chunks). Runs at 03:00 so
// it finishes well before debug-log:prune at 03:30.
// ----------------------------------------------------------------
Schedule::command('funnel:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// ----------------------------------------------------------------
// MARKER-PATCH-118 - Custom domain state polling"""


# ============================================================
# EDIT 2: add link-out panels to traffic.blade.php
# ============================================================
# Anchor: just before the closing `@endif` of the panel block. The
# current view ends like:
#
#         </div>
#       @endif
#     </div>
#   @endif
# </div>
# @endsection
#
# The first @endif closes the new-vs-returning conditional callout.
# The second @endif closes the "if no traffic at all" branch.
# We need to inject BEFORE the second @endif.

OLD_VIEW_TAIL = """      @endif
    @endif
  </div>
  @endif
</div>
@endsection"""

NEW_VIEW_TAIL = """      @endif
    @endif
  </div>

  {{-- MARKER-PATCH-151C — link-out panels for data we deliberately don't track --}}
  <div class="rep-two-col">
    {{-- Top search terms — Search Console link --}}
    <div class="rep-zone rep-link-out">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top search terms</div>
          <div class="rep-zone-sub">What people searched before finding you</div>
        </div>
      </div>
      <p style="font-size: 12.5px; line-height: 1.6; color: var(--ia-text-2, rgba(255,255,255,.78)); margin: 0 0 16px;">
        We don't track search referrer query strings — Google strips them from referrer headers for privacy, and accurate search-term data is only available through Search Console.
      </p>
      <a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer" class="rep-link-out-btn">
        Open Search Console
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M7 17L17 7M7 7h10v10"/>
        </svg>
      </a>
    </div>

    {{-- Top locations — GA-4 link --}}
    <div class="rep-zone rep-link-out">
      <div class="rep-zone-head">
        <div>
          <div class="rep-zone-title">Top locations</div>
          <div class="rep-zone-sub">Where your visitors are based</div>
        </div>
      </div>
      <p style="font-size: 12.5px; line-height: 1.6; color: var(--ia-text-2, rgba(255,255,255,.78)); margin: 0 0 16px;">
        We deliberately don't store IP addresses or geolocate visitors. If you've connected GA-4 in Settings &rarr; Communication, Google Analytics breaks visits down by country, region, and city.
      </p>
      @if(!empty($tenant->settings['analytics_ga4_id'] ?? null))
        <a href="https://analytics.google.com" target="_blank" rel="noopener noreferrer" class="rep-link-out-btn">
          Open GA-4 ({{ $tenant->settings['analytics_ga4_id'] }})
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M7 17L17 7M7 7h10v10"/>
          </svg>
        </a>
      @else
        <a href="{{ route('tenant.settings.index') }}#communication" class="rep-link-out-btn rep-link-out-btn--ghost">
          Connect Google Analytics &rarr;
        </a>
      @endif
    </div>
  </div>

  @endif
</div>
@endsection"""


# ============================================================
# EDIT 3: append CSS for the link-out buttons
# ============================================================

OLD_CSS_TAIL = """  .rep-bar-track > span {
    display: block;
    height: 100%;
    background: var(--ia-accent, #BEF264);
    border-radius: 99px;
  }
</style>"""

NEW_CSS_TAIL = """  .rep-bar-track > span {
    display: block;
    height: 100%;
    background: var(--ia-accent, #BEF264);
    border-radius: 99px;
  }

  /* MARKER-PATCH-151C — link-out panel CTAs */
  .rep-link-out { display: flex; flex-direction: column; }
  .rep-link-out p { flex: 1; }
  .rep-link-out-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    align-self: flex-start;
    padding: 9px 14px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ia-accent, #BEF264);
    background: rgba(190, 242, 100, 0.08);
    border: 1px solid rgba(190, 242, 100, 0.2);
    border-radius: 8px;
    text-decoration: none;
    transition: background .12s ease, border-color .12s ease;
  }
  .rep-link-out-btn:hover {
    background: rgba(190, 242, 100, 0.14);
    border-color: rgba(190, 242, 100, 0.35);
  }
  .rep-link-out-btn--ghost {
    color: var(--ia-text-2, rgba(255, 255, 255, 0.78));
    background: rgba(255, 255, 255, 0.04);
    border-color: var(--ia-border);
  }
  .rep-link-out-btn--ghost:hover {
    background: rgba(255, 255, 255, 0.07);
    border-color: rgba(255, 255, 255, 0.16);
  }
</style>"""


NEW_FILES = {
    'app/Console/Commands/PruneFunnelEvents.php': PRUNE_COMMAND,
}

EDITS = [
    ('routes/console.php',                                OLD_SCHEDULE_ANCHOR, NEW_SCHEDULE_ANCHOR, 'scheduler: funnel:prune nightly', 'MARKER-PATCH-151C — Prune tenant_funnel_events'),
    ('resources/views/tenant/reports/traffic.blade.php',  OLD_VIEW_TAIL,       NEW_VIEW_TAIL,       'view: link-out panels (Search Console + GA-4)', 'MARKER-PATCH-151C — link-out panels'),
    ('resources/views/tenant/reports/traffic.blade.php',  OLD_CSS_TAIL,        NEW_CSS_TAIL,        'view: link-out CSS', 'MARKER-PATCH-151C — link-out panel CTAs'),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-151-c [{mode}] target={root} ===\n')

    for rel, content in NEW_FILES.items():
        p = root / rel
        if p.exists() and p.read_text() == content:
            print(f'  unchanged: {rel}'); continue
        if a.apply:
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(content)
        print(f'  {"written" if a.apply else "would_write"}: {rel}')

    for rel, old, new, label, marker in EDITS:
        p = root / rel
        if not p.exists():
            print(f'  ERROR: file missing for {label}: {rel}', file=sys.stderr); sys.exit(2)
        t = p.read_text()
        if marker in t:
            print(f'  already_applied: {label}'); continue
        if old not in t:
            print(f'  ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'  ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'  {"applied" if a.apply else "would_apply"}: {label}')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')
        print('Verify: php artisan funnel:prune --dry-run')


if __name__ == '__main__':
    main()
