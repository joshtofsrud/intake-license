@extends('layouts.tenant.app')
@php $pageTitle = $rental->rental_number; @endphp

{{-- MARKER-PATCH-219 — rental detail: lines, ledger, transitions. --}}

@section('content')

@php
  $late = $rental->isOverdue();
  $statusColor = $late ? '#ef4444' : ($rental->status === 'out' ? '#f59e0b' : ($rental->status === 'returned' ? '#34d399' : ($rental->status === 'cancelled' ? '#ef4444' : 'inherit')));
  $balance = $rental->total_cents - $rental->paid_cents;
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $rental->rental_number }}
      <span style="font-size:13px;font-weight:800;color:{{ $statusColor }};margin-left:8px">{{ $late ? 'OVERDUE' : strtoupper($rental->status) }}</span>
    </h1>
    <p class="ia-page-subtitle">{{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }}</p>
  </div>
  <a href="{{ route('tenant.rentals.bookings.index') }}" class="ia-btn">All bookings</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start">

  <div>
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Lines</span></div>
      @foreach($rental->lines as $line)
        <div style="display:flex;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:0.5px solid var(--ia-border)">
          <span style="font-size:13px">{{ $line->name_snapshot }}
            <span style="opacity:.5;font-size:11.5px">{{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }} ({{ $line->rate_mode_snapshot }})</span>
          </span>
          <span style="font-size:13px;font-weight:600">{{ format_money($line->line_total_cents) }}</span>
        </div>
      @endforeach
      <div style="padding:12px 16px;font-size:13px">
        <div style="display:flex;justify-content:space-between"><span style="opacity:.65">Subtotal</span><span>{{ format_money($rental->subtotal_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between"><span style="opacity:.65">Tax</span><span>{{ format_money($rental->tax_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:800;margin-top:4px"><span>Total</span><span>{{ format_money($rental->total_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;margin-top:4px"><span style="opacity:.65">Paid (ledger)</span><span>{{ format_money($rental->paid_cents) }}</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:700;{{ $balance > 0 ? 'color:#f59e0b' : '' }}"><span>Balance</span><span>{{ format_money(max(0, $balance)) }}</span></div>
      </div>
    </div>

    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Payments</span></div>
      @if($rental->payments->isEmpty())
        <div style="padding:18px 16px;font-size:12.5px;opacity:.55">No payments recorded yet.</div>
      @else
        @foreach($rental->payments as $p)
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;padding:9px 16px;border-bottom:0.5px solid var(--ia-border);font-size:12.5px">
            <span>{{ tlocal_datetime($p->recorded_at, 'M j, g:i A') }}</span>
            <span style="opacity:.7">{{ ucfirst($p->kind) }} · {{ $p->method ?? '—' }}</span>
            <span style="opacity:.55">{{ $p->notes }}</span>
            <span style="font-weight:700;text-align:right;{{ $p->amount_cents < 0 ? 'color:#ef4444' : '' }}">{{ format_money(abs($p->amount_cents)) }}{{ $p->amount_cents < 0 ? ' refund' : '' }}</span>
          </div>
        @endforeach
      @endif
      @if($rental->status !== 'cancelled')
      <form method="POST" action="{{ route('tenant.rentals.bookings.payments.store', $rental->id) }}" style="display:grid;grid-template-columns:1fr 1fr 1fr 2fr auto;gap:8px;padding:12px 16px;align-items:end">
        @csrf
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Amount $</label>
          <input type="number" name="amount" min="0.01" step="0.01" required class="ia-input" style="width:100%;text-align:right">
        </div>
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Type</label>
          <select name="direction" class="ia-input" style="width:100%"><option value="charge">Charge</option><option value="refund">Refund</option></select>
        </div>
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Method</label>
          <select name="method" class="ia-input" style="width:100%"><option value="cash">Cash</option><option value="card">Card</option><option value="other">Other</option></select>
        </div>
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Note</label>
          <input type="text" name="notes" maxlength="500" class="ia-input" style="width:100%">
        </div>
        <button type="submit" class="ia-btn ia-btn--primary">Record</button>
      </form>
      <p style="padding:0 16px 12px;font-size:11px;opacity:.45">Card here means taken outside Intake (terminal). In-app card payments and deposits arrive with the next update.</p>
      @endif
    </div>
  </div>

  <div>
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Customer</span>
      <div style="margin-top:8px">
        <a href="{{ route('tenant.customers.show', $rental->customer_id) }}" style="font-size:14px;font-weight:700;text-decoration:none;color:inherit">{{ $rental->customer?->first_name }} {{ $rental->customer?->last_name }}</a>
        <div style="font-size:12px;opacity:.6;margin-top:2px">{{ $rental->customer?->email }}</div>
        @if($rental->customer?->phone)<div style="font-size:12px;opacity:.6">{{ $rental->customer?->phone }}</div>@endif
      </div>
    </div>

    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Actions</span>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
        @if($rental->status === 'reserved')
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkout', $rental->id) }}">@csrf
            <button type="submit" class="ia-btn ia-btn--primary" style="width:100%">Check out</button>
          </form>
          <form method="POST" action="{{ route('tenant.rentals.bookings.cancel', $rental->id) }}" onsubmit="return confirm('Cancel this reservation?')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Cancel reservation</button>
          </form>
        @elseif($rental->status === 'out')
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkin', $rental->id) }}">@csrf
            <button type="submit" class="ia-btn ia-btn--primary" style="width:100%">Check in (return)</button>
          </form>
        @else
          <p style="font-size:12.5px;opacity:.55;margin:0">
            {{ $rental->status === 'returned' ? 'Returned ' . ($rental->returned_at ? tlocal_datetime($rental->returned_at, 'M j, g:i A') : '') : 'Cancelled.' }}
          </p>
        @endif
      </div>
      <p style="font-size:11px;opacity:.45;margin-top:10px">Condition checks, deposits, and signed agreements arrive with the next update.</p>
    </div>

    @if($rental->notes)
    <div class="ia-card" style="padding:16px">
      <span class="ia-label">Notes</span>
      <p style="font-size:12.5px;margin-top:8px;white-space:pre-wrap">{{ $rental->notes }}</p>
    </div>
    @endif
  </div>

</div>

@endsection
