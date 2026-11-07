@extends('layouts.tenant.app')

{{-- Reports → Daily ops → Reconciliation. --}}

@section('title', 'Reports · Reconciliation')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
@php $money = fn ($c) => ($c < 0 ? '−' : '') . '$' . number_format(abs($c) / 100, 2); @endphp
<div class="ia-section ia-section--wide">
@include('tenant.reports._daily_head', ['dailyActive' => 'recon'])

<div class="rb-bar">
  <a class="ia-btn ia-btn--sm" href="{{ route('tenant.reports.daily.recon', ['week' => $week->copy()->subWeek()->toDateString()]) }}" aria-label="Previous week">‹</a>
  <strong style="font-size:15px;font-weight:600;padding:0 4px">Week of {{ $week->format('M j') }} – {{ $week->copy()->addDays(6)->format('M j, Y') }}</strong>
  <a class="ia-btn ia-btn--sm" href="{{ route('tenant.reports.daily.recon', ['week' => $week->copy()->addWeek()->toDateString()]) }}" aria-label="Next week">›</a>
  <span class="sp"></span>
  @if($lastFetch)<span class="rb-hint">Fetched {{ tlocal($lastFetch, 'M j g:ia') }}</span>@endif
  @if($available)
    <form method="POST" action="{{ route('tenant.reports.daily.recon.refresh', ['week' => $week->toDateString()]) }}">
      @csrf<button class="ia-btn ia-btn--sm ia-btn--primary" type="submit">Fetch payouts from Stripe</button>
    </form>
  @endif
</div>

@unless($available)
  <div class="rb-note rb-stale"><div>Stripe Direct isn't connected. Payout reconciliation needs your Stripe keys (Settings → Payments).</div></div>
@endunless

<div class="rb-sec">
  <div class="rb-h"><h2>Stripe payouts</h2><span class="rb-hint">every charge in a payout matched against the register ledger</span></div>
  <div class="rb-tbl"><table class="ia-table rx-tbl">
    <thead><tr><th>Payout</th><th>Arrived</th><th class="num">Gross</th><th class="num">Fees</th><th class="num">Net payout</th><th class="num">Status</th></tr></thead>
    <tbody>
      @forelse($payouts as $po)
        <tr>
          <td class="rb-sku">{{ \Illuminate\Support\Str::limit($po->payout_id, 16) }}</td>
          <td>{{ $po->arrived_on->format('D, M j') }}</td>
          <td class="num">{{ $money($po->gross_cents) }}</td>
          <td class="num">−{{ $money($po->fee_cents) }}</td>
          <td class="num"><b>{{ $money($po->net_cents) }}</b></td>
          <td class="num">@if($po->unmatched_count === 0)<span class="rb-pill good">Matched ✓</span>@else<span class="rb-pill warn">{{ $po->unmatched_count }} unmatched</span>@endif</td>
        </tr>
      @empty
        <tr><td colspan="6" class="rb-hint">No payouts cached for this week. Use “Fetch payouts from Stripe”.</td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>

@if(count($unmatched))
<div class="rb-sec">
  <div class="rb-h"><h2>Unmatched charges</h2><span class="rb-hint">in a payout, but no ledger payment carries the PaymentIntent</span></div>
  <div class="rb-tbl"><table class="ia-table rx-tbl">
    <thead><tr><th>Charge</th><th>Created</th><th class="num">Amount</th><th>Likely cause</th></tr></thead>
    <tbody>
      @foreach($unmatched as $u)
        <tr>
          <td class="rb-sku">{{ $u['charge'] }}</td>
          <td>{{ $u['created'] ? tlocal(\Carbon\Carbon::createFromTimestamp($u['created'], 'UTC'), 'M j, g:ia') : '' }}</td>
          <td class="num">{{ $money($u['amount']) }}</td>
          <td class="sub">Likely hand-keyed in Stripe outside the register. Record it manually if real.</td>
        </tr>
      @endforeach
    </tbody>
  </table></div>
</div>
@endif

<div class="rb-sec">
  <div class="rb-h"><h2>Cash, week at a glance</h2><span class="rb-hint">from closed drawer days</span></div>
  <div class="rb-tbl"><table class="ia-table rx-tbl">
    <thead><tr><th>Day</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Over / short</th></tr></thead>
    <tbody>
      @forelse($cashWeek as $d)
        <tr>
          <td>{{ $d->day->format('D M j') }}</td>
          <td class="num">{{ $d->expected_cents !== null ? $money($d->expected_cents) : '—' }}</td>
          <td class="num">{{ $d->counted_cents !== null ? $money($d->counted_cents) : '—' }}</td>
          <td class="num">
            @if($d->over_short_cents === null)<span class="rb-pill warn">Not closed</span>
            @elseif($d->over_short_cents === 0)<span class="rb-pill good">{{ $money(0) }} ✓</span>
            @else<span class="rb-pill bad">{{ $money($d->over_short_cents) }}</span>@endif
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="rb-hint">No drawer days recorded this week yet.</td></tr>
      @endforelse
    </tbody>
  </table></div>
</div>

<div class="rb-legend">
  <b>How this behaves.</b>
  <div class="row"><span>Fetching</span><span>Pulls payouts from Stripe into a local cache for the week. It reads only; nothing in Stripe or your sales changes.</span></div>
  <div class="row"><span>Matched</span><span>Every charge in the payout has a register payment carrying the same Stripe reference.</span></div>
  <div class="row"><span>Cash</span><span>Only days that have been closed show expected and counted figures.</span></div>
</div>
</div>
@endsection
