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
    <h1 class="ia-page-title" style="display:flex;align-items:center;gap:10px">{{ $rental->rental_number }}
      {{-- MARKER-PATCH-234 — shared pill vocabulary. --}}
      @include('tenant.rentals._status-pill', ['rental' => $rental])
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

{{-- MARKER-PATCH-234 — pipeline stepper: real timestamps per stage, red
     missed-due, cancelled short-circuits. --}}
@php
  $missedDue = $rental->due_at && $rental->due_at->isPast() && in_array($rental->status, ['out'], true);
  $stages = $rental->status === 'cancelled'
    ? [
        ['t' => 'Reserved',  'at' => $rental->created_at,   'state' => 'hit'],
        ['t' => 'Cancelled', 'at' => $rental->cancelled_at, 'state' => 'bad'],
      ]
    : [
        ['t' => 'Reserved',    'at' => $rental->created_at,     'state' => 'hit'],
        ['t' => 'Checked out', 'at' => $rental->checked_out_at, 'state' => in_array($rental->status, ['out', 'returned'], true) ? 'hit' : 'next'],
        ['t' => 'Due back',    'at' => $rental->due_at,         'state' => $rental->status === 'returned' ? 'hit' : ($missedDue ? 'bad' : ($rental->status === 'out' ? 'now' : 'next'))],
        ['t' => 'Returned',    'at' => $rental->returned_at,    'state' => $rental->status === 'returned' ? 'hit' : 'next'],
      ];
@endphp
<div class="ia-card" style="margin-bottom:16px;padding:14px 18px;display:flex;align-items:center;flex-wrap:wrap">
  @foreach($stages as $i => $st)
    @php
      [$dotStyle, $txtColor] = match ($st['state']) {
        'hit' => ['background:var(--ia-accent,#BEF264)', 'inherit'],
        'now' => ['background:#5BA3D0;box-shadow:0 0 0 4px rgba(91,163,208,.18)', 'inherit'],
        'bad' => ['background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.16)', '#ef4444'],
        default => ['background:var(--ia-border-strong,rgba(255,255,255,.22))', 'rgba(255,255,255,.55)'],
      };
    @endphp
    <div style="display:flex;align-items:center;gap:8px">
      <span style="width:9px;height:9px;border-radius:50%;{{ $dotStyle }}"></span>
      <div>
        <div style="font-size:11.5px;font-weight:550;color:{{ $txtColor }}">{{ $st['t'] }}</div>
        <div style="font-size:10px;opacity:.5;{{ $st['state'] === 'bad' ? 'color:#ef4444;opacity:1' : '' }}">{{ $st['at'] ? tlocal_datetime($st['at'], 'M j, g:i a') . ($st['state'] === 'bad' ? ' — missed' : '') : '—' }}</div>
      </div>
    </div>
    @if(!$loop->last)<span style="flex:1;min-width:18px;height:1.5px;background:{{ $st['state'] === 'hit' ? 'rgba(190,242,100,.5)' : 'var(--ia-border)' }};margin:0 10px"></span>@endif
  @endforeach
</div>

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

    {{-- MARKER-PATCH-234 — derived activity feed. --}}
    <div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:16px">
      <div style="padding:12px 16px;border-bottom:0.5px solid var(--ia-border)"><span class="ia-label">Activity</span></div>
      @if($feed->isEmpty())
        <div style="padding:18px 16px;font-size:12.5px;opacity:.55">Nothing yet.</div>
      @else
        @foreach($feed as $i => $ev)
          @php
            $dotColor = match ($ev['dot']) {
              'lime' => 'var(--ia-accent,#BEF264)',
              'blue' => '#5BA3D0',
              'red'  => '#ef4444',
              default => 'var(--ia-border-strong,rgba(255,255,255,.3))',
            };
          @endphp
          <div style="display:grid;grid-template-columns:20px 1fr;gap:12px;padding:9px 18px;position:relative">
            @if(!$loop->last)<span style="position:absolute;left:27px;top:30px;bottom:-4px;width:1px;background:var(--ia-border)"></span>@endif
            <span style="width:9px;height:9px;border-radius:50%;background:{{ $dotColor }};margin-top:6px;justify-self:center"></span>
            <div>
              <div style="font-size:12.5px">{{ $ev['text'] }}</div>
              <div style="font-size:11px;opacity:.5;font-family:var(--ia-font-mono,monospace)">{{ tlocal_datetime($ev['at'], 'M j, g:i a') }}</div>
            </div>
          </div>
        @endforeach
      @endif
    </div>
  </div>

  <div>
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Customer</span>
      <div style="margin-top:8px">
        <a href="{{ route('tenant.customers.show', $rental->customer_id) }}" style="font-size:14px;font-weight:700;text-decoration:none;color:inherit">{{ $rental->customer?->fullName() }}</a>
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

    {{-- MARKER-PATCH-234 — documents: signed agreement + check photos. --}}
    @php
      $docChecks = $rental->conditionChecks->filter(fn ($c) => is_array($c->photos) && count($c->photos));
    @endphp
    @if($rental->agreement_pdf_path || $docChecks->isNotEmpty())
    <div class="ia-card" style="padding:16px;margin-bottom:16px">
      <span class="ia-label">Documents</span>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;font-size:12.5px">
        @if($rental->agreement_pdf_path)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span>Agreement v{{ $rental->agreement_template_version }} — signed</span>
            <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">PDF →</a>
          </div>
        @endif
        @foreach($docChecks as $check)
          <div style="display:flex;justify-content:space-between;gap:10px">
            <span>{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} — {{ $check->unit?->identifier ?: 'unit' }} ({{ count($check->photos) }} photo{{ count($check->photos) === 1 ? '' : 's' }})</span>
            <span>
              @foreach($check->photos as $pi => $p)
                <a href="{{ Storage::disk('public')->url($p) }}" target="_blank" style="color:var(--ia-accent);text-decoration:none">{{ $pi + 1 }}</a>{{ !$loop->last ? ' ' : '' }}
              @endforeach
            </span>
          </div>
        @endforeach
      </div>
    </div>
    @endif

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
