@extends('layouts.tenant.app')

{{-- MARKER-PATCH-634 — Reports → Daily ops → Bookkeeping exports. --}}

@section('title', 'Reports · Exports')

@push('styles')
@include('tenant.reports._tab_styles')
<style>
.ex-sub { display:flex; gap:18px; border-bottom:.5px solid var(--ia-border); margin-bottom:16px; }
.ex-sub a { padding:10px 2px; font-size:12.5px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.ex-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.ex-range { display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap; font-size:12.5px; }
.ex-inp { background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text); border-radius:7px; padding:7px 10px; font-size:12.5px; }
.ex-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:840px; }
@media(max-width:760px){ .ex-cards { grid-template-columns:1fr; } }
.ex-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); padding:15px 17px; display:flex; flex-direction:column; gap:8px; }
.ex-card .t { font-weight:700; font-size:13.5px; }
.ex-card .d { font-size:12px; color:var(--ia-text-muted); line-height:1.55; flex:1; }
.ex-card .f { font-size:10px; color:var(--ia-text-dim,rgba(255,255,255,.4)); text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
.ex-btn { align-self:flex-start; padding:8px 15px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:none; background:var(--ia-accent); color:var(--ia-accent-text); text-decoration:none; }
.ex-card.soon { opacity:.55; }
.ex-card.soon .ex-btn { background:transparent; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); color:var(--ia-text-muted); cursor:default; }
</style>
@endpush

@section('content')
<div style="max-width:1000px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:2px">Reports</h1>
  <div style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:14px">How your business is performing.</div>
  @include('tenant.reports._tab_subnav', ['active' => 'daily'])

  <div class="ex-sub">
    <a href="{{ route('tenant.reports.daily') }}">End of day</a>
    <a href="{{ route('tenant.reports.daily.exports') }}" class="on">Bookkeeping exports</a>
  </div>

  <form method="GET" action="{{ route('tenant.reports.daily.exports') }}" class="ex-range">
    <span style="color:var(--ia-text-muted)">Range</span>
    <input class="ex-inp" type="date" name="from" value="{{ tlocal_carbon($from)->toDateString() }}">
    <span style="color:var(--ia-text-muted)">to</span>
    <input class="ex-inp" type="date" name="to" value="{{ tlocal_carbon($to)->toDateString() }}">
    <button class="ex-btn" type="submit" style="background:transparent;border:.5px solid var(--ia-border-2,rgba(255,255,255,.2));color:var(--ia-text)">Set range</button>
    <span style="color:var(--ia-text-dim,rgba(255,255,255,.4));font-size:11px">defaults to this month · dates are your local days</span>
  </form>

  @php $qs = '?from=' . tlocal_carbon($from)->toDateString() . '&to=' . tlocal_carbon($to)->toDateString(); @endphp

  <div class="ex-cards">
    <div class="ex-card">
      <div class="t">QuickBooks journal</div>
      <div class="d">One balanced journal entry per day: each payment method into its deposit account, sales income, tax payable, tips payable. Import via QBO journal import. Account names follow your payment-method mapping once set.</div>
      <div class="f">CSV · QBO journal</div>
      <a class="ex-btn" href="{{ route('tenant.reports.daily.export.qb') . $qs }}">Generate export</a>
    </div>

    <div class="ex-card">
      <div class="t">Sales &amp; payments detail</div>
      <div class="d">Every payment with its kind, method, amount, sale totals, tax, tip, customer, and reference — the everything file for your accountant, Wave, or Excel.</div>
      <div class="f">CSV · generic</div>
      <a class="ex-btn" href="{{ route('tenant.reports.daily.export.detail') . $qs }}">Generate export</a>
    </div>

    <div class="ex-card">
      <div class="t">Tax summary</div>
      <div class="d">Collected tax by day with gross and taxable amounts — shaped for the WA excise return, works for any jurisdiction.</div>
      <div class="f">CSV · tax</div>
      <a class="ex-btn" href="{{ route('tenant.reports.daily.export.tax') . $qs }}">Generate export</a>
    </div>

    <div class="ex-card soon">
      <div class="t">Xero bank statement</div>
      <div class="d">Payout-level lines matching your bank feed for one-click reconciliation in Xero. Arrives with Stripe payout reconciliation (next stage) — it's built from payout data.</div>
      <div class="f">CSV · Xero statement</div>
      <span class="ex-btn">Coming with reconciliation</span>
    </div>
  </div>
</div>
@endsection

