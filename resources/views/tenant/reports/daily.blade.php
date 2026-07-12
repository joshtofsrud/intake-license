@extends('layouts.tenant.app')

{{-- MARKER-PATCH-633 — Reports → Daily ops → End of day. --}}

@section('title', 'Reports · End of day')

@push('styles')
@include('tenant.reports._tab_styles')
<style>
.eod-bar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.eod-btn { padding:7px 13px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:.5px solid var(--ia-border-2,rgba(255,255,255,.2)); background:transparent; color:var(--ia-text); text-decoration:none; }
.eod-btn.p { background:var(--ia-accent); color:var(--ia-accent-text); border:none; }
.eod-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:16px; }
.eod-tile { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); padding:13px 15px; }
.eod-tile .k { font-size:9.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--ia-text-dim,rgba(255,255,255,.42)); font-weight:700; }
.eod-tile .v { font-size:19px; font-weight:800; margin-top:4px; font-variant-numeric:tabular-nums; }
.eod-tile .m { font-size:10.5px; color:var(--ia-text-muted); margin-top:2px; }
.eod-cols { display:grid; grid-template-columns:1fr 1.2fr; gap:14px; margin-bottom:16px; }
@media(max-width:860px){ .eod-cols { grid-template-columns:1fr; } }
.eod-card { border:.5px solid var(--ia-border); border-radius:12px; background:var(--ia-surface); }
.eod-ch { padding:11px 15px; border-bottom:.5px solid var(--ia-border); font-weight:700; font-size:13px; display:flex; justify-content:space-between; align-items:center; }
.eod-ch .m { font-size:10.5px; color:var(--ia-text-muted); font-weight:500; }
.eod-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 15px; border-bottom:.5px dashed var(--ia-border); font-size:12.5px; }
.eod-row:last-child { border:none; }
.eod-row .n { font-variant-numeric:tabular-nums; }
.eod-inp { background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text); border-radius:7px; padding:7px 10px; font-size:12.5px; width:110px; text-align:right; font-variant-numeric:tabular-nums; }
.eod-tag { font-size:9.5px; letter-spacing:.04em; text-transform:uppercase; border-radius:999px; padding:2px 8px; font-weight:700; }
.eod-tag.unpaid { background:rgba(248,113,113,.12); color:#f87171; }
.eod-tag.pending { background:rgba(245,158,11,.12); color:#F59E0B; }
.eod-tag.draft { background:rgba(96,165,250,.12); color:#60a5fa; }
.eod-closed { border:1px solid rgba(190,242,100,.4); background:rgba(190,242,100,.06); border-radius:12px; padding:11px 15px; font-size:12.5px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
</style>
@endpush

@section('content')
<div style="max-width:1000px">
  <h1 style="font-size:19px;font-weight:700;margin-bottom:2px">Reports</h1>
  <div style="font-size:12.5px;color:var(--ia-text-muted);margin-bottom:14px">How your business is performing.</div>
  @include('tenant.reports._tab_subnav', ['active' => 'daily'])

  {{-- MARKER-PATCH-634 — daily ops inner subnav --}}
  <div style="display:flex;gap:18px;border-bottom:.5px solid var(--ia-border);margin-bottom:16px">
    <a href="{{ route('tenant.reports.daily') }}" style="padding:10px 2px;font-size:12.5px;color:var(--ia-text);border-bottom:2px solid var(--ia-accent);margin-bottom:-.5px;text-decoration:none;font-weight:600">End of day</a>
    <a href="{{ route('tenant.reports.daily.exports') }}" style="padding:10px 2px;font-size:12.5px;color:var(--ia-text-muted);border-bottom:2px solid transparent;margin-bottom:-.5px;text-decoration:none">Bookkeeping exports</a>
  </div>

  @php $money = fn ($c) => '$' . number_format($c / 100, 2); @endphp

  <div class="eod-bar">
    <a class="eod-btn" href="{{ route('tenant.reports.daily', ['day' => $day->copy()->subDay()->toDateString()]) }}">‹</a>
    <span style="font-weight:700;font-size:14px">{{ $day->format('l, M j, Y') }}</span>
    <a class="eod-btn" href="{{ route('tenant.reports.daily', ['day' => $day->copy()->addDay()->toDateString()]) }}">›</a>
    @unless($isToday)<a class="eod-btn" href="{{ route('tenant.reports.daily') }}">today</a>@endunless
    <span style="margin-left:auto;display:flex;gap:8px">
      <a class="eod-btn" href="{{ route('tenant.reports.daily.print', ['day' => $day->toDateString()]) }}" target="_blank">Print</a>
      @if(!($drawer?->isClosed()))
        <form method="POST" action="{{ route('tenant.reports.daily.close', ['day' => $day->toDateString()]) }}"
              onsubmit="return confirm('Close {{ $day->format('M j') }}? Numbers lock and the drawer count becomes final.')">
          @csrf<button class="eod-btn p" type="submit">Close the day</button>
        </form>
      @endif
    </span>
  </div>

  @if($drawer?->isClosed())
    <div class="eod-closed">
      <span>✓ Day closed {{ tlocal($drawer->closed_at, 'M j g:ia') }} — numbers below are the locked snapshot.
        Over/short: <b>{{ $money($drawer->over_short_cents) }}</b></span>
      <form method="POST" action="{{ route('tenant.reports.daily.reopen', ['day' => $day->toDateString()]) }}" style="display:flex;gap:6px"
            onsubmit="return this.reason.value.trim().length > 2 || (alert('Give a reason.'), false)">
        @csrf
        <input class="eod-inp" style="width:180px;text-align:left" type="text" name="reason" placeholder="reason to reopen" maxlength="200">
        <button class="eod-btn" type="submit">Reopen</button>
      </form>
    </div>
  @endif

  <div class="eod-tiles">
    <div class="eod-tile"><div class="k">Gross sales</div><div class="v">{{ $money($n['gross']) }}</div><div class="m">{{ $n['sale_count'] }} sale{{ $n['sale_count'] === 1 ? '' : 's' }}</div></div>
    <div class="eod-tile"><div class="k">Collected</div><div class="v">{{ $money($n['collected']) }}</div><div class="m">across all methods</div></div>
    <div class="eod-tile"><div class="k">Refunds</div><div class="v">{{ $n['refunds'] > 0 ? '−' : '' }}{{ $money($n['refunds']) }}</div><div class="m">&nbsp;</div></div>
    <div class="eod-tile"><div class="k">Tax collected</div><div class="v">{{ $money($n['tax']) }}</div><div class="m">&nbsp;</div></div>
    <div class="eod-tile"><div class="k">Deposits taken</div><div class="v">{{ $money($n['deposits']) }}</div><div class="m">&nbsp;</div></div>
    <div class="eod-tile"><div class="k">Tips</div><div class="v">{{ $money($n['tips']) }}</div><div class="m">&nbsp;</div></div>
  </div>

  <div class="eod-cols">
    {{-- drawer --}}
    <div class="eod-card">
      <div class="eod-ch">Cash drawer @if($drawer?->isClosed())<span class="m">locked</span>@endif</div>
      @php
        $float   = $drawer->opening_float_cents ?? 0;
        $paidOut = $drawer->paid_out_cents ?? 0;
        $expected = $drawer?->isClosed() ? $drawer->expected_cents : ($float + $n['cash_collected'] - $n['cash_refunds'] - $paidOut);
        $counted = $drawer->counted_cents ?? null;
      @endphp
      @if($drawer?->isClosed())
        <div class="eod-row"><span>Opening float</span><span class="n">{{ $money($float) }}</span></div>
        <div class="eod-row"><span>+ Cash collected</span><span class="n">{{ $money($n['cash_collected']) }}</span></div>
        <div class="eod-row"><span>− Cash refunds</span><span class="n">{{ $money($n['cash_refunds']) }}</span></div>
        <div class="eod-row"><span>− Paid out @if($drawer->paid_out_note)<span style="color:var(--ia-text-muted)">({{ $drawer->paid_out_note }})</span>@endif</span><span class="n">{{ $money($paidOut) }}</span></div>
        <div class="eod-row" style="font-weight:700"><span>Expected</span><span class="n">{{ $money($expected) }}</span></div>
        <div class="eod-row" style="font-weight:700"><span>Counted</span><span class="n">{{ $money($counted) }}</span></div>
        <div class="eod-row" style="font-weight:800"><span>Over / short</span>
          <span class="n" style="color:{{ $drawer->over_short_cents === 0 ? 'var(--ia-accent)' : '#f87171' }}">{{ $money($drawer->over_short_cents) }} {{ $drawer->over_short_cents === 0 ? '✓' : '' }}</span></div>
      @else
        <form method="POST" action="{{ route('tenant.reports.daily.drawer', ['day' => $day->toDateString()]) }}">
          @csrf
          <div class="eod-row"><span>Opening float</span><input class="eod-inp" type="number" step="0.01" min="0" name="opening_float" value="{{ number_format($float / 100, 2, '.', '') }}"></div>
          <div class="eod-row"><span>+ Cash collected</span><span class="n">{{ $money($n['cash_collected']) }}</span></div>
          <div class="eod-row"><span>− Cash refunds</span><span class="n">{{ $money($n['cash_refunds']) }}</span></div>
          <div class="eod-row"><span>− Paid out</span><input class="eod-inp" type="number" step="0.01" min="0" name="paid_out" value="{{ number_format($paidOut / 100, 2, '.', '') }}"></div>
          <div class="eod-row"><span style="color:var(--ia-text-muted);font-size:11.5px">Paid-out note</span><input class="eod-inp" style="width:170px;text-align:left" type="text" name="paid_out_note" maxlength="200" value="{{ $drawer->paid_out_note ?? '' }}" placeholder="what for"></div>
          <div class="eod-row" style="font-weight:700"><span>Expected</span><span class="n">{{ $money($expected) }}</span></div>
          <div class="eod-row" style="font-weight:700"><span>Counted</span><input class="eod-inp" type="number" step="0.01" min="0" name="counted" value="{{ $counted !== null ? number_format($counted / 100, 2, '.', '') : '' }}" placeholder="count it"></div>
          @if($counted !== null)
            <div class="eod-row" style="font-weight:800"><span>Over / short</span>
              <span class="n" style="color:{{ ($counted - $expected) === 0 ? 'var(--ia-accent)' : '#f87171' }}">{{ $money($counted - $expected) }} {{ ($counted - $expected) === 0 ? '✓' : '' }}</span></div>
          @endif
          <div class="eod-row"><span></span><button class="eod-btn" type="submit">Save drawer</button></div>
        </form>
      @endif
    </div>

    {{-- by method --}}
    <div class="eod-card">
      <div class="eod-ch">Payments by method <span class="m">fees arrive with payout reconciliation</span></div>
      <div class="eod-row" style="font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-dim,rgba(255,255,255,.42));font-weight:700">
        <span style="flex:1">Method</span><span style="width:50px;text-align:right">Count</span><span style="width:90px;text-align:right">Collected</span><span style="width:90px;text-align:right">Refunded</span>
      </div>
      @forelse($n['by_method'] as $bm)
        <div class="eod-row">
          <span style="flex:1">{{ $bm['label'] }}</span>
          <span class="n" style="width:50px;text-align:right">{{ $bm['count'] }}</span>
          <span class="n" style="width:90px;text-align:right">{{ $money($bm['collected']) }}</span>
          <span class="n" style="width:90px;text-align:right;color:{{ $bm['refunded'] > 0 ? '#f87171' : 'var(--ia-text-muted)' }}">{{ $bm['refunded'] > 0 ? '−' . $money($bm['refunded']) : '—' }}</span>
        </div>
      @empty
        <div class="eod-row" style="color:var(--ia-text-muted)">No payments recorded this day.</div>
      @endforelse
      <div class="eod-row" style="font-weight:800">
        <span style="flex:1">Total</span><span></span>
        <span class="n" style="width:90px;text-align:right">{{ $money($n['collected']) }}</span>
        <span class="n" style="width:90px;text-align:right">{{ $n['refunds'] > 0 ? '−' . $money($n['refunds']) : '—' }}</span>
      </div>
    </div>
  </div>

  {{-- attention --}}
  <div class="eod-card">
    <div class="eod-ch">Needs attention before close <span class="m">{{ count($attention) }}</span></div>
    @forelse($attention as $a)
      <div class="eod-row">
        <span style="flex:1">{{ $a['label'] }}</span>
        <span class="n">{{ $money($a['amount']) }}</span>
        <span class="eod-tag {{ $a['tag'] }}">{{ $a['tag'] }}</span>
        <a class="eod-btn" style="padding:4px 11px;font-size:11px" href="{{ $a['url'] }}">Open</a>
      </div>
    @empty
      <div class="eod-row" style="color:var(--ia-text-muted)">Nothing outstanding — clean day.</div>
    @endforelse
  </div>
</div>
@endsection

