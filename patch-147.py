#!/usr/bin/env python3
"""
Patch 147 — Tenant-facing email suppression list.

Adds /admin/email/suppressions where tenants can see which of their
customers' addresses are blocked from receiving mail, and why. They
can remove non-permanent suppressions (bounces, unsubscribes, manual)
and manually add new ones.

Complaints are permanent and not removable from this UI — that would
defeat the recipient's "this is spam" signal and put SES reputation
at risk.

Files added:
  - app/Http/Controllers/Tenant/SuppressionController.php
  - resources/views/tenant/email/suppressions.blade.php

Files edited:
  - routes/web.php  (add 3 routes for index, destroy, manual create)
  - resources/views/layouts/tenant/_nav-items.blade.php  (add nav entry)

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# NEW FILES
# ============================================================

CONTROLLER = r'''<?php
// MARKER-PATCH-147

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailSuppression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Tenant-facing suppression list.
 *
 * Shows addresses that won't receive this tenant's mail, scoped to
 * tenant-specific rows AND platform-wide rows that would affect them.
 *
 * Manager+ permission required (same gate as Settings).
 */
class SuppressionController extends Controller
{
    /**
     * GET /admin/email/suppressions
     */
    public function index(string $subdomain, Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return redirect()->route('tenant.dashboard', $subdomain);
        }

        $tenant = tenant();
        $tab = $request->query('tab', 'all');

        // Pull both tenant-scoped and platform-wide suppressions that would
        // block this tenant's outbound mail.
        $query = TenantEmailSuppression::where(function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)
              ->orWhereNull('tenant_id');
        });

        switch ($tab) {
            case 'bounced':
                $query->where('reason', 'bounce');
                break;
            case 'complained':
                $query->where('reason', 'complaint');
                break;
            case 'other':
                $query->whereIn('reason', ['unsubscribe', 'manual']);
                break;
            // 'all' — no extra filter
        }

        $rows = $query->orderByDesc('suppressed_at')->paginate(50);

        // Tab counts (always over the full set, not filtered)
        $base = TenantEmailSuppression::where(function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
        });
        $counts = [
            'all'        => (clone $base)->count(),
            'bounced'    => (clone $base)->where('reason', 'bounce')->count(),
            'complained' => (clone $base)->where('reason', 'complaint')->count(),
            'other'      => (clone $base)->whereIn('reason', ['unsubscribe', 'manual'])->count(),
        ];

        return view('tenant.email.suppressions', [
            'rows'   => $rows,
            'counts' => $counts,
            'tab'    => $tab,
        ]);
    }

    /**
     * DELETE /admin/email/suppressions/{id}
     *
     * Removes a tenant-scoped suppression. Platform-wide rows and
     * complaint-reason rows are not removable here.
     */
    public function destroy(string $subdomain, int $id)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $tenant = tenant();
        $row = TenantEmailSuppression::where('id', $id)
            ->where('tenant_id', $tenant->id)   // only tenant-scoped rows
            ->first();

        if (! $row) {
            return back()->with('error', 'Suppression not found or not yours to remove.');
        }
        if ($row->reason === 'complaint') {
            return back()->with('error', 'Complaints cannot be removed — the recipient marked your mail as spam.');
        }

        $email = $row->email;
        $row->delete();

        Log::info('Suppression removed', [
            'tenant_id' => $tenant->id,
            'email'     => $email,
            'by'        => $me->email,
        ]);

        return back()->with('success', "Removed {$email} from your suppression list.");
    }

    /**
     * POST /admin/email/suppressions
     *
     * Manually suppress an address for this tenant only.
     */
    public function store(string $subdomain, Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = tenant();
        $email = strtolower(trim($data['email']));

        TenantEmailSuppression::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'reason'                => 'manual',
                'notes'                 => $data['notes'] ?? null,
                'suppressed_by_user_id' => $me->id,
                'suppressed_at'         => now(),
            ]
        );

        Log::info('Suppression added (manual)', [
            'tenant_id' => $tenant->id,
            'email'     => $email,
            'by'        => $me->email,
        ]);

        return back()->with('success', "{$email} will no longer receive mail.");
    }
}
'''


VIEW = r'''@extends('layouts.tenant.app')
@php $pageTitle = 'Email suppressions'; @endphp

{{-- MARKER-PATCH-147 — tenant suppression list --}}

@push('styles')
<style>
  .sup-tabs { display: inline-flex; background: var(--ia-surface-2); border-radius: var(--ia-r-md); padding: 3px; font-size: 12.5px; margin-bottom: 14px; }
  .sup-tabs a {
    padding: 5px 14px;
    border-radius: 6px;
    color: var(--ia-text-muted);
    text-decoration: none;
  }
  .sup-tabs a.active {
    background: var(--ia-surface);
    color: var(--ia-text);
    font-weight: 500;
    box-shadow: 0 1px 2px rgba(0,0,0,.06);
  }
  .sup-tabs a:hover { color: var(--ia-text); }

  .sup-row {
    display: grid;
    grid-template-columns: 1.6fr 130px 130px 1fr auto;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--ia-border);
    align-items: center;
    font-size: 13px;
  }
  .sup-row:last-child { border-bottom: none; }
  .sup-row:hover { background: var(--ia-surface-2); }

  .sup-row-head {
    background: var(--ia-surface-2);
    font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
    color: var(--ia-text-muted); font-weight: 500;
    padding: 9px 16px;
  }
  .sup-row-head:hover { background: var(--ia-surface-2); }

  .sup-pill {
    display: inline-block;
    padding: 2px 9px;
    font-size: 11px;
    border-radius: 999px;
    line-height: 1.6;
  }
  .sup-pill.bad  { background: rgba(192,57,43,.12);  color: var(--ia-bad, #C0392B); }
  .sup-pill.warn { background: rgba(198,130,16,.14); color: var(--ia-warn, #C68210); }
  .sup-pill.muted { background: var(--ia-surface-2); color: var(--ia-text-muted); }

  .sup-platform-badge {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    font-size: 10px;
    border-radius: 3px;
    background: rgba(48,102,176,.12);
    color: var(--ia-info, #3066B0);
    vertical-align: middle;
  }

  .sup-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--ia-text-muted);
  }
  .sup-empty .icon { font-size: 32px; opacity: .25; margin-bottom: 8px; }

  .sup-add-block {
    background: var(--ia-surface-2);
    border-radius: var(--ia-r-md);
    padding: 14px 16px;
    margin-top: 14px;
    display: none;
  }
  .sup-add-block.open { display: block; }

  .sup-mono { font-family: var(--ia-font-mono); font-size: 12.5px; }
</style>
@endpush

@section('content')
<div class="ia-page">
  <div class="ia-page-header">
    <div>
      <h1 class="ia-page-title">Email suppressions</h1>
      <div class="ia-page-sub">Addresses that won't receive your mail. Automatically populated from bounces, complaints, and unsubscribes.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('sup-add').classList.toggle('open')">
      + Manually suppress
    </button>
  </div>

  @if(session('success'))
    <div class="ia-flash ia-flash--ok" style="margin-bottom: 12px;">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div class="ia-flash ia-flash--err" style="margin-bottom: 12px;">{{ session('error') }}</div>
  @endif

  <div class="ia-card">
    <div class="sup-tabs">
      <a href="{{ route('tenant.suppressions.index', ['subdomain' => $currentTenant->subdomain, 'tab' => 'all']) }}"
         class="{{ $tab === 'all' ? 'active' : '' }}">All · {{ $counts['all'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['subdomain' => $currentTenant->subdomain, 'tab' => 'bounced']) }}"
         class="{{ $tab === 'bounced' ? 'active' : '' }}">Bounced · {{ $counts['bounced'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['subdomain' => $currentTenant->subdomain, 'tab' => 'complained']) }}"
         class="{{ $tab === 'complained' ? 'active' : '' }}">Complained · {{ $counts['complained'] }}</a>
      <a href="{{ route('tenant.suppressions.index', ['subdomain' => $currentTenant->subdomain, 'tab' => 'other']) }}"
         class="{{ $tab === 'other' ? 'active' : '' }}">Unsub'd / Manual · {{ $counts['other'] }}</a>
    </div>

    <div id="sup-add" class="sup-add-block">
      <div style="font-size: 13px; font-weight: 500; margin-bottom: 8px;">Manually suppress an address</div>
      <form method="POST" action="{{ route('tenant.suppressions.store', ['subdomain' => $currentTenant->subdomain]) }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;">
        @csrf
        <div style="flex: 1; min-width: 240px;">
          <label class="ia-label" style="margin-bottom: 4px;">Email</label>
          <input type="email" name="email" class="ia-input" required placeholder="customer@example.com">
        </div>
        <div style="flex: 2; min-width: 240px;">
          <label class="ia-label" style="margin-bottom: 4px;">Notes (optional)</label>
          <input type="text" name="notes" class="ia-input" placeholder="Why you're suppressing this">
        </div>
        <button type="submit" class="ia-btn ia-btn--primary">Suppress</button>
      </form>
    </div>

    @if($rows->isEmpty())
      <div class="sup-empty">
        <div class="icon">✓</div>
        <div style="font-size: 14px; font-weight: 500; color: var(--ia-text);">No suppressed addresses</div>
        <div style="font-size: 12px; margin-top: 4px;">When a customer's mail bounces, complains, or unsubscribes, they show up here.</div>
      </div>
    @else
      <div class="ia-row-list" style="border: 1px solid var(--ia-border); border-radius: var(--ia-r-md); overflow: hidden; margin-top: 14px;">
        <div class="sup-row sup-row-head">
          <div>Email</div>
          <div>Reason</div>
          <div>Suppressed</div>
          <div>Diagnostic</div>
          <div></div>
        </div>
        @foreach($rows as $row)
          <div class="sup-row">
            <div>
              <span class="sup-mono">{{ $row->email }}</span>
              @if(is_null($row->tenant_id))
                <span class="sup-platform-badge" title="Suppressed platform-wide — not just on your list">platform</span>
              @endif
            </div>
            <div>
              @if($row->reason === 'bounce')
                <span class="sup-pill bad">{{ $row->subtype === 'Permanent' ? 'Hard bounce' : 'Bounced' }}</span>
              @elseif($row->reason === 'complaint')
                <span class="sup-pill warn">Spam complaint</span>
              @elseif($row->reason === 'unsubscribe')
                <span class="sup-pill muted">Unsubscribed</span>
              @else
                <span class="sup-pill muted">{{ ucfirst($row->reason) }}</span>
              @endif
            </div>
            <div style="color: var(--ia-text-muted); font-size: 12px;">
              {{ $row->suppressed_at?->format('M j, Y') ?? '—' }}
            </div>
            <div style="font-size: 11.5px; color: var(--ia-text-dim); font-family: var(--ia-font-mono);">
              {{ \Illuminate\Support\Str::limit($row->diagnostic ?? $row->notes ?? '—', 60) }}
            </div>
            <div style="text-align: right;">
              @if($row->reason === 'complaint' || is_null($row->tenant_id))
                <span style="font-size: 11.5px; color: var(--ia-text-dim);">Permanent</span>
              @else
                <form method="POST" action="{{ route('tenant.suppressions.destroy', ['subdomain' => $currentTenant->subdomain, 'id' => $row->id]) }}" style="display: inline;">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="ia-btn ia-btn--ghost" style="font-size: 11.5px; padding: 4px 10px;"
                          onclick="return confirm('Remove {{ $row->email }} from your suppression list? They\'ll receive future mail again.')">
                    Remove
                  </button>
                </form>
              @endif
            </div>
          </div>
        @endforeach
      </div>

      <div style="margin-top: 14px;">
        {{ $rows->withQueryString()->links() }}
      </div>
    @endif

    <div style="margin-top: 18px; padding: 12px 14px; background: rgba(48,102,176,.06); border-radius: var(--ia-r-md); font-size: 12.5px; color: var(--ia-text-muted); line-height: 1.6;">
      <strong style="color: var(--ia-text);">How this works.</strong>
      When a customer's email bounces (mailbox doesn't exist) or they mark your mail as spam, they're automatically added here so you don't accidentally send them more — which would hurt your shop's sender reputation.
      Complaints are permanent. Bounces and manual entries can be removed once you've fixed the underlying issue.
      Addresses marked <span class="sup-platform-badge">platform</span> are suppressed across all of Intake, usually because they bounced from multiple shops.
    </div>
  </div>
</div>
@endsection
'''


# ============================================================
# EDITS
# ============================================================

# routes/web.php — add suppression routes inside the existing tenant admin route group.
# The settings.email.test route is the easiest anchor (added in patch 143).
OLD_ROUTES = """            // MARKER-PATCH-143 — Test email send endpoint (settings card)
            Route::post('/settings/email/test', [TenantControllers\\TestEmailController::class, 'sendSettingsTest'])->name('settings.email.test');"""

NEW_ROUTES = """            // MARKER-PATCH-143 — Test email send endpoint (settings card)
            Route::post('/settings/email/test', [TenantControllers\\TestEmailController::class, 'sendSettingsTest'])->name('settings.email.test');

            // MARKER-PATCH-147 — Tenant suppression list
            Route::get('/email/suppressions',         [TenantControllers\\SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('/email/suppressions',        [TenantControllers\\SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('/email/suppressions/{id}', [TenantControllers\\SuppressionController::class, 'destroy'])->name('suppressions.destroy');"""


# Nav — add right after the existing 'Email' nav entry
OLD_NAV = """    [
      'route'  => 'tenant.emails.index',
      'label'  => 'Email',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 4l5.5 4 5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],"""

NEW_NAV = """    [
      'route'  => 'tenant.emails.index',
      'label'  => 'Email',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="3" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.2"/><path d="M1.5 4l5.5 4 5.5-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],
    // MARKER-PATCH-147 — tenant-facing suppression list
    [
      'route'  => 'tenant.suppressions.index',
      'label'  => 'Suppressions',
      'icon'   => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M3.5 3.5l7 7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>',
      'group'  => 'manage',
    ],"""


NEW_FILES = {
    'app/Http/Controllers/Tenant/SuppressionController.php':   CONTROLLER,
    'resources/views/tenant/email/suppressions.blade.php':     VIEW,
}

EDITS = [
    ('routes/web.php',                                     OLD_ROUTES, NEW_ROUTES, 'routes: suppression endpoints',     'MARKER-PATCH-147 — Tenant suppression list'),
    ('resources/views/layouts/tenant/_nav-items.blade.php', OLD_NAV,    NEW_NAV,    'nav: suppressions entry',           'MARKER-PATCH-147 — tenant-facing suppression list'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-147 [{mode}] target={root} ===\n')

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

    if not a.apply:
        print('\n(dry-run — no files written.)')


if __name__ == '__main__':
    main()
