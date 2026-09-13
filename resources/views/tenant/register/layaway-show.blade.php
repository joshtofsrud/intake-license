@extends('layouts.tenant.app')
@php
  $pageTitle = 'Layaway';
  $sale = $plan->sale;
  $money = fn ($c) => '$' . number_format($c / 100, 2);
@endphp

{{-- MARKER-LAYAWAY-NAV — the tab styles live per page in this app;
     copying the markup without them is why this rendered as plain text. --}}
@push('styles')
<style>
  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }
  /* MARKER-REG-MOBILE ------------------------------------------------- */
  /* display:contents keeps the links as direct flex children of the bar on
     desktop, so nothing about the existing layout changes. */
  .reg-tabs-scroll{display:contents}

  @media (max-width: 760px){
    .ia-page-subtitle{display:none}

    .reg-tabs-bar{display:block;flex-wrap:nowrap}
    .reg-tabs-scroll{
      display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;
      -webkit-overflow-scrolling:touch
    }
    .reg-tabs-scroll::-webkit-scrollbar{display:none}
    .reg-tab-link{white-space:nowrap;flex:0 0 auto;padding:10px 14px}
  }

  .reg-tab-link{
    padding:10px 18px;font-size:13px;font-weight:500;color:var(--ia-text-dim);
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-0.5px;
    transition:color var(--ia-t),border-color var(--ia-t)
  }
  .reg-tab-link:hover{color:var(--ia-text)}
  .reg-tab-link.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}

  .quotes-empty{
    padding:60px 20px;text-align:center;color:var(--ia-text-dim);
    border:0.5px dashed var(--ia-border);border-radius:var(--ia-r-lg);
    background:var(--ia-surface)
  }
  .quotes-empty h3{font-size:16px;color:var(--ia-text);margin-bottom:6px;font-weight:500}
  .quotes-empty p{font-size:13px;line-height:1.5;max-width:420px;margin:0 auto}

  .quotes-table-wrap{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);overflow:hidden
  }
  .quotes-table{width:100%;border-collapse:collapse}
  .quotes-table thead th{
    text-align:left;padding:12px 16px;font-size:11px;font-weight:600;
    color:var(--ia-text-dim);text-transform:uppercase;letter-spacing:.06em;
    background:var(--ia-surface-2);border-bottom:0.5px solid var(--ia-border)
  }
  .quotes-table tbody td{
    padding:14px 16px;font-size:13px;color:var(--ia-text);
    border-bottom:0.5px solid var(--ia-border);vertical-align:middle
  }
  .quotes-table tbody tr:last-child td{border-bottom:none}
  .quotes-table tbody tr:hover{background:var(--ia-hover)}

  .q-customer-name{font-weight:500}
  .q-customer-email{font-size:11px;color:var(--ia-text-dim);margin-top:2px}
  .q-meta-line{color:var(--ia-text-dim);font-size:12px}
  .q-total{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
  .q-actions{display:flex;gap:6px;justify-content:flex-end}
  .q-btn-convert{
    padding:6px 12px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:12px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .q-btn-convert:hover{filter:brightness(.93)}
  .q-btn-discard{
    padding:6px 10px;background:transparent;border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-sm);color:var(--ia-text-dim);font-size:12px;
    font-family:inherit;cursor:pointer
  }
  .q-btn-discard:hover{color:#F09595;border-color:#F09595}

  .quotes-count{font-size:13px;color:var(--ia-text-dim)}

  .q-dashboard{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:12px;margin-bottom:24px
  }
  .q-card{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-lg);padding:16px;cursor:pointer;
    transition:border-color var(--ia-t),background var(--ia-t);
    position:relative
  }
  .q-card:hover{border-color:var(--ia-border-strong);background:var(--ia-hover)}
  .q-card.active{border-color:var(--ia-accent);background:rgba(190,242,100,.04)}
  .q-card .q-card-label{
    font-size:11px;text-transform:uppercase;letter-spacing:.06em;
    color:var(--ia-text-dim);font-weight:600;margin-bottom:8px
  }
  .q-card .q-card-primary{
    font-size:24px;font-weight:600;color:var(--ia-text);
    font-variant-numeric:tabular-nums;line-height:1.1
  }
  .q-card .q-card-meta{
    font-size:12px;color:var(--ia-text-dim);margin-top:6px
  }
  .q-card.urgent .q-card-primary{color:#FFB450}
  .q-card.critical .q-card-primary{color:#F09595}
  .q-card.healthy .q-card-primary{color:var(--ia-accent)}

  .q-clear-filter{
    margin-left:8px;padding:4px 10px;background:transparent;
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text-dim);font-size:11px;font-family:inherit;
    cursor:pointer;display:none
  }
  .q-clear-filter:hover{color:var(--ia-text);border-color:var(--ia-border-strong)}
  .q-clear-filter.visible{display:inline-block}

  .quotes-toolbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-bottom:14px;flex-wrap:wrap
  }
  .quotes-search{
    flex:1;min-width:220px;max-width:360px;padding:9px 12px;
    background:var(--ia-input-bg);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-md);color:var(--ia-text);font-size:13px;
    font-family:inherit
  }
  .quotes-search:focus{outline:none;border-color:var(--ia-accent)}

  .quotes-table thead th.sortable{cursor:pointer;user-select:none}
  .quotes-table thead th.sortable:hover{color:var(--ia-text)}
  .quotes-table thead th .sort-arrow{
    display:inline-block;margin-left:4px;font-size:10px;
    color:var(--ia-text-muted);opacity:.5
  }
  .quotes-table thead th.sort-active .sort-arrow{
    color:var(--ia-accent);opacity:1
  }

  .quotes-empty-search{
    padding:40px 20px;text-align:center;color:var(--ia-text-dim);
    font-size:13px
  }

  .reg-modal-bg{
    position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;
    align-items:center;justify-content:center;z-index:1000;padding:20px
  }
  .reg-modal-bg.open{display:flex}
  .reg-modal{
    background:var(--ia-surface);border:0.5px solid var(--ia-border);
    border-radius:var(--ia-r-xl);padding:24px;width:100%;max-width:380px
  }
  .reg-modal h2{font-size:18px;font-weight:600;margin-bottom:8px;color:var(--ia-text)}
  .reg-modal .lede{color:var(--ia-text-dim);font-size:13px;margin-bottom:18px}
  .reg-modal-actions{display:flex;gap:8px;margin-top:18px}
  .reg-btn-secondary{
    flex:1;padding:11px;background:var(--ia-surface-2);
    border:0.5px solid var(--ia-border);border-radius:var(--ia-r-sm);
    color:var(--ia-text);font-size:13px;font-weight:500;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-secondary:hover{border-color:var(--ia-border-strong)}
  .reg-btn-primary{
    flex:1;padding:11px;background:var(--ia-accent);color:var(--ia-accent-text);
    border:none;border-radius:var(--ia-r-sm);font-size:13px;font-weight:600;
    font-family:inherit;cursor:pointer
  }
  .reg-btn-primary:hover:not(:disabled){filter:brightness(.93)}
  .reg-btn-primary:disabled{opacity:.4;cursor:not-allowed}
</style>
@endpush

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
