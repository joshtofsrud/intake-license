#!/usr/bin/env python3
"""
Patch 148 — Master-admin email health page.

Adds a Filament page at /admin/email-health (under Platform nav group)
that gives Josh platform-wide visibility into SES sending hygiene.

Surfaces:
  - Top tiles: platform-wide suppressions, tenant-scoped suppressions,
    bounce events 7d, complaint events 7d
  - Tenants by bounce rate (last 7d) — early warning sign of a tenant
    about to damage shared SES reputation
  - Recent bounce events feed (last 50)
  - Search input — find any suppressed address across all tenants

Files added:
  - app/Filament/Pages/EmailHealth.php
  - resources/views/filament/pages/email-health.blade.php

Files edited:
  - app/Providers/Filament/AdminPanelProvider.php  (register page)

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW FILES
# ============================================================

PAGE = r'''<?php
// MARKER-PATCH-148

namespace App\Filament\Pages;

use App\Models\Tenant\TenantEmailBounceEvent;
use App\Models\Tenant\TenantEmailSuppression;
use App\Models\Tenant;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Master-admin email health page.
 *
 * Read-only view of platform-wide email sending hygiene. Surfaces the
 * data Josh needs to spot a problem tenant before AWS does.
 */
class EmailHealth extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-envelope';
    protected static ?string $navigationLabel = 'Email health';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 20;

    protected static string $view = 'filament.pages.email-health';

    public ?string $search = null;

    public function getViewData(): array
    {
        $search = trim((string) request()->query('q', ''));

        return [
            'tiles'       => $this->buildTiles(),
            'byBounce'    => $this->tenantsByBounceRate(),
            'recent'      => $this->recentEvents(),
            'searchTerm'  => $search,
            'searchHits'  => $search !== '' ? $this->searchSuppressions($search) : null,
            'generatedAt' => now(),
        ];
    }

    protected function buildTiles(): array
    {
        $week = now()->subDays(7);

        return [
            'platform' => TenantEmailSuppression::whereNull('tenant_id')->count(),
            'tenant'   => TenantEmailSuppression::whereNotNull('tenant_id')->count(),
            'bounces7' => TenantEmailBounceEvent::where('event_type', 'bounce')
                ->where('received_at', '>=', $week)->count(),
            'complaints7' => TenantEmailBounceEvent::where('event_type', 'complaint')
                ->where('received_at', '>=', $week)->count(),
        ];
    }

    /**
     * Tenants with the highest bounce rate in the last 7 days.
     * Bounce rate = bounces / (bounces + estimated sends).
     *
     * "Estimated sends" is approximated from debug_logs mail.sent events
     * if available; otherwise we just show bounce count.
     */
    protected function tenantsByBounceRate(): array
    {
        $week = now()->subDays(7);

        // Bounce counts grouped by tenant
        $bounces = TenantEmailBounceEvent::where('event_type', 'bounce')
            ->where('received_at', '>=', $week)
            ->whereNotNull('tenant_id')
            ->select('tenant_id', DB::raw('COUNT(*) as n'))
            ->groupBy('tenant_id')
            ->orderByDesc('n')
            ->limit(10)
            ->get();

        if ($bounces->isEmpty()) return [];

        $tenants = Tenant::whereIn('id', $bounces->pluck('tenant_id'))
            ->get(['id', 'name', 'subdomain'])
            ->keyBy('id');

        return $bounces->map(function ($row) use ($tenants) {
            $t = $tenants[$row->tenant_id] ?? null;
            // Estimated sends via mail.sent log channel
            $sent = 0;
            try {
                $sent = (int) DB::table('debug_logs')
                    ->where('channel', 'mail')
                    ->where('event', 'mail.sent')
                    ->where('tenant_id', $row->tenant_id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();
            } catch (\Throwable $e) { /* table column shape may vary; degrade gracefully */ }

            $total = $sent + $row->n;
            $rate = $total > 0 ? round(($row->n / $total) * 100, 1) : null;

            return [
                'tenant_id' => $row->tenant_id,
                'name'      => $t->name ?? '(unknown tenant)',
                'subdomain' => $t->subdomain ?? null,
                'bounces'   => (int) $row->n,
                'sent'      => $sent,
                'rate'      => $rate,
                'severity'  => $this->rateSeverity($rate, $row->n),
            ];
        })->all();
    }

    /**
     * Categorise rate: AWS suspends accounts above 5% sustained.
     * Anything over 2% is worth investigating; over 5% is alarm.
     */
    protected function rateSeverity(?float $rate, int $bounces): string
    {
        if ($rate === null) {
            return $bounces > 5 ? 'warn' : 'info';
        }
        if ($rate >= 5)  return 'bad';
        if ($rate >= 2)  return 'warn';
        return 'ok';
    }

    /**
     * Most recent bounce + complaint events across all tenants.
     */
    protected function recentEvents(): array
    {
        return TenantEmailBounceEvent::orderByDesc('received_at')
            ->limit(50)
            ->get()
            ->map(function ($e) {
                $tenant = $e->tenant_id ? Tenant::find($e->tenant_id) : null;
                return [
                    'id'         => $e->id,
                    'email'      => $e->email,
                    'event_type' => $e->event_type,
                    'subtype'    => $e->bounce_subtype,
                    'tenant'     => $tenant?->name,
                    'received_at'=> $e->received_at,
                ];
            })
            ->all();
    }

    /**
     * Search the suppression list across all tenants.
     */
    protected function searchSuppressions(string $term): array
    {
        return TenantEmailSuppression::where('email', 'like', '%' . $term . '%')
            ->orderByDesc('suppressed_at')
            ->limit(50)
            ->get()
            ->map(function ($s) {
                $tenant = $s->tenant_id ? Tenant::find($s->tenant_id) : null;
                return [
                    'id'             => $s->id,
                    'email'          => $s->email,
                    'tenant_id'      => $s->tenant_id,
                    'tenant_name'    => $tenant?->name,
                    'reason'         => $s->reason,
                    'suppressed_at'  => $s->suppressed_at,
                    'is_platform'    => is_null($s->tenant_id),
                ];
            })
            ->all();
    }
}
'''


VIEW = r'''{{-- MARKER-PATCH-148 — master-admin email health --}}
<x-filament-panels::page>

<style>
  .eh {
    --eh-bg: #ffffff;
    --eh-surface-2: #f7f7f8;
    --eh-border: rgba(0,0,0,.08);
    --eh-border-2: rgba(0,0,0,.15);
    --eh-text: #111827;
    --eh-text-muted: rgba(17,24,39,.7);
    --eh-text-dim: rgba(17,24,39,.5);
    --eh-ok: #16A34A;
    --eh-warn: #D97706;
    --eh-bad: #DC2626;
    --eh-info: #0284C7;
    --eh-mono: 'JetBrains Mono', ui-monospace, monospace;
    color: var(--eh-text);
    font-size: 13.5px;
  }
  .dark .eh {
    --eh-bg: #131313;
    --eh-surface-2: #1a1a1a;
    --eh-border: rgba(255,255,255,.08);
    --eh-border-2: rgba(255,255,255,.18);
    --eh-text: #f0f0f0;
    --eh-text-muted: rgba(255,255,255,.62);
    --eh-text-dim: rgba(255,255,255,.42);
  }

  .eh-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
  .eh-tile { background: var(--eh-bg); border: 1px solid var(--eh-border); border-radius: 10px; padding: 16px 18px; }
  .eh-tile-lbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--eh-text-dim); font-weight: 500; }
  .eh-tile-val { font-size: 28px; font-weight: 600; letter-spacing: -0.01em; line-height: 1.15; margin-top: 4px; }
  .eh-tile-sub { font-size: 11.5px; color: var(--eh-text-dim); margin-top: 4px; font-family: var(--eh-mono); }

  .eh-section { margin-bottom: 28px; }
  .eh-section-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
  .eh-section-title { font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--eh-text-muted); font-weight: 500; }
  .eh-section-sub { font-size: 11.5px; color: var(--eh-text-dim); }

  .eh-card { background: var(--eh-bg); border: 1px solid var(--eh-border); border-radius: 10px; overflow: hidden; }

  .eh-row { display: grid; gap: 14px; padding: 12px 18px; border-bottom: 1px solid var(--eh-border); align-items: center; }
  .eh-row:last-child { border-bottom: none; }
  .eh-row:hover { background: var(--eh-surface-2); }
  .eh-row-head { background: var(--eh-surface-2); font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--eh-text-muted); padding: 9px 18px; }
  .eh-row-head:hover { background: var(--eh-surface-2); }

  .eh-pill { display: inline-block; padding: 2px 9px; font-size: 11px; border-radius: 999px; }
  .eh-pill.ok { background: rgba(22,163,74,.12); color: var(--eh-ok); }
  .eh-pill.warn { background: rgba(217,119,6,.14); color: var(--eh-warn); }
  .eh-pill.bad { background: rgba(220,38,38,.12); color: var(--eh-bad); }
  .eh-pill.info { background: rgba(2,132,199,.12); color: var(--eh-info); }
  .eh-pill.muted { background: var(--eh-surface-2); color: var(--eh-text-muted); }

  .eh-empty { padding: 32px 18px; text-align: center; color: var(--eh-text-muted); font-size: 13px; }

  .eh-search { display: flex; gap: 8px; margin-bottom: 14px; }
  .eh-input { flex: 1; padding: 7px 11px; border: 1px solid var(--eh-border-2); border-radius: 6px; background: var(--eh-bg); color: var(--eh-text); font-size: 13px; font-family: inherit; }

  .eh-mono { font-family: var(--eh-mono); }
</style>

<div class="eh">

  {{-- Top tiles --}}
  <div class="eh-tiles">
    <div class="eh-tile">
      <div class="eh-tile-lbl">Platform suppressions</div>
      <div class="eh-tile-val">{{ number_format($tiles['platform']) }}</div>
      <div class="eh-tile-sub">addresses blocked everywhere</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Tenant suppressions</div>
      <div class="eh-tile-val">{{ number_format($tiles['tenant']) }}</div>
      <div class="eh-tile-sub">blocked at one tenant</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Bounces · 7d</div>
      <div class="eh-tile-val">{{ number_format($tiles['bounces7']) }}</div>
      <div class="eh-tile-sub">across all tenants</div>
    </div>
    <div class="eh-tile">
      <div class="eh-tile-lbl">Complaints · 7d</div>
      <div class="eh-tile-val">{{ number_format($tiles['complaints7']) }}</div>
      <div class="eh-tile-sub">SES reputation risk</div>
    </div>
  </div>

  {{-- Search --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Find an address</div>
    </div>
    <form method="GET" class="eh-search">
      <input class="eh-input" type="text" name="q" placeholder="Search suppressed addresses across all tenants…" value="{{ $searchTerm }}">
      <button type="submit" style="padding: 7px 14px; border-radius: 6px; border: 1px solid var(--eh-border-2); background: var(--eh-bg); color: var(--eh-text); font-size: 13px; cursor: pointer;">Search</button>
    </form>

    @if($searchHits !== null)
      <div class="eh-card">
        @if(count($searchHits) === 0)
          <div class="eh-empty">No matches for "{{ $searchTerm }}"</div>
        @else
          <div class="eh-row eh-row-head" style="grid-template-columns: 1.6fr 130px 130px 110px;">
            <div>Email</div><div>Scope</div><div>Reason</div><div>When</div>
          </div>
          @foreach($searchHits as $hit)
            <div class="eh-row" style="grid-template-columns: 1.6fr 130px 130px 110px;">
              <div class="eh-mono" style="font-size: 12.5px;">{{ $hit['email'] }}</div>
              <div>
                @if($hit['is_platform'])
                  <span class="eh-pill info">Platform-wide</span>
                @else
                  <span class="eh-pill muted">{{ $hit['tenant_name'] ?? '—' }}</span>
                @endif
              </div>
              <div>
                @if($hit['reason'] === 'bounce')<span class="eh-pill bad">Bounce</span>
                @elseif($hit['reason'] === 'complaint')<span class="eh-pill warn">Complaint</span>
                @else<span class="eh-pill muted">{{ ucfirst($hit['reason']) }}</span>
                @endif
              </div>
              <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">
                {{ $hit['suppressed_at']?->diffForHumans(null, true) }} ago
              </div>
            </div>
          @endforeach
        @endif
      </div>
    @endif
  </div>

  {{-- Tenants by bounce rate --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Tenants by bounce rate · last 7 days</div>
      <div class="eh-section-sub">Watch these. AWS suspends accounts above 5% sustained.</div>
    </div>
    <div class="eh-card">
      @if(count($byBounce) === 0)
        <div class="eh-empty">No bounces in the last 7 days. 🎉</div>
      @else
        <div class="eh-row eh-row-head" style="grid-template-columns: 1.4fr 90px 90px 110px 90px;">
          <div>Tenant</div><div>Bounces</div><div>Sent</div><div>Rate</div><div></div>
        </div>
        @foreach($byBounce as $row)
          <div class="eh-row" style="grid-template-columns: 1.4fr 90px 90px 110px 90px;">
            <div>
              <div style="font-weight: 500;">{{ $row['name'] }}</div>
              <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">{{ $row['subdomain'] ?? '—' }}.intake.works</div>
            </div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['bounces'] }}</div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['sent'] > 0 ? number_format($row['sent']) : '—' }}</div>
            <div style="font-family: var(--eh-mono); font-size: 13px;">{{ $row['rate'] !== null ? $row['rate'] . '%' : '—' }}</div>
            <div>
              @if($row['severity'] === 'bad')<span class="eh-pill bad">Investigate</span>
              @elseif($row['severity'] === 'warn')<span class="eh-pill warn">Watch</span>
              @elseif($row['severity'] === 'ok')<span class="eh-pill ok">OK</span>
              @else<span class="eh-pill info">Low data</span>
              @endif
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  {{-- Recent events --}}
  <div class="eh-section">
    <div class="eh-section-head">
      <div class="eh-section-title">Recent bounce &amp; complaint events</div>
      <div class="eh-section-sub">Last 50 across all tenants</div>
    </div>
    <div class="eh-card">
      @if(count($recent) === 0)
        <div class="eh-empty">No bounce or complaint events yet.</div>
      @else
        <div class="eh-row eh-row-head" style="grid-template-columns: 1.6fr 100px 1fr 120px;">
          <div>Email</div><div>Type</div><div>Tenant</div><div>When</div>
        </div>
        @foreach($recent as $e)
          <div class="eh-row" style="grid-template-columns: 1.6fr 100px 1fr 120px;">
            <div class="eh-mono" style="font-size: 12.5px;">{{ $e['email'] }}</div>
            <div>
              @if($e['event_type'] === 'bounce')<span class="eh-pill bad">Bounce</span>
              @else<span class="eh-pill warn">Complaint</span>
              @endif
            </div>
            <div style="font-size: 12.5px;">{{ $e['tenant'] ?? '—' }}</div>
            <div style="font-size: 11.5px; color: var(--eh-text-dim); font-family: var(--eh-mono);">
              {{ $e['received_at']?->diffForHumans(null, true) }} ago
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  <div style="font-size: 11px; color: var(--eh-text-dim); text-align: right; font-family: var(--eh-mono); margin-top: 16px;">
    Generated {{ $generatedAt->format('Y-m-d H:i:s') }} UTC
  </div>

</div>

</x-filament-panels::page>
'''


# ============================================================
# EDITS
# ============================================================

# Register EmailHealth in the admin panel pages list
OLD_PAGES = """            ->pages([
                // MARKER-PATCH-135 — custom dashboard replaces Pages\\Dashboard
                \\App\\Filament\\Pages\\PlatformDashboard::class,
                ThemeEditor::class,
                \\App\\Filament\\Pages\\BillingConfiguration::class,
                \\App\\Filament\\Pages\\ChangelogImportPreview::class,
            ])"""

NEW_PAGES = """            ->pages([
                // MARKER-PATCH-135 — custom dashboard replaces Pages\\Dashboard
                \\App\\Filament\\Pages\\PlatformDashboard::class,
                ThemeEditor::class,
                \\App\\Filament\\Pages\\BillingConfiguration::class,
                \\App\\Filament\\Pages\\ChangelogImportPreview::class,
                \\App\\Filament\\Pages\\EmailHealth::class,  // MARKER-PATCH-148
            ])"""


NEW_FILES = {
    'app/Filament/Pages/EmailHealth.php':                      PAGE,
    'resources/views/filament/pages/email-health.blade.php':   VIEW,
}

EDITS = [
    ('app/Providers/Filament/AdminPanelProvider.php', OLD_PAGES, NEW_PAGES, 'AdminPanelProvider pages list', 'MARKER-PATCH-148'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-148 [{mode}] target={root} ===\n')

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
        print('\nDeploy steps after pull:')
        print('  php artisan optimize:clear')
        print('  php artisan filament:clear-cached-components')
        print('  systemctl stop php8.3-fpm && sleep 2 && systemctl start php8.3-fpm')
    else:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
