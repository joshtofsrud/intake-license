@extends('layouts.tenant.app')
@php $pageTitle = 'Layaways'; @endphp

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Layaways</h1>
    <p class="ia-page-subtitle">Goods held and money taken against them.</p>
  </div>
</div>

<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.layaways.index') }}" class="reg-tab-link active">Layaways</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a>
  </div>
</div>

{{-- MARKER-LAYAWAY-TAB — legend. Two things here are invisible without being
     said: held stock is on the shelf but unsellable, and money taken is a
     liability rather than revenue until the goods leave. --}}
<div style="background:rgba(111,179,242,.07);border:0.5px solid rgba(111,179,242,.3);border-radius:8px;
            padding:11px 14px;font-size:12.5px;color:var(--ia-text-muted);margin-bottom:16px;line-height:1.55">
  <strong>Held stock is still on your shelf</strong> and still counts when you count it — it just
  cannot be sold to anyone else. <strong>Money taken is not revenue yet.</strong> It becomes a sale
  on the day the goods are handed over, dated that day.
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">OPEN PLANS</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">{{ $rows->count() }}</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">MONEY HELD</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">${{ number_format($heldCents / 100, 2) }}</div>
    <div style="font-size:11px;color:var(--ia-text-dim)">a liability, not revenue</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">STILL OWED</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">${{ number_format($owedCents / 100, 2) }}</div>
  </div></div>
  <div class="ia-card"><div class="ia-card-body">
    <div style="font-size:11px;letter-spacing:.07em;color:var(--ia-text-dim)">NEEDS ATTENTION</div>
    <div style="font-size:22px;font-weight:650;margin-top:3px">
      {{ $overdue }}<span style="font-size:13px;color:var(--ia-text-dim)"> overdue</span>
      @if($readyNow)<span style="font-size:13px;color:var(--ia-text-dim)"> · {{ $readyNow }} ready</span>@endif
    </div>
  </div></div>
</div>

<div class="ia-card">
  @if($rows->isEmpty())
    <div class="ia-card-body" style="color:var(--ia-text-muted)">
      No open layaways. Start one from the register: add items, attach a customer, then
      <strong>Put on layaway</strong> in the tender window.
    </div>
  @else
    <table class="ia-table">
      <thead>
        <tr>
          <th>Plan</th><th>Customer</th><th>Items</th>
          <th style="text-align:right">Paid / total</th>
          <th>Next due</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
          @php $p = $r['plan']; @endphp
          <tr style="cursor:pointer" onclick="window.location='{{ route('tenant.register.layaways.show', $p->id) }}'">
            <td>
              <strong>{{ $p->sale->sale_number ?: Str::substr($p->id, 0, 8) }}</strong>
              <div style="font-size:11.5px;color:var(--ia-text-dim)">{{ $p->created_at?->format('M j') }}</div>
            </td>
            <td>{{ $p->customer?->name ?: '—' }}</td>
            <td>
              {{ $p->sale->items->first()?->name_snapshot ?: '—' }}
              @if($p->sale->items->count() > 1)
                <div style="font-size:11.5px;color:var(--ia-text-dim)">+ {{ $p->sale->items->count() - 1 }} more</div>
              @endif
            </td>
            <td style="text-align:right">
              ${{ number_format($r['paid'] / 100, 2) }} / ${{ number_format($p->sale->total_cents / 100, 2) }}
              <div style="height:4px;background:var(--ia-surface-2);border-radius:99px;margin-top:5px;overflow:hidden;width:110px;margin-left:auto">
                <div style="height:100%;width:{{ min(100, $r['pct']) }}%;background:var(--ia-accent)"></div>
              </div>
            </td>
            <td>
              @if($r['ready'])<span style="color:var(--ia-text-dim)">—</span>
              @elseif($p->next_due_on)
                {{ $p->next_due_on->format('M j') }}
                @if($p->scheduled_amount_cents)
                  <div style="font-size:11.5px;color:var(--ia-text-dim)">${{ number_format($p->scheduled_amount_cents / 100, 2) }}</div>
                @endif
              @else<span style="color:var(--ia-text-dim)">—</span>@endif
            </td>
            <td>
              @if($r['overdue'])
                <span class="ia-badge" style="color:#f2777a;border-color:rgba(242,119,122,.4)">Overdue</span>
              @elseif($r['ready'])
                <span class="ia-badge" style="color:#6fb3f2;border-color:rgba(111,179,242,.4)">Ready to collect</span>
              @elseif($r['awaiting'])
                <span class="ia-badge ia-badge--amber">Awaiting arrival</span>
              @elseif($r['due_soon'])
                <span class="ia-badge ia-badge--amber">Due soon</span>
              @else
                <span class="ia-badge">On track</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
