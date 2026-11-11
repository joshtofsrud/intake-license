@extends('layouts.tenant.app')
@php $pageTitle = $rental->rental_number; @endphp

{{-- MARKER-RENTAL-BOOKING-STUDIO: one booking, in the Studio design — where it
     is in its life, the units and the money, what happened, and on the right
     the customer, the deposit hold and the quiet actions. Same routes and
     forms as before. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $late    = $rental->isOverdue();
  $balance = (int) $rental->total_cents - (int) $rental->paid_cents;
  $isRes   = $rental->status === 'reserved';
  $isOut   = $rental->status === 'out';
  $who     = $rental->customer?->fullName() ?: 'Walk-in';
  $tones   = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $tone    = $tones[crc32((string) ($rental->customer_id ?? $rental->id)) % 4];
  $ini     = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';
  $unitCount = $rental->lines->where('kind', 'unit')->count();
  $canDiscount = (int) ($rental->paid_cents ?? 0) === 0 && in_array($rental->status, ['reserved', 'out'], true);
  $hasDiscount = (int) ($rental->discount_cents ?? 0) > 0;

  // where it is in its life: real timestamps per stage, red when due-back was missed
  $missedDue = $rental->due_at && $rental->due_at->isPast() && $isOut;
  $stages = $rental->status === 'cancelled'
    ? [['t' => 'Reserved', 'at' => $rental->created_at, 'state' => 'hit'], ['t' => 'Cancelled', 'at' => $rental->cancelled_at, 'state' => 'bad']]
    : [
        ['t' => 'Reserved',    'at' => $rental->created_at,     'state' => 'hit'],
        ['t' => 'Checked out', 'at' => $rental->checked_out_at, 'state' => in_array($rental->status, ['out', 'returned'], true) ? 'hit' : 'next'],
        ['t' => 'Due back',    'at' => $rental->due_at,         'state' => $rental->status === 'returned' ? 'hit' : ($missedDue ? 'bad' : ($isOut ? 'now' : 'next'))],
        ['t' => 'Returned',    'at' => $rental->returned_at,    'state' => $rental->status === 'returned' ? 'hit' : 'next'],
      ];

  // live extension-offer state (only while it's out)
  $extPanel = null;
  if (tenant()->rental_extensions_enabled && $isOut && ! $rental->returned_at) {
      $extSvc = app(\App\Services\RentalExtensionOfferService::class);
      $extOffer = \App\Models\Tenant\TenantRentalExtensionOffer::where('rental_id', $rental->id)->orderByDesc('created_at')->first();
      $extReason = null;
      $extElig = ($extOffer && in_array($extOffer->status, ['sent', 'paid'], true)) ? null : $extSvc->eligibility(tenant(), $rental, $extReason);
      $extPanel = ['offer' => $extOffer, 'elig' => $extElig, 'reason' => $extReason];
  }
  $docChecks = $rental->conditionChecks->filter(fn ($c) => is_array($c->photos) && count($c->photos));
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.rentals.bookings.index') }}">Bookings</a> / {{ $rental->rental_number }}</div>
      <h1 class="sd-hi">{{ $who }}<span class="rf-hpill">@include('tenant.rentals._status-pill', ['rental' => $rental])</span></h1>
      <p class="sd-sub">{{ $rental->rental_number }} · {{ tlocal_datetime($rental->starts_at, 'M j, g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, g:i A') }} · {{ $unitCount }} {{ $unitCount === 1 ? 'unit' : 'units' }}</p>
    </div>
    <div class="sd-head-r">
      @if($canDiscount)<button type="button" class="ia-btn ia-btn--sm" data-disc-open>{{ $hasDiscount ? 'Change discount' : 'Discount' }}</button>@endif
      @if($isRes)<a href="{{ route('tenant.rentals.bookings.checkout.flow', $rental->id) }}" class="ia-btn ia-btn--sm ia-btn--primary">Check out →</a>
      @elseif($isOut)<a href="{{ route('tenant.rentals.bookings.return.flow', $rental->id) }}" class="ia-btn ia-btn--sm ia-btn--primary">Start return →</a>
      @elseif($rental->agreement_pdf_path)<a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" rel="noopener" class="ia-btn ia-btn--sm">Agreement PDF</a>
      @endif
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'bookings'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">

      {{-- life of the booking --}}
      <div class="rf-track rf-track-t sd-in">
        @foreach($stages as $i => $st)
          @php
            $cls = match ($st['state']) { 'hit' => 'done', 'now' => 'now', 'bad' => 'now late', default => '' };
          @endphp
          <div class="{{ $cls }}">
            <i>{{ $st['state'] === 'hit' ? '✓' : ($st['state'] === 'bad' ? '!' : $i + 1) }}</i>
            <span>{{ $st['t'] }}<small>{{ $st['at'] ? tlocal_datetime($st['at'], 'M j, g:i a') . ($st['state'] === 'bad' && $st['t'] === 'Due back' ? ' — missed' : '') : '—' }}</small></span>
          </div>
        @endforeach
      </div>

      <section class="rf-blk sd-in" style="--d:60ms">
        <div class="sd-sec-h"><div><h2>Units</h2><span>{{ $isRes ? 'Held for this window' : ($isOut ? 'With the customer' : ($rental->status === 'returned' ? 'Back in the fleet' : 'Released')) }}</span></div></div>
        @foreach($rental->lines as $line)
          @php
            $isUnit = $line->kind === 'unit';
            $u = $isUnit ? $line->unit : null;
            $thumbPhoto = $u?->photo_url ?: ($u?->model?->image_url);
            $t = crc32((string) $line->id) % 4;
            $lineChip = ! $isUnit ? null : match (true) {
              $rental->status === 'cancelled' => ['off', 'Released'],
              $rental->status === 'returned'  => ['done', 'Back'],
              $late                           => ['late', 'Overdue'],
              $isOut                          => ['out', 'Out'],
              default                         => ['res', 'Held for this'],
            };
          @endphp
          <div class="rf-line {{ $isUnit ? '' : 'addon' }}">
            <div class="rf-thumb" style="{{ $thumbPhoto ? "background-image:url('" . e($thumbPhoto) . "')" : 'background:linear-gradient(135deg,color-mix(in srgb,var(' . $tones[$t] . ') 70%,var(--ia-surface)),color-mix(in srgb,var(' . $tones[($t + 1) % 4] . ') 35%,var(--ia-surface)));color:var(--sd-ink)' }}">{{ $thumbPhoto ? '' : ($isUnit ? $ini($line->name_snapshot) : '+') }}</div>
            <div>
              <b>{{ $line->name_snapshot }}</b>
              <small>
                @if($u && ($u->identifier || $u->size))<span class="rf-mono">{{ $u->identifier ?: '—' }}</span>@if($u->size) · Size {{ $u->size }}@endif · @endif
                {{ $line->duration_units }} × {{ format_money($line->rate_cents_snapshot) }} ({{ $line->rate_mode_snapshot }})
              </small>
            </div>
            <div>@if($lineChip)<span class="rf-st {{ $lineChip[0] }}">{{ $lineChip[1] }}</span>@endif</div>
            <div class="amt">{{ format_money($line->line_total_cents) }}</div>
          </div>
        @endforeach

        <dl class="rf-tot">
          <dt>Subtotal</dt><dd>{{ format_money($rental->subtotal_cents) }}</dd>
          @if($hasDiscount)
            <dt>{{ $rental->discount_code ? 'Discount (' . $rental->discount_code . ')' : 'Discount' }}
              @if($canDiscount)
                <form method="POST" action="{{ route('tenant.rentals.bookings.discount.remove', $rental->id) }}" style="display:inline">@csrf @method('DELETE')<button type="submit" class="rf-linkbtn">remove</button></form>
              @endif
            </dt><dd>−{{ format_money($rental->discount_cents) }}</dd>
          @endif
          <dt>Tax</dt><dd>{{ format_money($rental->tax_cents) }}</dd>
          <dt class="big">Total</dt><dd>{{ format_money($rental->total_cents) }}</dd>
          <dt>Paid</dt><dd>{{ format_money($rental->paid_cents) }}</dd>
          <dt class="{{ $balance > 0 ? 'owe' : '' }}">Balance</dt><dd>{{ format_money(max(0, $balance)) }}</dd>
        </dl>
        @if($hasDiscount && (int) ($rental->paid_cents ?? 0) > 0)
          <p class="rf-fine" style="text-align:right">Payment has started, so the discount is locked. Refund first to change it.</p>
        @endif
      </section>

      <section class="rf-blk sd-in" style="--d:120ms">
        <div class="sd-sec-h"><div><h2>Payments</h2><span>Taken in the register</span></div></div>
        @forelse($rental->sales as $sale)
          <div class="rf-pay">
            <div>
              <b>{{ $sale->sale_number }} <span class="rf-st {{ $sale->payment_status === 'paid' ? 'done' : ($sale->payment_status === 'refunded' ? 'late' : 'off') }}">{{ strtolower($sale->payment_status) }}</span></b>
              @foreach($sale->payments as $p)
                <small>{{ tlocal_datetime($p->recorded_at, 'M j, g:i A') }} · {{ ucfirst($p->kind) }} · {{ $p->method ?? '—' }} · {{ $p->amount_cents < 0 ? '−' : '' }}{{ format_money(abs($p->amount_cents)) }}{{ $p->amount_cents < 0 ? ' refund' : '' }}</small>
              @endforeach
            </div>
            <span class="rf-mono">{{ format_money($sale->total_cents) }}</span>
            <span></span>
          </div>
        @empty
          <div class="sd-empty">No payments yet. Collecting opens the register with this booking's balance and links the sale back here.</div>
        @endforelse
        @if($rental->status !== 'cancelled' && $balance > 0)
          <form method="POST" action="{{ route('tenant.rentals.bookings.collect', $rental->id) }}" class="rf-collect">
            @csrf
            {{-- come back to this booking after payment --}}
            <input type="hidden" name="return_to" value="{{ parse_url(route('tenant.rentals.bookings.show', $rental->id), PHP_URL_PATH) }}">
            <div class="rf-money"><input class="ia-input" type="number" name="amount" min="0.01" step="0.01" required value="{{ number_format($balance / 100, 2, '.', '') }}" aria-label="Amount to collect"></div>
            <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Collect payment</button>
            <span class="rf-dim" style="font-size:11.5px">Opens a register sale — take cash or card, or send a payment link there. Refunds: open the sale in register history.</span>
          </form>
        @endif
      </section>

      <section class="rf-blk sd-in" style="--d:180ms">
        <div class="sd-sec-h"><div><h2>Activity</h2><span>Newest first</span></div></div>
        @if($feed->isEmpty())
          <div class="sd-empty">Nothing yet.</div>
        @else
          <div class="sd-tl rf-tl">
            @foreach($feed as $i => $ev)
              <div class="sd-tl-r {{ $ev['dot'] === 'red' ? 'late' : '' }}"><time>{{ tlocal_datetime($ev['at'], 'M j, g:i a') }}</time><div><b>{{ $ev['text'] }}</b></div></div>
            @endforeach
          </div>
        @endif
      </section>
    </div>

    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:100ms">
        <div class="rf-rh"><b>Customer</b>@if($rental->customer_id)<a href="{{ route('tenant.customers.show', $rental->customer_id) }}" class="sd-link">Open →</a>@endif</div>
        <div class="rf-person">
          <div class="sd-av" style="--c:var({{ $tone }})">{{ $ini($who) }}</div>
          <div><b>{{ $who }}</b>@if($rental->customer?->phone)<small>{{ $rental->customer->phone }}</small>@endif</div>
        </div>
        <dl class="rf-kv">
          @if($rental->customer?->email)<dt>Email</dt><dd>{{ $rental->customer->email }}</dd>@endif
          <dt>Customer since</dt><dd>{{ $rental->customer?->created_at && $priorRentals > 0 ? tlocal_date($rental->customer->created_at, 'M Y') : 'New customer' }}</dd>
          <dt>Rentals</dt><dd>{{ $priorRentals > 0 ? $priorRentals . ' before this' : 'First rental' }}</dd>
        </dl>

        {{-- deposit hold --}}
        <div class="rf-rh"><b>Deposit</b><span>{{ $rental->deposit_status === 'authorized' ? 'Authorised' : '' }}</span></div>
        @if($rental->deposit_status === 'authorized')
          <div class="rf-hold">
            <b>{{ format_money($rental->deposit_hold_cents) }} held</b>
            <small>On the customer's card · not charged</small>
            <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.release', $rental->id) }}" style="display:inline">@csrf<button type="submit" class="ia-btn ia-btn--sm">Release</button></form>
            <details class="rf-capture">
              <summary class="ia-btn ia-btn--sm">Capture…</summary>
              <form method="POST" action="{{ route('tenant.rentals.bookings.deposit.capture', $rental->id) }}" data-confirm-title="Capture from the customer's card?" data-confirm-msg="This charges the amount you entered to the card that holds the deposit." data-confirm-yes="Capture">
                @csrf
                <div class="rf-money"><input class="ia-input" type="number" name="amount" min="0.50" step="0.01" max="{{ number_format($rental->deposit_hold_cents / 100, 2, '.', '') }}" placeholder="Amount" required aria-label="Amount to capture"></div>
                <input class="ia-input" type="text" name="reason" maxlength="500" placeholder="Reason (shows on the sale)" aria-label="Reason">
                <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Capture</button>
              </form>
            </details>
            <p class="rf-fine">Release = no charge, no ledger entry. Capture = a damage charge through the register ledger.</p>
          </div>
        @elseif($rental->deposit_status === 'released')
          <div class="rf-hold"><b>Released</b><small>Hold released — no charge.</small></div>
        @elseif(in_array($rental->deposit_status, ['captured', 'partially_captured'], true))
          <div class="rf-hold"><b>{{ $rental->deposit_status === 'captured' ? 'Fully captured' : 'Partly captured' }}</b><small>See the linked sale under Payments.</small></div>
        @elseif(in_array($rental->status, ['reserved', 'out'], true))
          @if(tenant()->direct_payments_enabled)
            <div class="rf-hold" id="dep-start">
              <b>No hold yet</b>
              <small>It's usually placed at check-out; you can place it now if they've paid ahead. Authorises the card without charging it.</small>
              <div class="rf-collect" style="margin:0">
                <div class="rf-money"><input class="ia-input" type="number" id="dep-amount" min="0.50" step="0.01" value="{{ number_format(max(0, $rental->lines->where('kind', 'unit')->sum(fn ($l) => (int) ($l->unit?->effectiveDepositCents() ?? 0))) / 100, 2, '.', '') }}" aria-label="Deposit amount"></div>
                <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" id="dep-authorize">Authorize hold</button>
              </div>
            </div>
            <div id="dep-element-wrap" style="display:none;margin-top:10px">
              <div id="dep-element"></div>
              <button type="button" class="ia-btn ia-btn--primary rf-wide" id="dep-confirm" style="margin-top:8px">Place hold</button>
              <p class="rf-err" id="dep-error"></p>
            </div>
          @else
            <div class="sd-empty">Turn on card payments in Settings → Payments to take deposit holds.</div>
          @endif
        @else
          <div class="sd-empty">No deposit was held on this rental.</div>
        @endif

        @if($extPanel)
          <div class="rf-rh"><b>Last-minute extension</b><span>{{ $extPanel['offer'] && $extPanel['offer']->status === 'sent' ? 'Sent' : ($extPanel['offer'] && $extPanel['offer']->status === 'paid' ? 'Accepted' : '') }}</span></div>
          <div class="rf-offer">
            @if($extPanel['offer'] && $extPanel['offer']->status === 'paid')
              <p>Accepted and paid {{ format_money($extPanel['offer']->total_cents) }} — extended to {{ tlocal_datetime($extPanel['offer']->extend_to, 'g:i A') }}.</p>
            @elseif($extPanel['offer'] && $extPanel['offer']->status === 'sent')
              <p>Offer sent {{ tlocal_datetime($extPanel['offer']->sent_at, 'g:i A') }} — waiting on an answer. Extends to {{ tlocal_datetime($extPanel['offer']->extend_to, 'g:i A') }} for {{ format_money($extPanel['offer']->total_cents) }}.</p>
            @elseif($extPanel['elig'])
              <p>Eligible — the unit is free until {{ tlocal_datetime($extPanel['elig']['extend_to'], 'g:i A') }}. Offer price {{ format_money($extPanel['elig']['total_cents']) }} ({{ $extPanel['elig']['discount_pct'] }}% off).</p>
              <form method="POST" action="{{ route('tenant.rentals.bookings.extension.send', $rental->id) }}">@csrf<button type="submit" class="ia-btn ia-btn--sm">Send offer now</button></form>
            @else
              <p>Not eligible: {{ $extPanel['reason'] ?? ($extPanel['offer'] ? ucfirst($extPanel['offer']->status) . ' offer on file.' : 'n/a') }}</p>
            @endif
          </div>
        @endif

        @if($rental->agreement_pdf_path || $docChecks->isNotEmpty())
          <div class="rf-rh"><b>Documents</b></div>
          <div class="rf-docs">
            @if($rental->agreement_pdf_path)
              <a href="{{ Storage::disk('public')->url($rental->agreement_pdf_path) }}" target="_blank" rel="noopener"><b>Rental agreement</b><span>v{{ $rental->agreement_template_version }} · signed</span></a>
            @endif
            @foreach($docChecks as $check)
              <div class="rf-doc-row">
                <b>{{ $check->phase === 'check_out' ? 'Out-check' : 'In-check' }} — {{ $check->unit?->identifier ?: 'unit' }}</b>
                <span>@foreach($check->photos as $pi => $p)<a href="{{ Storage::disk('public')->url($p) }}" target="_blank" rel="noopener" aria-label="Photo {{ $pi + 1 }}">{{ $pi + 1 }}</a>@endforeach</span>
              </div>
            @endforeach
          </div>
        @endif

        @if($rental->notes)
          <div class="rf-rh"><b>Notes</b></div>
          <p class="rf-note-text">{{ $rental->notes }}</p>
        @endif

        @if($isRes || $isOut)
          <div class="rf-rh"><b>More</b></div>
          <div class="rf-quiet">
            @if($isRes)
              <form method="POST" action="{{ route('tenant.rentals.bookings.checkout', $rental->id) }}" data-confirm-title="Quick check out?" data-confirm-msg="This skips the agreement, condition check and deposit steps." data-confirm-yes="Check out">@csrf<button type="submit">Quick check out — skip the flow</button></form>
              <form method="POST" action="{{ route('tenant.rentals.bookings.cancel', $rental->id) }}" data-confirm-title="Cancel this reservation?" data-confirm-msg="The units are released for other bookings." data-confirm-yes="Cancel reservation" data-confirm-danger="1">@csrf<button type="submit" class="bad">Cancel reservation</button></form>
            @else
              <form method="POST" action="{{ route('tenant.rentals.bookings.checkin', $rental->id) }}" data-confirm-title="Quick check in?" data-confirm-msg="This skips the inspection and charges. A clean check-in releases any deposit hold." data-confirm-yes="Check in">@csrf<button type="submit">Quick check in — skip the flow</button></form>
            @endif
          </div>
        @endif
      </aside>
    </div>
  </div>

  <p class="sd-legend">Payments are taken in the register and land on this booking's ledger, so the balance here always matches the register. A deposit hold authorises the customer's card without charging it; nothing is taken unless you capture it.</p>
</div>

{{-- discount dialog (in-app, no browser prompts) --}}
@if($canDiscount)
<div class="ia-confirm-backdrop" id="discDlg" hidden>
  <div class="ia-confirm-card" role="dialog" aria-modal="true" aria-labelledby="discT" style="max-width:420px">
    <div class="ia-confirm-title" id="discT">Discount this rental</div>
    <div class="ia-confirm-message">Applies to the whole rental, before tax. Damage and late charges added later are not discounted.</div>
    <form method="POST" action="{{ route('tenant.rentals.bookings.discount.apply', $rental->id) }}" class="rf-disc-f">
      @csrf
      <label><span class="rf-lbl">Discount code</span><input class="ia-input" type="text" name="code" placeholder="SPRING20" autocapitalize="characters"></label>
      <button type="submit" name="mode" value="code" class="ia-btn ia-btn--primary rf-wide">Apply code</button>
    </form>
    <div class="rf-or"><i></i>or<i></i></div>
    <form method="POST" action="{{ route('tenant.rentals.bookings.discount.apply', $rental->id) }}" class="rf-disc-f">
      @csrf
      <div class="rf-disc-row">
        <label><span class="rf-lbl">Amount off</span><input class="ia-input" type="text" name="amount" placeholder="0.00" inputmode="decimal"></label>
        <label><span class="rf-lbl">Or percent</span><input class="ia-input" type="text" name="percent" placeholder="10" inputmode="decimal"></label>
      </div>
      <div class="ia-confirm-actions">
        <button type="button" class="ia-confirm-btn" data-disc-close>Cancel</button>
        <button type="submit" name="mode" value="amount" class="ia-confirm-btn">Apply amount</button>
        <button type="submit" name="mode" value="percent" class="ia-confirm-btn ia-confirm-btn--primary">Apply percent</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection

@push('scripts')
@if($rental->deposit_status === 'none' && in_array($rental->status, ['reserved', 'out'], true) && tenant()->direct_payments_enabled)
<script src="https://js.stripe.com/v3/"></script>
@endif
<script>
(function () {
  'use strict';
  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() { var l = innerHeight * 0.92; $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < l) el.classList.add('is-in'); }); }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = document.getElementById('sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();

  // quiet actions and capture confirm in an in-app dialog, then submit for real
  $$('form[data-confirm-title]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.ok) return;
      e.preventDefault();
      IntakeConfirm.show({ title: f.dataset.confirmTitle, message: f.dataset.confirmMsg || '', confirmText: f.dataset.confirmYes || 'OK', danger: !!f.dataset.confirmDanger })
        .then(function (ok) { if (ok) { f.dataset.ok = '1'; f.submit(); } });
    });
  });

  // discount dialog
  var dlg = document.getElementById('discDlg');
  function dOpen() { if (!dlg) return; dlg.hidden = false; requestAnimationFrame(function () { dlg.classList.add('is-shown'); var i = $('input', dlg); if (i) i.focus(); }); }
  function dClose() { if (!dlg) return; dlg.classList.remove('is-shown'); setTimeout(function () { dlg.hidden = true; }, 160); }
  $$('[data-disc-open]').forEach(function (b) { b.addEventListener('click', dOpen); });
  if (dlg) {
    dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.closest('[data-disc-close]')) dClose(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !dlg.hidden) dClose(); });
  }

  // deposit hold (Stripe): authorise → card element → place hold → verify
  var btn = document.getElementById('dep-authorize');
  if (btn) {
    var intentUrl  = '{{ route('tenant.rentals.bookings.deposit.intent', $rental->id) }}';
    var confirmUrl = '{{ route('tenant.rentals.bookings.deposit.confirm', $rental->id) }}';
    var csrf = '{{ csrf_token() }}';
    var stripe = null, elements = null, piId = null;
    function post(url, payload) {
      return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload || {}) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
    }
    function fail(msg) { IntakeConfirm.alert({ title: 'Deposit hold', message: msg }); }
    btn.addEventListener('click', function () {
      if (typeof Stripe === 'undefined') { fail("The card form didn't load — check your connection and reload the page."); return; }
      btn.disabled = true;
      var dollars = parseFloat(document.getElementById('dep-amount').value || '0');
      post(intentUrl, { amount_cents: Math.round(dollars * 100) }).then(function (res) {
        if (!res.ok || !res.json.ok) { fail(res.json.error || 'Could not start the hold.'); btn.disabled = false; return; }
        piId = res.json.payment_intent;
        stripe = Stripe(res.json.publishable_key);
        elements = stripe.elements({ clientSecret: res.json.client_secret });
        elements.create('payment').mount('#dep-element');
        document.getElementById('dep-element-wrap').style.display = 'block';
      }).catch(function () { fail('Could not start the hold.'); btn.disabled = false; });
    });
    document.getElementById('dep-confirm').addEventListener('click', function () {
      var c = this; c.disabled = true;
      document.getElementById('dep-error').textContent = '';
      stripe.confirmPayment({ elements: elements, redirect: 'if_required' }).then(function (result) {
        if (result.error) { document.getElementById('dep-error').textContent = result.error.message || 'Card was not authorized.'; c.disabled = false; return; }
        post(confirmUrl, { payment_intent: piId }).then(function (res) {
          if (res.ok && res.json.ok) location.reload();
          else { document.getElementById('dep-error').textContent = (res.json && res.json.error) || 'Could not verify the hold.'; c.disabled = false; }
        });
      });
    });
  }
})();
</script>
@endpush
