@extends('layouts.tenant.app')

{{-- Reports → Daily ops → End of day. --}}

@section('title', 'Reports · End of day')

@push('styles')
@include('tenant.reports._tab_styles')
<link rel="stylesheet" href="{{ asset('css/tenant/report-builder.css') }}?v={{ filemtime(public_path('css/tenant/report-builder.css')) }}">
@endpush

@section('content')
@php
  $money = fn ($c) => ($c < 0 ? '−' : '') . '$' . number_format(abs($c) / 100, 2);
  $closed = (bool) ($drawer?->isClosed());
  $float   = $drawer->opening_float_cents ?? 0;
  $paidOut = $drawer->paid_out_cents ?? 0;
  $expected = $closed ? $drawer->expected_cents : ($float + $n['cash_collected'] - $n['cash_refunds'] - $paidOut);
  $counted = $drawer->counted_cents ?? null;
  $dayUrl = fn ($d) => route('tenant.reports.daily', ['day' => $d->toDateString()]);
@endphp
<div class="ia-section ia-section--wide" id="eod-app">
@include('tenant.reports._daily_head', ['dailyActive' => 'eod'])

<div class="rb-bar">
  <a class="ia-btn ia-btn--sm" href="{{ $dayUrl($day->copy()->subDay()) }}" aria-label="Previous day">‹</a>
  <strong style="font-size:15px;font-weight:600;padding:0 4px">{{ $day->format('l, M j, Y') }}</strong>
  <a class="ia-btn ia-btn--sm" href="{{ $dayUrl($day->copy()->addDay()) }}" aria-label="Next day">›</a>
  @unless($isToday)<a class="ia-btn ia-btn--sm" href="{{ route('tenant.reports.daily') }}">Today</a>@endunless
  <span class="sp"></span>
  <a class="ia-btn ia-btn--sm" href="{{ route('tenant.reports.daily.print', ['day' => $day->toDateString()]) }}" target="_blank">Print</a>
  @unless($closed)
    <button class="ia-btn ia-btn--sm ia-btn--primary" type="button" id="eod-close-open">Close the day</button>
  @endunless
</div>

@if($closed)
  <div class="rb-note rb-good" style="margin-bottom:20px">
    <div><b>Day closed {{ tlocal($drawer->closed_at, 'M j g:ia') }}.</b> The numbers below are the locked snapshot. Over/short: <b>{{ $money($drawer->over_short_cents) }}</b></div>
    <form method="POST" action="{{ route('tenant.reports.daily.reopen', ['day' => $day->toDateString()]) }}" class="rb-inline" style="margin-left:auto;flex:none">
      @csrf
      <input class="rb-inp" style="width:220px" type="text" name="reason" placeholder="Reason to reopen" maxlength="200" minlength="3" required aria-label="Reason to reopen">
      <button class="ia-btn ia-btn--sm" type="submit">Reopen day</button>
    </form>
  </div>
@endif

<div class="rb-strip">
  <div class="rb-fig"><div class="l">Gross sales</div><div class="v">{{ $money($n['gross']) }}</div><div class="d">{{ $n['sale_count'] }} sale{{ $n['sale_count'] === 1 ? '' : 's' }}</div></div>
  <div class="rb-fig"><div class="l">Collected</div><div class="v">{{ $money($n['collected']) }}</div><div class="d">across all methods</div></div>
  <div class="rb-fig"><div class="l">Refunds</div><div class="v">{{ $n['refunds'] > 0 ? '−' : '' }}{{ $money($n['refunds']) }}</div><div class="d {{ $n['refunds'] > 0 ? 'warn' : '' }}">&nbsp;</div></div>
  <div class="rb-fig"><div class="l">Tax collected</div><div class="v">{{ $money($n['tax']) }}</div><div class="d">&nbsp;</div></div>
  <div class="rb-fig"><div class="l">Deposits taken</div><div class="v">{{ $money($n['deposits']) }}</div><div class="d">&nbsp;</div></div>
  <div class="rb-fig"><div class="l">Tips</div><div class="v">{{ $money($n['tips']) }}</div><div class="d">&nbsp;</div></div>
