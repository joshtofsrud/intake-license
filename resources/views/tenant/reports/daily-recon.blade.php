@extends('layouts.tenant.app')

{{-- MARKER-PATCH-635 — Reports → Daily ops → Reconciliation. --}}

@section('title', 'Reports · Reconciliation')

@push('styles')
@include('tenant.reports._tab_styles')
<style>
.rc-sub { display:flex; gap:18px; border-bottom:.5px solid var(--ia-border); margin-bottom:16px; }
.rc-sub a { padding:10px 2px; font-size:12.5px; color:var(--ia-text-muted); border-bottom:2px solid transparent; margin-bottom:-.5px; text-decoration:none; }
.rc-sub a.on { color:var(--ia-text); border-bottom-color:var(--ia-accent); font-weight:600; }
.rc-bar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.rc-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); text-decoration:none; }
.rc-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.rc-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); margin-bottom:14px; }
.rc-ch { padding:11px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; display:flex; justify-content:space-between; align-items:center; }
.rc-ch .m { font-size:10.5px; color:var(--ia-text-muted); font-weight:500; }
.rc-row { display:flex; align-items:center; gap:10px; padding:9px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.rc-row:last-child { border:none; }
.rc-row .n { font-variant-numeric:tabular-nums; }
.rc-tag { font-size:9.5px; letter-spacing:.04em; text-transform:uppercase; border-radius:999px; padding:2px 8px; font-weight:700; }
.rc-tag.ok { background:rgba(190,242,100,.12); color:var(--ia-accent); }
.rc-tag.bad { background:rgba(245,158,11,.12); color:#F59E0B; }
.rc-mono { font-family:ui-monospace,Menlo,monospace; font-size:11px; color:var(--ia-text-muted); }
</style>
@endpush

@section('content')
<div style="max-width:1000px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:2px">Reports</h1>
  <div style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:14px">How your business is performing.</div>
  @include('tenant.reports._tab_subnav', ['active' => 'daily'])

  <div class="rc-sub">
    <a href="{{ route('tenant.reports.daily') }}">End of day</a>
    <a href="{{ route('tenant.reports.daily.recon') }}" class="on">Reconciliation</a>
    <a href="{{ route('tenant.reports.daily.exports') }}">Bookkeeping exports</a>
  </div>

  @php $money = fn ($c) => '$' . number_format($c / 100, 2); @endphp

  <div class="rc-bar">
    <a class="rc-btn" href="{{ route('tenant.reports.daily.recon', ['week' => $week->copy()->subWeek()->toDateString()]) }}">‹</a>
    <span style="font-weight:700;font-size:14px">Week of {{ $week->format('M j') }} – {{ $week->copy()->addDays(6)->format('M j, Y') }}</span>
    <a class="rc-btn" href="{{ route('tenant.reports.daily.recon', ['week' => $week->copy()->addWeek()->toDateString()]) }}">›</a>
    <span style="margin-left:auto;display:flex;gap:8px;align-items:center">
      @if($lastFetch)<span style="font-size:11px;color:var(--ia-text-muted)">fetched {{ tlocal($lastFetch, 'M j g:ia') }}</span>@endif
      @if($available)
        <form method="POST" action="{{ route('tenant.reports.daily.recon.refresh', ['week' => $week->toDateString()]) }}">
          @csrf<button class="rc-btn p" type="submit">Fetch payouts from Stripe</button>
        </form>
      @endif
    </span>
  </div>

  @unless($available)
    <div class="rc-card"><div class="rc-row" style="color:var(--ia-text-muted)">Stripe Direct isn't connected — payout reconciliation needs your Stripe keys (Settings → Payments).</div></div>
  @endunless

  <div class="rc-card">
    <div class="rc-ch">Stripe payouts <span class="m">every charge in a payout matched against the register ledger</span></div>
    <div class="rc-row" style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim,rgba(255,255,255,.42));font-weight:700">
      <span style="width:150px">Payout</span><span style="width:90px">Arrived</span>
      <span class="n" style="width:90px;text-align:right">Gross</span><span class="n" style="width:80px;text-align:right">Fees</span>
      <span class="n" style="width:100px;text-align:right">Net payout</span><span style="flex:1;text-align:right">Status</span>
    </div>
    @forelse($payouts as $po)
      <div class="rc-row">
        <span class="rc-mono" style="width:150px">{{ \Illuminate\Support\Str::limit($po->payout_id, 16) }}</span>
        <span style="width:90px">{{ $po->arrived_on->format('D, M j') }}</span>
        <span class="n" style="width:90px;text-align:right">{{ $money($po->gross_cents) }}</span>
        <span class="n" style="width:80px;text-align:right">−{{ $money($po->fee_cents) }}</span>
        <span class="n" style="width:100px;text-align:right;font-weight:700">{{ $money($po->net_cents) }}</span>
        <span style="flex:1;text-align:right">
          @if($po->unmatched_count === 0)<span class="rc-tag ok">matched ✓</span>
          @else<span class="rc-tag bad">{{ $po->unmatched_count }} unmatched</span>@endif
        </span>
      </div>
    @empty
      <div class="rc-row" style="color:var(--ia-text-muted)">No payouts cached for this week — hit "Fetch payouts from Stripe".</div>
    @endforelse
  </div>

  @if(count($unmatched))
    <div class="rc-card">
      <div class="rc-ch">Unmatched charges <span class="m">in a payout, but no ledger payment carries the PaymentIntent</span></div>
      @foreach($unmatched as $u)
        <div class="rc-row">
          <span class="rc-mono" style="width:210px">{{ $u['charge'] }}</span>
          <span style="width:130px">{{ $u['created'] ? tlocal(\Carbon\Carbon::createFromTimestamp($u['created'], 'UTC'), 'M j, g:ia') : '' }}</span>
          <span class="n" style="width:90px;text-align:right">{{ $money($u['amount']) }}</span>
          <span style="flex:1;color:var(--ia-text-muted);font-size:11.5px;text-align:right">likely hand-keyed in Stripe outside the register — record it manually if real</span>
        </div>
      @endforeach
    </div>
  @endif

  <div class="rc-card">
    <div class="rc-ch">Cash — week at a glance <span class="m">from closed drawer days</span></div>
    @forelse($cashWeek as $d)
      <div class="rc-row">
        <span style="width:120px">{{ $d->day->format('D M j') }}</span>
        <span class="n" style="width:110px;text-align:right">expected {{ $d->expected_cents !== null ? $money($d->expected_cents) : '—' }}</span>
        <span class="n" style="width:110px;text-align:right">counted {{ $d->counted_cents !== null ? $money($d->counted_cents) : '—' }}</span>
        <span style="flex:1;text-align:right">
          @if($d->over_short_cents === null)<span class="rc-tag bad">not closed</span>
          @elseif($d->over_short_cents === 0)<span class="rc-tag ok">{{ $money(0) }} ✓</span>
          @else<span class="rc-tag bad">{{ $money($d->over_short_cents) }}</span>@endif
        </span>
      </div>
    @empty
      <div class="rc-row" style="color:var(--ia-text-muted)">No drawer days recorded this week yet.</div>
    @endforelse
  </div>
</div>
@endsection

