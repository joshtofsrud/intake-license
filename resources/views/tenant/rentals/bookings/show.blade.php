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

    {{-- MARKER-PATCH-219B — sales-as-money: payments flow through the register. --}}
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Payments — via register</span></div>
      @if($rental->sales->isEmpty())
        <div style="padding:18px 16px;font-size:12.5px;opacity:.55">No register sales linked yet. Use Collect payment below.</div>
      @else
        @foreach($rental->sales as $sale)
          <div style="padding:10px 16px;border-bottom:0.5px solid var(--ia-border)">
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px">
              <span style="font-weight:600">{{ $sale->sale_number }}
                <span style="font-size:11px;font-weight:700;margin-left:6px;{{ $sale->payment_status === 'paid' ? 'color:#34d399' : ($sale->payment_status === 'refunded' ? 'color:#ef4444' : 'opacity:.55') }}">{{ strtoupper($sale->payment_status) }}</span>
              </span>
              <span style="font-weight:700">{{ format_money($sale->total_cents) }}</span>
            </div>
            @foreach($sale->payments as $p)
              <div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;opacity:.75;margin-top:4px">
                <span>{{ tlocal_datetime($p->recorded_at, 'M j, g:i A') }} · {{ ucfirst($p->kind) }} · {{ $p->method ?? '—' }}</span>
                <span style="{{ $p->amount_cents < 0 ? 'color:#ef4444' : '' }}">{{ format_money(abs($p->amount_cents)) }}{{ $p->amount_cents < 0 ? ' refund' : '' }}</span>
              </div>
            @endforeach
          </div>
        @endforeach
      @endif
      @if($rental->status !== 'cancelled' && $balance > 0)
      <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" style="display:flex;gap:8px;padding:12px 16px;align-items:end">
        @csrf
        {{-- MARKER-PATCH-232B — come back to this booking after payment. --}}
        <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.show', $rental->id), PHP_URL_PATH) }}">
        <div>
          <label class="ia-label" style="display:block;margin-bottom:4px">Amount $</label>
          <input type="number" name="amount" min="0.01" step="0.01" required value="{{ number_format($balance / 100, 2, '.', '') }}" class="ia-input" style="width:140px;text-align:right">
        </div>
        <button type="submit" class="ia-btn ia-btn--primary">Collect payment</button>
        <span style="font-size:11px;opacity:.45;align-self:center">Creates a register sale — take cash, card, or send a payment link there. Refunds: open the sale in register history.</span>
      </form>
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

    {{-- MARKER-PATCH-220 — deposit hold panel --}}
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Deposit</span>
      <div style="margin-top:10px;font-size:12.5px">
        @if($rental->deposit_status === 'authorized')
          <div style="font-weight:700;margin-bottom:8px">{{ format_money($rental->deposit_hold_cents) }} on hold</div>
          <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.release', $rental->id) }}" style="margin-bottom:8px">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Release hold</button>
          </form>
          <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.capture', $rental->id) }}" onsubmit="return confirm('Capture from the customer\'s card?')">@csrf
            <div style="display:flex;gap:6px;margin-bottom:6px">
              <input type="number" name="amount" min="0.50" step="0.01" max="{{ number_format($rental->deposit_hold_cents / 100, 2, '.', '') }}" placeholder="Amount $" required class="ia-input" style="flex:1;text-align:right">
              <button type="submit" class="ia-btn ia-btn--primary">Capture</button>
            </div>
            <input type="text" name="reason" maxlength="500" placeholder="Reason (shows on the sale)" class="ia-input" style="width:100%">
          </form>
          <p style="font-size:11px;opacity:.45;margin-top:8px">Release = no charge, no ledger entry. Capture = damage charge through the register ledger.</p>
        @elseif($rental->deposit_status === 'released')
          <div style="opacity:.65">Hold released — no charge.</div>
        @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
          <div style="opacity:.85">{{ $rental->deposit_status === 'captured' ? 'Hold fully captured' : 'Hold partially captured' }} — see the linked sale above.</div>
        @elseif(in_array($rental->status, ['reserved', 'out'], true))
          @if(tenant()->direct_payments_enabled)
            <div id="dep-start">
              <div style="display:flex;gap:6px">
                <input type="number" id="dep-amount" min="0.50" step="0.01" value="{{ number_format(max(0, $rental->lines->where('kind','unit')->sum(fn ($l) => (int) ($l->unit?->deposit_cents ?? 0))) / 100, 2, '.', '') }}" class="ia-input" style="flex:1;text-align:right">
                <button type="button" class="ia-btn ia-btn--primary" id="dep-authorize">Authorize hold</button>
              </div>
              <p style="font-size:11px;opacity:.45;margin-top:6px">Authorizes the customer's card without charging it.</p>
            </div>
            <div id="dep-element-wrap" style="display:none;margin-top:10px">
              <div id="dep-element"></div>
              <button type="button" class="ia-btn ia-btn--primary" id="dep-confirm" style="width:100%;margin-top:8px">Place hold</button>
              <div id="dep-error" style="font-size:12px;color:#ef4444;margin-top:6px"></div>
            </div>
          @else
            <div style="opacity:.55">Enable card payments in Settings → Payments to take deposit holds.</div>
          @endif
        @else
          <div style="opacity:.55">No deposit was held on this rental.</div>
        @endif
      </div>
    </div>

    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Actions</span>
      <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px">
        @if($rental->status === 'reserved')
          {{-- MARKER-PATCH-232 — guided flow is the front door; one-click stays as the escape hatch. --}}
          <a href="{{ route('tenant.rentals.bookings.checkout.flow', $rental->id) }}" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;text-decoration:none">Check out →</a>
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkout', $rental->id) }}" onsubmit="return confirm('Skip the agreement, condition check, and deposit steps?')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Quick check out (skip flow)</button>
          </form>
          <form method="POST" action="{{ route('tenant.rentals.bookings.cancel', $rental->id) }}" onsubmit="return confirm('Cancel this reservation?')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Cancel reservation</button>
          </form>
        @elseif($rental->status === 'out')
          {{-- MARKER-PATCH-233 — guided return is the front door; one-click stays as the escape hatch. --}}
          <a href="{{ route('tenant.rentals.bookings.return.flow', $rental->id) }}" class="ia-btn ia-btn--primary" style="width:100%;justify-content:center;text-decoration:none">Start return →</a>
          <form method="POST" action="{{ route('tenant.rentals.bookings.checkin', $rental->id) }}" onsubmit="return confirm('Skip inspection and charges? A clean check-in auto-releases any deposit hold.')">@csrf
            <button type="submit" class="ia-btn" style="width:100%">Quick check in (skip flow)</button>
          </form>
        @else
          <p style="font-size:12.5px;opacity:.55;margin:0">
            {{ $rental->status === 'returned' ? 'Returned ' . ($rental->returned_at ? tlocal_datetime($rental->returned_at, 'M j, g:i A') : '') : 'Cancelled.' }}
          </p>
        @endif
      </div>

    </div>

    @if($rental->notes)
    <div class="ia-card" style="padding:16px">
      <span class="ia-label">Notes</span>
      <p style="font-size:12.5px;margin-top:8px;white-space:pre-wrap">{{ $rental->notes }}</p>
    </div>
    @endif
  </div>

