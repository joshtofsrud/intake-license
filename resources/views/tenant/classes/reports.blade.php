@extends('layouts.tenant.app')
@php $pageTitle = 'Classes Reports'; @endphp

@push('styles')
<style>
.cl-subnav{display:flex;gap:2px;margin-bottom:20px;border-bottom:0.5px solid var(--ia-border)}
.cl-subnav-tab{padding:9px 14px;font-size:13px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-0.5px;cursor:pointer;background:none;border-left:none;border-right:none;border-top:none;text-decoration:none;transition:color var(--ia-t),border-color var(--ia-t)}
.cl-subnav-tab:hover{color:var(--ia-text)}
.cl-subnav-tab.is-active{color:var(--ia-text);border-bottom-color:var(--ia-accent);font-weight:500}

/* Headline strip */
.rp-headline { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
.rp-head-card { background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: var(--ia-r-lg); padding: 18px 20px; position: relative; }
.rp-head-card::before { content: ''; position: absolute; left: 0; top: 16px; bottom: 16px; width: 2px; border-radius: 1px; }
.rp-head-card.green::before { background: var(--ia-accent); }
.rp-head-card.amber::before { background: #FAB46A; }
.rp-head-card.red::before   { background: #F47373; }
.rp-head-card.blue::before  { background: #75A8E0; }
.rp-head-num { font-size: 30px; font-weight: 600; letter-spacing: -.02em; margin: 0 0 4px; font-variant-numeric: tabular-nums; }
.rp-head-card.green .rp-head-num { color: var(--ia-accent); }
.rp-head-card.amber .rp-head-num { color: #FAB46A; }
.rp-head-card.red   .rp-head-num { color: #F47373; }
.rp-head-card.blue  .rp-head-num { color: #75A8E0; }
.rp-head-label { font-size: 12.5px; font-weight: 500; margin: 0 0 2px; }
.rp-head-sub { font-size: 11.5px; color: var(--ia-text-muted); margin: 0; }

/* Panel grid */
.rp-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.rp-panel.full { grid-column: span 2; }
.rp-panel { background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: var(--ia-r-lg); padding: 18px 20px 8px; display: flex; flex-direction: column; }
.rp-panel-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; gap: 12px; }
.rp-panel-title-wrap { flex: 1; min-width: 0; }
.rp-panel-title { font-size: 14px; font-weight: 600; margin: 0 0 2px; display: flex; align-items: center; gap: 8px; }
.rp-panel-tag { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; padding: 2px 6px; border-radius: 3px; }
.rp-tag-opportunity { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.rp-tag-risk { background: rgba(244,115,115,.15); color: #F47373; }
.rp-tag-amber { background: rgba(250,180,106,.15); color: #FAB46A; }
.rp-tag-info { background: rgba(117,168,224,.15); color: #75A8E0; }
.rp-panel-sub { font-size: 12px; color: var(--ia-text-muted); margin: 0; }
.rp-panel-actions { display: flex; gap: 6px; flex-shrink: 0; }
.rp-export-btn { font-family: inherit; font-size: 11.5px; padding: 5px 10px; border-radius: var(--ia-r-sm); border: 0.5px solid var(--ia-border); background: transparent; color: var(--ia-text-muted); cursor: pointer; transition: all var(--ia-t); font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
.rp-export-btn:hover { color: var(--ia-text); border-color: var(--ia-border-strong); background: var(--ia-hover); }

/* Rows */
.rp-row-list { margin: 8px -8px 0; padding: 0 8px; flex: 1; display: flex; flex-direction: column; }
.rp-row { display: grid; grid-template-columns: 32px 1fr auto auto; align-items: center; gap: 12px; padding: 10px 8px; border-radius: var(--ia-r-md); transition: background var(--ia-t); cursor: pointer; border-bottom: 0.5px solid var(--ia-border); text-decoration: none; color: inherit; }
.rp-row:last-child { border-bottom: none; }
.rp-row:hover { background: var(--ia-hover); }
.rp-avatar { width: 28px; height: 28px; background: var(--ia-surface-2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: var(--ia-text-muted); border: 0.5px solid var(--ia-border); flex-shrink: 0; }
.rp-avatar.green { background: rgba(190,242,100,.18); color: var(--ia-accent); }
.rp-avatar.amber { background: rgba(250,180,106,.18); color: #FAB46A; }
.rp-avatar.red   { background: rgba(244,115,115,.18); color: #F47373; }
.rp-avatar.blue  { background: rgba(117,168,224,.18); color: #75A8E0; }
.rp-row-main { min-width: 0; }
.rp-row-name { font-size: 13px; font-weight: 500; color: var(--ia-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rp-row-fact { font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rp-row-meta { font-size: 11.5px; color: var(--ia-text-muted); text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

.rp-empty { padding: 28px 8px; text-align: center; color: var(--ia-text-dim); font-size: 12.5px; }

/* Top earning products: tabular */
.rp-tep-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.rp-tep-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: var(--ia-text-dim); font-weight: 600; padding: 8px 12px 8px 0; border-bottom: 0.5px solid var(--ia-border); }
.rp-tep-table th.num { text-align: right; padding-right: 0; }
.rp-tep-table td { padding: 12px 12px 12px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; vertical-align: top; }
.rp-tep-table tr:last-child td { border-bottom: none; }
.rp-tep-table td.num { text-align: right; font-variant-numeric: tabular-nums; padding-right: 0; font-weight: 500; }
.rp-tep-name { font-weight: 500; }
.rp-tep-meta { font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px; }
.rp-tep-bar { height: 3px; background: var(--ia-border); border-radius: 2px; margin-top: 6px; overflow: hidden; }
.rp-tep-bar-fill { height: 100%; background: var(--ia-accent); border-radius: 2px; }
.rp-tep-rank { display: inline-flex; width: 22px; height: 22px; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: var(--ia-text-muted); background: var(--ia-surface-2); border-radius: 50%; margin-right: 8px; vertical-align: middle; }

@media (max-width: 1024px) {
  .rp-panels { grid-template-columns: 1fr; }
  .rp-panel.full { grid-column: span 1; }
  .rp-headline { grid-template-columns: repeat(2, 1fr); }
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Classes Reports</h1>
    <p class="ia-page-subtitle">Member health, churn signals, and conversion targets — last 30 days unless noted.</p>
  </div>
</div>

<nav class="cl-subnav">
  <a href="{{ route('tenant.classes.templates') }}"   class="cl-subnav-tab">Templates</a>
  <a href="{{ route('tenant.classes.sessions') }}"    class="cl-subnav-tab">Schedule</a>
  <a href="{{ route('tenant.classes.memberships') }}" class="cl-subnav-tab">Memberships</a>
  <a href="{{ route('tenant.classes.packs') }}"       class="cl-subnav-tab">Packs</a>
  <a href="{{ route('tenant.classes.reports') }}"     class="cl-subnav-tab is-active">Reports</a>
</nav>

@php
    $sub = $currentTenant->subdomain;
    $arrAtRiskDollars = (int) round($headline['arr_at_risk_cents'] / 100);
    $packCreditsLeft  = $headline['pack_credits_remaining'];

    /** Inline initials helper — used by the full-width panels below. */
    $initials = function ($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        $i = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            if ($p !== '') $i .= strtoupper($p[0]);
        }
        return $i ?: '?';
    };
@endphp

{{-- Headline strip --}}
<div class="rp-headline">
    <div class="rp-head-card green">
        <p class="rp-head-num">{{ number_format($headline['active_members']) }}</p>
        <p class="rp-head-label">Active members</p>
        <p class="rp-head-sub">
            @if($headline['active_members_delta'] > 0)
                +{{ $headline['active_members_delta'] }} this month
            @else
                No new members this month
            @endif
        </p>
    </div>
    <div class="rp-head-card blue">
        <p class="rp-head-num">{{ number_format($headline['active_packs']) }}</p>
        <p class="rp-head-label">Active packs</p>
        <p class="rp-head-sub">{{ number_format($packCreditsLeft) }} credits remaining</p>
    </div>
    <div class="rp-head-card amber">
        <p class="rp-head-num">{{ number_format($headline['dropins_this_month']) }}</p>
        <p class="rp-head-label">Drop-ins this month</p>
        <p class="rp-head-sub">across {{ $headline['dropin_customers_this_month'] }} customers</p>
    </div>
    <div class="rp-head-card red">
        <p class="rp-head-num">{{ number_format($headline['lapsed_recent']) }}</p>
        <p class="rp-head-label">Lapsed last 30 days</p>
        <p class="rp-head-sub">${{ number_format($arrAtRiskDollars) }} ARR at risk</p>
    </div>
</div>

{{-- Panels --}}
<div class="rp-panels">

    {{-- Panel: Drop-in regulars --}}
    @include('tenant.classes._report-panel', [
        'rows'        => $dropInRegulars,
        'title'       => 'Drop-in regulars',
        'tag'         => 'opportunity',
        'tagLabel'    => 'Conversion',
        'subtitle'    => '3+ paid drop-ins in 90 days, no active membership or pack',
        'exportSlug'  => 'drop-in-regulars',
        'emptyText'   => 'No drop-in regulars right now.',
        'sub'         => $sub,
    ])

    {{-- Panel: At-risk members --}}
    @include('tenant.classes._report-panel', [
        'rows'        => $atRiskMembers,
        'title'       => 'At-risk active members',
        'tag'         => 'risk',
        'tagLabel'    => 'Churn signal',
        'subtitle'    => 'Active membership with low usage this period',
        'exportSlug'  => 'at-risk-members',
        'emptyText'   => 'Nobody is at-risk right now. Healthy roster.',
        'sub'         => $sub,
    ])

    {{-- Panel: Used-up packs --}}
    @include('tenant.classes._report-panel', [
        'rows'        => $usedUpPacks,
        'title'       => 'Used-up packs',
        'tag'         => 'opportunity',
        'tagLabel'    => 'Renewal',
        'subtitle'    => 'Packs exhausted in last 60 days',
        'exportSlug'  => 'used-up-packs',
        'emptyText'   => 'No recently exhausted packs.',
        'sub'         => $sub,
    ])

    {{-- Panel: Recently cancelled --}}
    @include('tenant.classes._report-panel', [
        'rows'        => $recentlyCancelled,
        'title'       => 'Recently cancelled',
        'tag'         => 'risk',
        'tagLabel'    => 'Win-back',
        'subtitle'    => 'Memberships cancelled in last 30 days',
        'exportSlug'  => 'recently-cancelled',
        'emptyText'   => 'No recent cancellations. Nice.',
        'sub'         => $sub,
    ])

    {{-- Panel: Lapsed (full width) --}}
    <div class="rp-panel full">
        <div class="rp-panel-head">
            <div class="rp-panel-title-wrap">
                <h2 class="rp-panel-title">Lapsed memberships <span class="rp-panel-tag rp-tag-amber">Re-engage</span></h2>
                <p class="rp-panel-sub">Period ended without rolling over · expired memberships in last 90 days</p>
            </div>
            <div class="rp-panel-actions">
                <a class="rp-export-btn" href="{{ route('tenant.classes.reports.export', ['subdomain' => $sub, 'panel' => 'lapsed-memberships']) }}">Export CSV</a>
            </div>
        </div>
        <div class="rp-row-list">
            @forelse($lapsedMemberships as $row)
                <a class="rp-row" href="{{ route('tenant.customers.show', ['subdomain' => $sub, 'id' => $row['customer_id']]) }}">
                    <div class="rp-avatar {{ $row['severity'] }}">{{ $initials($row['name'] ?? '') }}</div>
                    <div class="rp-row-main">
                        <div class="rp-row-name">{{ $row['name'] }}</div>
                        <div class="rp-row-fact">{{ $row['fact'] }}</div>
                    </div>
                    <div class="rp-row-meta">{{ $row['meta'] }}</div>
                    <span class="rp-export-btn" style="cursor:default">{{ $row['cta'] }}</span>
                </a>
            @empty
                <div class="rp-empty">No lapsed memberships in the last 90 days.</div>
            @endforelse
        </div>
    </div>

    {{-- Panel: Top earning products (full width, tabular) --}}
    <div class="rp-panel full">
        <div class="rp-panel-head">
            <div class="rp-panel-title-wrap">
                <h2 class="rp-panel-title">Top earning products <span class="rp-panel-tag rp-tag-info">Last 30d</span></h2>
                <p class="rp-panel-sub">Revenue and active count by tier</p>
            </div>
        </div>
        @if($topProducts->isEmpty())
            <div class="rp-empty">No products yet. <a href="{{ route('tenant.classes.memberships') }}" style="color: var(--ia-accent)">Create one →</a></div>
        @else
            <table class="rp-tep-table">
                <thead>
                    <tr>
                        <th style="width:40%">Product</th>
                        <th class="num">Active</th>
                        <th class="num">Lifetime sold</th>
                        <th class="num" style="width:25%">Revenue (30d)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topProducts as $i => $row)
                        <tr>
                            <td>
                                <span class="rp-tep-rank">{{ $i + 1 }}</span>
                                <span class="rp-tep-name">{{ $row['name'] }}</span>
                                <div class="rp-tep-meta">{{ $row['meta'] }}</div>
                                <div class="rp-tep-bar"><div class="rp-tep-bar-fill" style="width:{{ $row['revenue_pct'] }}%"></div></div>
                            </td>
                            <td class="num">{{ number_format($row['active']) }}</td>
                            <td class="num">{{ number_format($row['lifetime']) }}</td>
                            <td class="num">${{ number_format($row['revenue_cents'] / 100, 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

@endsection
