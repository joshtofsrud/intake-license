#!/usr/bin/env python3
"""
Patch 140 — Signed-up row becomes a full-width 30-day chart.

The first row of the trial funnel was a 100%-full bar (because signups
ARE the baseline — by definition 100%). That carried no information.

Replace it with a full-width chart inside the funnel card:
  - Top line: daily signups for the last 30 days
  - Bottom line: daily signups for the 30 days prior (muted)
  - Right-side: 30-day total + percent delta vs prior 30

The three downstream rows (onboarded / first booking / paid) stay as
funnel bars below the chart, still normalised against the signup
baseline.

Idempotent.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────
# Controller change: replace buildFunnel
# ─────────────────────────────────────────────────────────

OLD_BUILD = """    protected function buildFunnel(): array
    {
        $window = now()->subDays(30);
        $signedUp     = Tenant::where('created_at', '>=', $window)->count();
        $onboarded    = Tenant::where('created_at', '>=', $window)
            ->where('onboarding_status', 'complete')->count();
        $firstBooking = Tenant::where('created_at', '>=', $window)
            ->whereHas('appointments')->count();
        $paid         = Tenant::where('created_at', '>=', $window)
            ->where('subscription_status', 'active')->count();

        $base = max($signedUp, 1);
        return [
            ['label' => 'Signed up',           'count' => $signedUp,     'pct' => 100],
            ['label' => 'Completed onboarding','count' => $onboarded,    'pct' => round($onboarded / $base * 100)],
            ['label' => 'Took 1st booking',    'count' => $firstBooking, 'pct' => round($firstBooking / $base * 100)],
            ['label' => 'Converted to paid',   'count' => $paid,         'pct' => round($paid / $base * 100)],
        ];
    }"""

NEW_BUILD = """    // MARKER-PATCH-140 — Signed-up row becomes a chart; downstream stays as bars.
    protected function buildFunnel(): array
    {
        $window = now()->subDays(30);
        $signedUp     = Tenant::where('created_at', '>=', $window)->count();
        $onboarded    = Tenant::where('created_at', '>=', $window)
            ->where('onboarding_status', 'complete')->count();
        $firstBooking = Tenant::where('created_at', '>=', $window)
            ->whereHas('appointments')->count();
        $paid         = Tenant::where('created_at', '>=', $window)
            ->where('subscription_status', 'active')->count();

        $base = max($signedUp, 1);

        // Daily series for the chart: 30 days current + 30 days prior.
        $current = $this->dailySignups(now()->subDays(29)->startOfDay(), now()->endOfDay());
        $prior   = $this->dailySignups(now()->subDays(59)->startOfDay(), now()->subDays(30)->endOfDay());
        $priorTotal = array_sum($prior);
        $delta = $priorTotal > 0
            ? (int) round((($signedUp - $priorTotal) / $priorTotal) * 100)
            : ($signedUp > 0 ? 100 : 0);

        return [
            'signups' => [
                'current'    => $current,
                'prior'      => $prior,
                'total'      => $signedUp,
                'priorTotal' => $priorTotal,
                'delta'      => $delta,
            ],
            'stages' => [
                ['label' => 'Completed onboarding','count' => $onboarded,    'pct' => (int) round($onboarded / $base * 100)],
                ['label' => 'Took 1st booking',    'count' => $firstBooking, 'pct' => (int) round($firstBooking / $base * 100)],
                ['label' => 'Converted to paid',   'count' => $paid,         'pct' => (int) round($paid / $base * 100)],
            ],
        ];
    }

    /**
     * Return an array of integer signup counts, one per day, from $start to $end inclusive.
     */
    protected function dailySignups(\\Carbon\\Carbon $start, \\Carbon\\Carbon $end): array
    {
        $rows = Tenant::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');
        $out = [];
        for ($cur = $start->copy(); $cur <= $end; $cur->addDay()) {
            $out[] = (int) ($rows[$cur->toDateString()] ?? 0);
        }
        return $out;
    }"""


# ─────────────────────────────────────────────────────────
# View change: replace the funnel rendering
# ─────────────────────────────────────────────────────────

OLD_VIEW = """    <div class=\"pd-funnel\">
      <div class=\"pd-funnel-title\">Trial funnel · last 30 days</div>
      @foreach($funnel as $step)
        <div class=\"pd-funnel-row\">
          <div class=\"pd-funnel-step\">{{ $step['label'] }}</div>
          <div class=\"pd-funnel-bar\"><span style=\"width:{{ $step['pct'] }}%\"></span></div>
          <div class=\"pd-funnel-count\">{{ $step['count'] }} <small>· {{ $step['pct'] }}%</small></div>
        </div>
      @endforeach
    </div>"""

NEW_VIEW = """    {{-- MARKER-PATCH-140 — chart for signups, bars for downstream stages --}}
    <div class=\"pd-funnel\">
      <div class=\"pd-funnel-title\">Trial funnel · last 30 days</div>

      {{-- Signups chart row: full-card-width, takes the place of the old 'Signed up' bar --}}
      @php
        $sg = $funnel['signups'];
        $all = array_merge($sg['current'], $sg['prior']);
        $max = max(max($all), 1);
        $w = 600; $h = 70; $pad = 4;
        $plotW = $w - $pad * 2;
        $plotH = $h - $pad * 2;
        $points = function($series) use ($plotW, $plotH, $pad, $max) {
          $n = count($series);
          if ($n === 0) return '';
          $step = $plotW / max($n - 1, 1);
          $parts = [];
          foreach ($series as $i => $v) {
            $x = round($pad + $i * $step, 1);
            $y = round($pad + $plotH - ($v / $max) * $plotH, 1);
            $parts[] = ($i === 0 ? 'M' : 'L') . $x . ' ' . $y;
          }
          return implode(' ', $parts);
        };
        $deltaClass = $sg['delta'] > 0 ? 'up' : ($sg['delta'] < 0 ? 'down' : 'flat');
        $deltaLabel = $sg['delta'] > 0 ? \"+{$sg['delta']}%\" : ($sg['delta'] < 0 ? \"{$sg['delta']}%\" : 'flat');
      @endphp
      <div class=\"pd-signups\">
        <div class=\"pd-signups-head\">
          <div>
            <div class=\"pd-signups-label\">Signed up</div>
            <div class=\"pd-signups-num\">{{ $sg['total'] }} <small>last 30d · {{ $sg['priorTotal'] }} prior</small></div>
          </div>
          <div class=\"pd-signups-delta {{ $deltaClass }}\">{{ $deltaLabel }} <small>vs prior 30d</small></div>
        </div>
        <svg class=\"pd-signups-chart\" viewBox=\"0 0 {{ $w }} {{ $h }}\" preserveAspectRatio=\"none\">
          {{-- Prior 30 days, muted --}}
          <path d=\"{{ $points($sg['prior']) }}\" stroke=\"var(--pd-text-dim)\" stroke-width=\"1\" fill=\"none\" stroke-dasharray=\"3 3\" opacity=\"0.55\"/>
          {{-- Current 30 days, accent --}}
          <path d=\"{{ $points($sg['current']) }}\" stroke=\"var(--pd-accent)\" stroke-width=\"1.8\" fill=\"none\"/>
        </svg>
        <div class=\"pd-signups-legend\">
          <span><i class=\"pd-swatch current\"></i> Last 30d</span>
          <span><i class=\"pd-swatch prior\"></i> Prior 30d</span>
        </div>
      </div>

      {{-- Downstream stages stay as bars --}}
      @foreach($funnel['stages'] as $step)
        <div class=\"pd-funnel-row\">
          <div class=\"pd-funnel-step\">{{ $step['label'] }}</div>
          <div class=\"pd-funnel-bar\"><span style=\"width:{{ $step['pct'] }}%\"></span></div>
          <div class=\"pd-funnel-count\">{{ $step['count'] }} <small>· {{ $step['pct'] }}%</small></div>
        </div>
      @endforeach
    </div>"""


# ─────────────────────────────────────────────────────────
# CSS additions for the signups block
# ─────────────────────────────────────────────────────────

OLD_CSS_ANCHOR = """  .pd-funnel-count { width:90px; text-align:right; font-family:var(--pd-font-mono); font-size:12.5px; color:var(--pd-text); }
  .pd-funnel-count small { color:var(--pd-text-dim); font-size:10.5px; }"""

NEW_CSS_ANCHOR = """  .pd-funnel-count { width:90px; text-align:right; font-family:var(--pd-font-mono); font-size:12.5px; color:var(--pd-text); }
  .pd-funnel-count small { color:var(--pd-text-dim); font-size:10.5px; }

  /* MARKER-PATCH-140 — signups chart styles */
  .pd-signups { padding:6px 0 16px; border-bottom:1px solid var(--pd-border); margin-bottom:6px; }
  .pd-signups-head { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:10px; }
  .pd-signups-label { font-size:12px; color:var(--pd-text-muted); margin-bottom:2px; }
  .pd-signups-num { font-size:22px; font-weight:600; letter-spacing:-0.01em; }
  .pd-signups-num small { font-family:var(--pd-font-mono); font-size:11px; color:var(--pd-text-dim); margin-left:8px; font-weight:400; }
  .pd-signups-delta { font-family:var(--pd-font-mono); font-size:13px; text-align:right; font-weight:500; }
  .pd-signups-delta.up   { color:var(--pd-ok); }
  .pd-signups-delta.down { color:var(--pd-bad); }
  .pd-signups-delta.flat { color:var(--pd-text-dim); }
  .pd-signups-delta small { display:block; color:var(--pd-text-dim); font-size:10.5px; font-weight:400; margin-top:2px; }
  .pd-signups-chart { width:100%; height:70px; }
  .pd-signups-legend { display:flex; gap:14px; margin-top:8px; font-size:10.5px; color:var(--pd-text-dim); }
  .pd-signups-legend .pd-swatch { display:inline-block; width:14px; height:2px; vertical-align:middle; margin-right:6px; }
  .pd-signups-legend .pd-swatch.current { background:var(--pd-accent); }
  .pd-signups-legend .pd-swatch.prior   { background:var(--pd-text-dim); }"""


EDITS = [
    ('app/Filament/Pages/PlatformDashboard.php', OLD_BUILD, NEW_BUILD, 'controller buildFunnel'),
    ('resources/views/filament/pages/platform-dashboard.blade.php', OLD_VIEW, NEW_VIEW, 'view funnel render'),
    ('resources/views/filament/pages/platform-dashboard.blade.php', OLD_CSS_ANCHOR, NEW_CSS_ANCHOR, 'view funnel css'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    for rel, old, new, label in EDITS:
        p = root / rel
        t = p.read_text()
        # Idempotence: if any patch-140 marker for this label is already in the file,
        # AND the OLD anchor still matches, it means the new block was appended without
        # the old being consumed — which is a bug condition. Refuse to re-apply.
        marker_present = 'MARKER-PATCH-140' in t
        if old in t and marker_present:
            # Already partially-applied state. Skip silently to avoid duplicating.
            print(f'already_applied (marker found): {label}')
            continue
        if old not in t:
            if new in t or marker_present:
                print(f'already_applied: {label}'); continue
            print(f'ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'{"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