</div>

<div class="rb-two">
  {{-- Cash drawer --}}
  <div class="rb-sec">
    <div class="rb-h"><h2>Cash drawer</h2>@if($closed)<span class="rb-hint">locked</span>@endif</div>
    @if($closed)
      <div class="rb-tbl"><table class="ia-table rx-tbl"><tbody>
        <tr><td>Opening float</td><td class="num">{{ $money($float) }}</td></tr>
        <tr><td>+ Cash collected</td><td class="num">{{ $money($n['cash_collected']) }}</td></tr>
        <tr><td>− Cash refunds</td><td class="num">{{ $money($n['cash_refunds']) }}</td></tr>
        <tr><td>− Paid out @if($drawer->paid_out_note)<span class="rb-hint">({{ $drawer->paid_out_note }})</span>@endif</td><td class="num">{{ $money($paidOut) }}</td></tr>
        <tr class="total"><td>Expected</td><td class="num">{{ $money($expected) }}</td></tr>
        <tr class="total"><td>Counted</td><td class="num">{{ $money($counted) }}</td></tr>
        <tr class="total"><td>Over / short</td><td class="num"><span class="rb-pill {{ $drawer->over_short_cents === 0 ? 'good' : 'bad' }}">{{ $money($drawer->over_short_cents) }}{{ $drawer->over_short_cents === 0 ? ' ✓' : '' }}</span></td></tr>
      </tbody></table></div>
    @else
      <form method="POST" action="{{ route('tenant.reports.daily.drawer', ['day' => $day->toDateString()]) }}">
        @csrf
        <div class="rb-tbl"><table class="ia-table rx-tbl"><tbody>
          <tr><td><label for="eod-float">Opening float</label></td><td class="num"><input class="rb-inp num" id="eod-float" type="number" step="0.01" min="0" name="opening_float" value="{{ number_format($float / 100, 2, '.', '') }}"></td></tr>
          <tr><td>+ Cash collected</td><td class="num">{{ $money($n['cash_collected']) }}</td></tr>
          <tr><td>− Cash refunds</td><td class="num">{{ $money($n['cash_refunds']) }}</td></tr>
          <tr><td><label for="eod-paid">− Paid out</label></td><td class="num"><input class="rb-inp num" id="eod-paid" type="number" step="0.01" min="0" name="paid_out" value="{{ number_format($paidOut / 100, 2, '.', '') }}"></td></tr>
          <tr><td class="sub"><label for="eod-note">Paid-out note</label></td><td class="num"><input class="rb-inp" style="width:190px" id="eod-note" type="text" name="paid_out_note" maxlength="200" value="{{ $drawer->paid_out_note ?? '' }}" placeholder="What for"></td></tr>
          <tr class="total"><td>Expected</td><td class="num">{{ $money($expected) }}</td></tr>
          <tr class="total"><td><label for="eod-count">Counted</label></td><td class="num"><input class="rb-inp num" id="eod-count" type="number" step="0.01" min="0" name="counted" value="{{ $counted !== null ? number_format($counted / 100, 2, '.', '') : '' }}" placeholder="Count it"></td></tr>
          @if($counted !== null)
            <tr class="total"><td>Over / short</td><td class="num"><span class="rb-pill {{ ($counted - $expected) === 0 ? 'good' : 'bad' }}">{{ $money($counted - $expected) }}{{ ($counted - $expected) === 0 ? ' ✓' : '' }}</span></td></tr>
          @endif
        </tbody></table></div>
        <div style="margin-top:12px"><button class="ia-btn ia-btn--sm ia-btn--primary" type="submit">Save drawer</button></div>
      </form>
    @endif
  </div>

  {{-- Payments by method --}}
  <div class="rb-sec">
    <div class="rb-h"><h2>Payments by method</h2><span class="rb-hint">fees arrive with payout reconciliation</span></div>
    <div class="rb-tbl"><table class="ia-table rx-tbl">
      <thead><tr><th>Method</th><th class="num">Count</th><th class="num">Collected</th><th class="num">Refunded</th></tr></thead>
      <tbody>
        @forelse($n['by_method'] as $bm)
          <tr><td>{{ $bm['label'] }}</td><td class="num">{{ $bm['count'] }}</td><td class="num">{{ $money($bm['collected']) }}</td>
            <td class="num">@if($bm['refunded'] > 0)<span style="color:var(--rb-bad)">−{{ $money($bm['refunded']) }}</span>@else<span class="rb-hint">—</span>@endif</td></tr>
        @empty
          <tr><td colspan="4" class="rb-hint">No payments recorded this day.</td></tr>
        @endforelse
        <tr class="total"><td>Total</td><td></td><td class="num">{{ $money($n['collected']) }}</td><td class="num">{{ $n['refunds'] > 0 ? '−' . $money($n['refunds']) : '—' }}</td></tr>
      </tbody>
    </table></div>
  </div>
