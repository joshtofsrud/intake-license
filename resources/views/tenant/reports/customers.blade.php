@extends('layouts.tenant.app')
@section('title', 'Reports · Customers')

@push('styles')
<style>
  /* Reuses the same rep-* tokens as the main reports page, plus a few additions for this tab. */
  .rep-h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 4px; }
  .rep-sub { color: var(--ia-text-3, #888); font-size: 13.5px; margin-bottom: 24px; }

  /* rep-toggle: matches the component on the Operations page so the
     Operations | Customers subnav looks identical on both tabs. */
  .rep-toggle { display: inline-flex; gap: 4px; background: rgba(255,255,255,0.02); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 8px; padding: 3px; }
  .rep-toggle a { padding: 7px 14px; font-size: 12.5px; font-weight: 600; color: var(--ia-text-3, #888); text-decoration: none; border-radius: 5px; transition: all 0.12s; }
  .rep-toggle a:hover { color: var(--ia-text, #f0f0f0); }
  .rep-toggle a.active { background: #BEF264; color: #0a0a0a; }

  .rep-note { font-size: 11.5px; color: #5fa8dc; background: rgba(95,168,220,0.07); border-left: 2px solid #5fa8dc; padding: 8px 12px; border-radius: 0 6px 6px 0; margin-bottom: 20px; }

  .rep-zone { background: var(--ia-surface, #131313); border: 1px solid var(--ia-border, #1f1f1f); border-radius: 14px; padding: 22px; margin-bottom: 18px; }
  .rep-zone-head { margin-bottom: 18px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
  .rep-zone-title { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; }
  .rep-zone-sub { font-size: 12px; color: var(--ia-text-3, #888); font-weight: 500; margin-top: 2px; }

  .rep-stat-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border-top: 0.5px solid var(--ia-border, #1f1f1f); border-bottom: 0.5px solid var(--ia-border, #1f1f1f); margin: 14px 0; }
  @media (max-width: 700px) { .rep-stat-strip { grid-template-columns: 1fr; } }
  .rep-stat-cell { padding: 16px 18px; border-right: 0.5px solid var(--ia-border, #1f1f1f); }
  .rep-stat-cell:last-child { border-right: none; }
  .rep-stat-cell .lbl { font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ia-text-3, #888); font-weight: 700; margin-bottom: 8px; }
  .rep-stat-cell .val { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; line-height: 1; font-feature-settings: 'tnum'; }
  .rep-stat-cell.feat .val { color: #BEF264; }
  .rep-stat-cell.warn .val { color: #F59E0B; }
  .rep-stat-cell .meta { font-size: 11px; color: var(--ia-text-3, #888); margin-top: 6px; }

  table.rep-tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 14px; }
  table.rep-tbl th { text-align: left; padding: 10px 12px; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ia-text-3, #888); font-weight: 700; border-bottom: 1px solid var(--ia-border, #1f1f1f); }
  table.rep-tbl th.right { text-align: right; }
  table.rep-tbl td { padding: 11px 12px; border-bottom: 1px solid var(--ia-border, #1f1f1f); vertical-align: top; }
  table.rep-tbl td.right { text-align: right; font-feature-settings: 'tnum'; font-weight: 600; }
  table.rep-tbl tr:last-child td { border-bottom: none; }
  table.rep-tbl tr:hover td { background: rgba(255,255,255,0.02); }
  .rep-cell-name { color: var(--ia-text, #f0f0f0); font-weight: 600; }
  .rep-cell-meta { color: var(--ia-text-3, #888); font-size: 11px; margin-top: 2px; }
  .rep-cell-meta a { color: var(--ia-text-3, #888); text-decoration: none; }
  .rep-cell-meta a:hover { color: var(--ia-text, #f0f0f0); }

  .rep-empty { padding: 28px 18px; text-align: center; color: var(--ia-text-3, #888); font-size: 13px; }

  .rep-pill { display: inline-flex; align-items: center; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.04em; }
  .rep-pill.warn { background: rgba(245,158,11,0.12); color: #F59E0B; }
  .rep-pill.err  { background: rgba(239,68,68,0.12); color: #f87171; }
  .rep-pill.ltv  { background: rgba(190,242,100,0.12); color: #BEF264; }

  .rep-list-foot { padding: 10px 12px; font-size: 11px; color: var(--ia-text-3, #888); font-style: italic; border-top: 0.5px dashed var(--ia-border-2, #2a2a2a); margin-top: 4px; }
</style>
@endpush

@section('content')
<div style="padding: 32px 40px;">

  <h1 class="rep-h1">Reports</h1>
  <p class="rep-sub">Customer health, lapse, and value — across all time.</p>

  <nav class="rep-toggle" style="margin-bottom: 18px;">
    <a href="{{ route('tenant.reports.index', ['subdomain' => tenant()->subdomain]) }}">Operations</a>
    <a href="{{ route('tenant.reports.customers', ['subdomain' => tenant()->subdomain]) }}" class="active">Customers</a>
  </nav>

  <div class="rep-note">
    These panels are <strong>not date-filtered</strong> — they answer whole-database questions ("who's never given us a phone number," "who hasn't come back"). The date range used elsewhere in Reports does not apply here.
  </div>

  {{-- ============ Missing contact info ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">📵 Missing contact info</div>
        <div class="rep-zone-sub">Customers you can't reach by phone — a fix-it list.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell {{ $missing['total_missing'] > 0 ? 'warn' : '' }}">
        <div class="lbl">Missing phone</div>
        <div class="val">{{ number_format($missing['total_missing']) }}</div>
        <div class="meta">{{ $missing['percent_missing'] }}% of all customers</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Total customers</div>
        <div class="val">{{ number_format($missing['total_customers']) }}</div>
        <div class="meta">in your database</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Reachable by email</div>
        <div class="val">{{ number_format($missing['total_customers']) }}</div>
        <div class="meta">100% — email is required</div>
      </div>
    </div>

    @if(empty($missing['list']))
      <div class="rep-empty">No customers are missing contact info. Nice.</div>
    @else
      <table class="rep-tbl">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Email</th>
            <th class="right">Added</th>
          </tr>
        </thead>
        <tbody>
          @foreach($missing['list'] as $c)
            <tr>
              <td>
                <div class="rep-cell-name">{{ $c['name'] ?: '(no name)' }}</div>
                <div class="rep-cell-meta"><span class="rep-pill warn">No phone</span></div>
              </td>
              <td>{{ $c['email'] }}</td>
              <td class="right">{{ \Carbon\Carbon::parse($c['added_at'])->format('M j, Y') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      @if($missing['total_missing'] > $missing['list_limit'])
        <div class="rep-list-foot">Showing first {{ $missing['list_limit'] }} of {{ number_format($missing['total_missing']) }}.</div>
      @endif
    @endif
  </div>

  {{-- ============ Lapsed customers ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">⏰ Lapsed customers</div>
        <div class="rep-zone-sub">Used to come in, haven't lately — a win-back list. Lapsed = no appointment in {{ $lapsed['lapsed_days'] }} days. At-risk = {{ $lapsed['at_risk_days'] }}–{{ $lapsed['lapsed_days'] }} days.</div>
      </div>
    </div>

    <div class="rep-stat-strip">
      <div class="rep-stat-cell {{ $lapsed['lapsed_count'] > 0 ? 'warn' : '' }}">
        <div class="lbl">Lapsed</div>
        <div class="val">{{ number_format($lapsed['lapsed_count']) }}</div>
        <div class="meta">no visit in {{ $lapsed['lapsed_days'] }}+ days</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">At-risk</div>
        <div class="val">{{ number_format($lapsed['at_risk_count']) }}</div>
        <div class="meta">{{ $lapsed['at_risk_days'] }}–{{ $lapsed['lapsed_days'] }} days since last visit</div>
      </div>
      <div class="rep-stat-cell">
        <div class="lbl">Action</div>
        <div class="val" style="font-size:14px;color:var(--ia-text,#f0f0f0);font-weight:600;line-height:1.4;padding-top:4px;">Win-back campaign →</div>
        <div class="meta">campaigns module (coming)</div>
      </div>
    </div>

    @if(empty($lapsed['list']))
      <div class="rep-empty">No lapsed customers. Either everyone's a regular or you're just getting started.</div>
    @else
      <table class="rep-tbl">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Contact</th>
            <th class="right">Last visit</th>
            <th class="right">Days ago</th>
          </tr>
        </thead>
        <tbody>
          @foreach($lapsed['list'] as $c)
            <tr>
              <td>
                <div class="rep-cell-name">{{ $c['name'] ?: '(no name)' }}</div>
                <div class="rep-cell-meta">since {{ \Carbon\Carbon::parse($c['last_visit'])->format('M Y') }}</div>
              </td>
              <td>
                <div class="rep-cell-meta">{{ $c['email'] }}</div>
                @if($c['phone'])<div class="rep-cell-meta">{{ $c['phone'] }}</div>@endif
              </td>
              <td class="right">{{ \Carbon\Carbon::parse($c['last_visit'])->format('M j, Y') }}</td>
              <td class="right">{{ number_format($c['days_since']) }}d</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      @if($lapsed['lapsed_count'] > $lapsed['list_limit'])
        <div class="rep-list-foot">Showing the {{ $lapsed['list_limit'] }} longest-lapsed of {{ number_format($lapsed['lapsed_count']) }}.</div>
      @endif
    @endif
  </div>

  {{-- ============ Highest LTV ============ --}}
  <div class="rep-zone">
    <div class="rep-zone-head">
      <div>
        <div class="rep-zone-title">💎 Highest lifetime value</div>
        <div class="rep-zone-sub">Your most valuable customers — service revenue + retail sales, refunds netted. Snapshotted from the rows, not live-recomputed.</div>
      </div>
    </div>

    @if(empty($topLtv['list']))
      <div class="rep-empty">No paid revenue yet. Once your first sales close, the leaderboard fills in.</div>
    @else
      <table class="rep-tbl">
        <thead>
          <tr>
            <th>Customer</th>
            <th class="right">Lifetime value</th>
            <th class="right">Visits</th>
            <th class="right">Since</th>
          </tr>
        </thead>
        <tbody>
          @foreach($topLtv['list'] as $i => $c)
            <tr>
              <td>
                <div class="rep-cell-name">
                  @if($i < 3)<span class="rep-pill ltv">#{{ $i+1 }}</span> @endif
                  {{ $c['name'] ?: '(no name)' }}
                </div>
                <div class="rep-cell-meta">{{ $c['email'] }}@if($c['phone']) · {{ $c['phone'] }}@endif</div>
              </td>
              <td class="right" style="color:#BEF264;font-size:14px;">${{ number_format($c['ltv_cents'] / 100, 2) }}</td>
              <td class="right">{{ number_format($c['visits']) }}</td>
              <td class="right">{{ \Carbon\Carbon::parse($c['customer_since'])->format('M Y') }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="rep-list-foot">
        Showing top {{ $topLtv['list_limit'] }}. Total lifetime value across these customers: ${{ number_format($topLtv['total_ltv'] / 100, 2) }}.
      </div>
    @endif
  </div>

</div>
@endsection