</div>

@if($rental->deposit_status === 'none' && in_array($rental->status, ['reserved', 'out'], true) && tenant()->direct_payments_enabled)
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var btn = document.getElementById('dep-authorize');
  if (!btn) return;
  var intentUrl  = '{{ route('tenant.rentals.bookings.deposit.intent', $rental->id) }}';
  var confirmUrl = '{{ route('tenant.rentals.bookings.deposit.confirm', $rental->id) }}';
  var csrf = '{{ csrf_token() }}';
  var stripe = null, elements = null, piId = null;

  function post(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(payload || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
    post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
      if (!res.ok || !res.json.ok) {
        alert(res.json.error || 'Could not start the hold.');
        btn.disabled = false;
        return;
      }
      piId = res.json.payment_intent;
      stripe = Stripe(res.json.publishable_key);
      elements = stripe.elements({ clientSecret: res.json.client_secret });
      elements.create('payment').mount('#dep-element');
      document.getElementById('dep-element-wrap').style.display = 'block';
    }).catch(function () { alert('Could not start the hold.'); btn.disabled = false; });
  });

  document.getElementById('dep-confirm').addEventListener('click', function () {
    var confirmBtn = this;
    confirmBtn.disabled = true;
    document.getElementById('dep-error').textContent = '';
    stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
      if (result.error) {
        document.getElementById('dep-error').textContent = result.error.message || 'Card was not authorized.';
        confirmBtn.disabled = false;
        return;
      }
      post(confirmUrl, { payment_intent: piId }).then(function (res) {
        if (res.ok && res.json.ok) { window.location.reload(); }
        else {
          document.getElementById('dep-error').textContent = (res.json && res.json.error) || 'Could not verify the hold.';
          confirmBtn.disabled = false;
        }
      });
    });
  });
})();
</script>
@endif

@endsection