</div>

{{-- Needs attention --}}
<div class="rb-sec">
  <div class="rb-h"><h2>Needs attention before close</h2><span class="rb-hint">{{ count($attention) }} open</span></div>
  @if(count($attention))
    <div class="rb-tbl"><table class="ia-table rx-tbl">
      <thead><tr><th>Item</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($attention as $a)
          <tr>
            <td>{{ $a['label'] }}</td>
            <td class="num">{{ $money($a['amount']) }}</td>
            <td><span class="rb-pill {{ ['unpaid' => 'bad', 'pending' => 'warn', 'draft' => 'info'][$a['tag']] ?? 'mute' }}">{{ ucfirst($a['tag']) }}</span></td>
            <td class="num"><a class="ia-btn ia-btn--sm" href="{{ $a['url'] }}">Open</a></td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @else
    <div class="rb-note rb-good"><div>Nothing outstanding. Clean day.</div></div>
  @endif
</div>

<div class="rb-legend">
  <b>How this behaves.</b>
  <div class="row"><span>Expected cash</span><span>Opening float + cash collected − cash refunds − paid out.</span></div>
  <div class="row"><span>Over / short</span><span>Counted cash minus expected cash. Zero means the drawer balances.</span></div>
  <div class="row"><span>Closing the day</span><span>Locks the numbers and the drawer count as a snapshot. Reopening needs a reason. Nothing else about your sales changes.</span></div>
  <div class="row"><span>Needs attention</span><span>Drafts, pending payments and unpaid jobs from this day. They don't block closing, but they aren't in Collected until paid.</span></div>
</div>
</div>

@unless($closed)
<div class="rx-scr" id="eod-scr"></div>
<div class="rx-mod" id="eod-close" role="dialog" aria-modal="true" aria-labelledby="eod-close-t">
  <form method="POST" action="{{ route('tenant.reports.daily.close', ['day' => $day->toDateString()]) }}">
    @csrf
    <div class="h"><h3 id="eod-close-t">Close {{ $day->format('M j') }}?</h3><button type="button" class="rb-x" data-eod-x aria-label="Close">×</button></div>
    <div class="b">The numbers lock and the drawer count becomes final. You can reopen the day later with a reason.@if(count($attention)) <b>{{ count($attention) }} item{{ count($attention) === 1 ? '' : 's' }} still need attention.</b>@endif</div>
    <div class="f"><button type="button" class="ia-btn" data-eod-x>Cancel</button><button type="submit" class="ia-btn ia-btn--primary">Close the day</button></div>
  </form>
</div>
<script>
(function () {
  var m = document.getElementById('eod-close'), s = document.getElementById('eod-scr'), o = document.getElementById('eod-close-open');
  if (!m || !s || !o) return;
  function show(on) { m.classList.toggle('on', on); s.classList.toggle('on', on); }
  o.addEventListener('click', function () { show(true); });
  s.addEventListener('click', function () { show(false); });
  m.querySelectorAll('[data-eod-x]').forEach(function (b) { b.addEventListener('click', function () { show(false); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') show(false); });
})();
</script>
@endunless
@endsection
