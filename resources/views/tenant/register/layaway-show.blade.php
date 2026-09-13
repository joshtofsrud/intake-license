@extends('layouts.tenant.app')
@php
  $pageTitle = 'Layaway';
  $sale = $plan->sale;
  $money = fn ($c) => '$' . number_format($c / 100, 2);
@endphp

@section('content')
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $sale->sale_number ?: 'Layaway' }}</h1>
    <p class="ia-page-subtitle">
      {{ $plan->customer?->name ?: 'No customer' }} · opened {{ $plan->created_at?->format('M j, Y') }}
      @if($plan->collect_by) · collect by {{ $plan->collect_by->format('M j, Y') }}@endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.register.layaways.index') }}" class="ia-btn">Back to layaways</a>
  </div>
</div>

@if($plan->status === 'cancelled' || $plan->status === 'completed')
  <div style="background:var(--ia-surface-2);border:0.5px solid var(--ia-border);border-radius:8px;
              padding:12px 15px;margin-bottom:16px;font-size:13px">
    This plan is <strong>{{ $plan->status }}</strong>
    @if($plan->status === 'cancelled' && $plan->cancelled_at)
      — {{ $plan->cancelled_at->format('M j, Y') }}, {{ $money($plan->refund_cents) }} refunded
      @if($plan->restocking_fee_cents) after a {{ $money($plan->restocking_fee_cents) }} restocking fee @endif .
    @elseif($plan->completed_at)
      — handed over {{ $plan->completed_at->format('M j, Y') }}.
    @endif
  </div>
