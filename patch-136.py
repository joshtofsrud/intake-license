#!/usr/bin/env python3
"""
Patch 136 — fix blade parse error in PlatformDashboard.

The week-delta line in platform-dashboard.blade.php uses an inline
@elseif with a `< 0` comparison. Blade's expression parser treats `<`
as the start of an HTML tag in some inline-directive contexts, so the
template fails to compile.

Fix: drop the inline @elseif, compute the trend tone + sign in the
controller, render plain HTML in the view.

Idempotent.
"""

import argparse
import pathlib
import sys


OLD_VIEW = '''        <div class="pd-biz-delta">
          @if($saas['weekDelta'] > 0)<b>+{{ $saas['weekDelta'] }}%</b>@elseif($saas['weekDelta'] < 0)<b class="down">{{ $saas['weekDelta'] }}%</b>@else flat@endif
          vs last week
        </div>'''

NEW_VIEW = '''        <div class="pd-biz-delta">
          {{-- MARKER-PATCH-136 — trend rendered from controller-computed tone --}}
          @if($saas['weekTrend'] === 'flat')
            flat vs last week
          @else
            <b class="{{ $saas['weekTrend'] === 'down' ? 'down' : '' }}">{{ $saas['weekDeltaLabel'] }}</b>
            vs last week
          @endif
        </div>'''


OLD_CTRL = '''        $newLastWeek  = Tenant::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();
        $weekDelta    = $newLastWeek > 0
            ? round((($newThisWeek - $newLastWeek) / $newLastWeek) * 100)
            : ($newThisWeek > 0 ? 100 : 0);'''

NEW_CTRL = '''        $newLastWeek  = Tenant::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();
        $weekDelta    = $newLastWeek > 0
            ? (int) round((($newThisWeek - $newLastWeek) / $newLastWeek) * 100)
            : ($newThisWeek > 0 ? 100 : 0);
        // MARKER-PATCH-136 — precompute trend so the view doesn't need inline @elseif comparisons.
        $weekTrend       = $weekDelta > 0 ? 'up' : ($weekDelta < 0 ? 'down' : 'flat');
        $weekDeltaLabel  = $weekDelta > 0 ? "+{$weekDelta}%" : ($weekDelta < 0 ? "{$weekDelta}%" : 'flat');'''


OLD_RETURN = '''            'weekDelta'         => $weekDelta,
            'mrr'               => $mrr,'''

NEW_RETURN = '''            'weekDelta'         => $weekDelta,
            'weekTrend'         => $weekTrend,        // MARKER-PATCH-136
            'weekDeltaLabel'    => $weekDeltaLabel,   // MARKER-PATCH-136
            'mrr'               => $mrr,'''


EDITS = [
    ('app/Filament/Pages/PlatformDashboard.php', OLD_CTRL, NEW_CTRL, 'controller delta calc'),
    ('app/Filament/Pages/PlatformDashboard.php', OLD_RETURN, NEW_RETURN, 'controller return'),
    ('resources/views/filament/pages/platform-dashboard.blade.php', OLD_VIEW, NEW_VIEW, 'view delta render'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    for rel, old, new, label in EDITS:
        p = root / rel
        t = p.read_text()
        if old not in t:
            if new in t:
                print(f'already_applied: {label}'); continue
            print(f'ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'{"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