@endif

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:16px;align-items:start">
  <div>
    <div class="ia-card">
      <div class="ia-card-header"><h2 class="ia-card-title">Items</h2></div>
      <div class="ia-card-body">
        @foreach($sale->items as $line)
          @php
            $heldQty = ($heldBy[$line->id] ?? collect())->sum('quantity');
            $orders  = $ordersBy[$line->id] ?? collect();
            $onOrder = $orders->whereNotIn('status', ['pulled', 'cancelled'])->sum('quantity');
          @endphp
          <div style="display:flex;gap:12px;padding:10px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px">
            <span style="color:var(--ia-text-dim);min-width:28px">{{ (int) $line->quantity }}×</span>
            <span style="flex:1">
              {{ $line->name_snapshot }}
              <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:2px">
                @if($heldQty > 0)<span style="color:#f5c451">{{ $heldQty }} held on your shelf</span>@endif
                @if($heldQty > 0 && $onOrder > 0) · @endif
                @if($onOrder > 0)
                  <span style="color:#6fb3f2">{{ $onOrder }} on special order</span>
                  @foreach($orders->whereNotIn('status', ['pulled', 'cancelled']) as $so)
                    · <a href="{{ route('tenant.special-orders.show', $so->id) }}" style="color:var(--ia-accent)">{{ $so->status }}</a>
                  @endforeach
                @endif
                @if($heldQty === 0 && $onOrder === 0)
                  nothing held — this line is a service or was already pulled
                @endif
              </div>
            </span>
            <span style="font-variant-numeric:tabular-nums">{{ $money($line->line_total_cents) }}</span>
          </div>
        @endforeach

        <div style="display:flex;justify-content:space-between;padding-top:12px;font-size:14px;font-weight:650">
          <span>Total</span><span>{{ $money($sale->total_cents) }}</span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:5px;font-size:13px;color:var(--ia-text-muted)">
          <span>Paid</span><span>{{ $money($paid) }}</span>
        </div>
        <div style="display:flex;justify-content:space-between;padding-top:5px;font-size:14px;font-weight:650">
          <span>Balance</span><span>{{ $money($balance) }}</span>
        </div>
      </div>
    </div>

    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-header"><h2 class="ia-card-title">Payments</h2></div>
      <div class="ia-card-body">
        @forelse($sale->payments as $pay)
          <div style="display:flex;gap:12px;padding:8px 0;border-bottom:0.5px solid var(--ia-border);font-size:12.5px">
            <span style="color:var(--ia-text-dim)">{{ $pay->created_at?->format('M j, Y') }}</span>
            <span style="flex:1">{{ ucfirst(str_replace('_', ' ', $pay->method ?: 'payment')) }}
              @if($pay->external_reference)<span style="color:var(--ia-text-dim)"> · {{ $pay->external_reference }}</span>@endif
            </span>
            <span style="font-variant-numeric:tabular-nums">{{ $money($pay->amount_cents) }}</span>
          </div>
        @empty
          <div style="color:var(--ia-text-dim);font-size:12.5px">Nothing paid yet.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div>
    <div class="ia-card">
      <div class="ia-card-header"><h2 class="ia-card-title">Plan</h2></div>
      <div class="ia-card-body" style="font-size:13px">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:7px 14px">
          <span style="color:var(--ia-text-dim)">Status</span>
          <span>
            @if($plan->isOverdue())<span style="color:#f2777a">Overdue</span>
            @elseif($plan->status === 'ready' && $plan->awaitingArrival())Paid · waiting on a special order
            @elseif($plan->status === 'ready')Paid · ready to hand over
            @else{{ ucfirst($plan->status) }}@endif
          </span>
          <span style="color:var(--ia-text-dim)">Next due</span>
          <span>@if($plan->next_due_on){{ $plan->next_due_on->format('M j, Y') }} · {{ $money($plan->scheduled_amount_cents ?? 0) }}@else—@endif</span>
          <span style="color:var(--ia-text-dim)">Frequency</span>
          <span>{{ ucfirst($plan->frequency) }}</span>
          <span style="color:var(--ia-text-dim)">Grace</span>
          <span>{{ $plan->grace_days }} days</span>
        </div>

        @if($plan->isOpen())
          <div style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
            <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn--primary" style="text-align:center;text-decoration:none">
              Take a payment at the register
            </a>
            <div style="font-size:11.5px;color:var(--ia-text-dim)">
              Attach {{ $plan->customer?->name ?: 'the customer' }} at the register and their open plans appear with a Pay on this button.
            </div>
          </div>
        @endif
      </div>
    </div>

    @if($plan->isOpen() && $canCancel)
      {{-- MARKER-LAYAWAY-TAB — the arithmetic before the button, not after.
           Fee applies only to what is actually HELD: a special order that
           never arrived held nothing and carries no fee. --}}
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-header"><h2 class="ia-card-title">If cancelled now</h2></div>
        <div class="ia-card-body" style="font-size:12.5px">
          <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px">
            <span style="color:var(--ia-text-dim)">Paid so far</span><span>{{ $money($preview['paid_cents']) }}</span>
            <span style="color:var(--ia-text-dim)">Value held</span><span>{{ $money($preview['held_value']) }}</span>
            <span style="color:var(--ia-text-dim)">Restocking fee</span>
            <span>−{{ $money($preview['fee_cents']) }}@if($preview['held_value'] === 0)<span style="color:var(--ia-text-dim)"> · nothing was held</span>@endif</span>
            <span style="color:var(--ia-text-dim)">Refund</span>
            <span style="color:#7ee081;font-weight:600">{{ $money($preview['refund_cents']) }}</span>
          </div>

          @if($preview['open_special_orders'] > 0)
            <div style="margin-top:10px;padding:9px 11px;border-radius:6px;background:rgba(245,196,81,.07);
                        border:0.5px solid rgba(245,196,81,.3);line-height:1.5">
              {{ $preview['open_special_orders'] }} special order(s) stay open. Cancelling the layaway does not
              cancel them with the vendor — decide that separately.
            </div>
          @endif

          <form method="POST" action="{{ route('tenant.register.layaways.cancel', $plan->id) }}" style="margin-top:12px"
                onsubmit="return confirm('Cancel this layaway? Held stock is released and {{ $money($preview['refund_cents']) }} is refunded. This cannot be undone.')">
            @csrf
            <label class="ia-label">Reason</label>
            <select name="reason" class="ia-input" style="margin-bottom:8px">
              <option value="customer_request">Customer changed their mind</option>
              <option value="non_payment">Payments stopped</option>
              <option value="unavailable">Item could not be supplied</option>
              <option value="other">Other</option>
            </select>
            <label class="ia-label">Refund by</label>
            <select name="method" class="ia-input" style="margin-bottom:10px">
              <option value="cash">Cash</option>
              <option value="check">Check</option>
              <option value="store_credit">Store credit</option>
              <option value="mark_paid">Record only — refunded elsewhere</option>
            </select>
            <button type="submit" class="ia-btn" style="color:#f2777a;width:100%">Cancel this layaway</button>
          </form>
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
