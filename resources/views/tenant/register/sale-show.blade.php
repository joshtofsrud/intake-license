@extends('layouts.tenant.app')
@php $pageTitle = 'Sale ' . $sale->sale_number; @endphp

{{-- MARKER-SALE-PAGE-STUDIO: one register sale, in the Studio design. Left:
     what the sale was for (work order / rental / lease and where it stands),
     the items, payments and refunds. Right rail: the customer, a message box
     and the record's other sales. --}}

@push('styles')
  @foreach (['dashboard-simple', 'dashboard-studio', 'rentals-studio'] as $slCss)
    <link rel="stylesheet" href="{{ asset('css/tenant/'.$slCss.'.css') }}?v={{ filemtime(public_path('css/tenant/'.$slCss.'.css')) }}">
  @endforeach
<style>
  .sl .sl-for{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 16px;align-items:start}
  .sl .sl-for h3{margin:0;font-size:15px;font-weight:600}
  .sl .sl-for h3 a{color:inherit;text-decoration:none}
  .sl .sl-for h3 a:hover{text-decoration:underline}
  .sl .sl-for p{margin:2px 0 0;font-size:12.5px;color:var(--ia-text-muted)}
  .sl .sl-money{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
  .sl .sl-money div{padding:10px 12px;border-radius:var(--ia-r-md,10px);background:color-mix(in srgb,var(--ia-text) 4%,transparent)}
  .sl .sl-money small{display:block;font-size:11px;color:var(--ia-text-muted);margin-bottom:2px}
  .sl .sl-money b{font:600 14px var(--ia-font-mono);font-variant-numeric:tabular-nums}
  .sl .sl-money .owe b{color:#E0573E}
  .sl .sl-money .clear b{color:var(--ia-success,#3BA776)}
  .sl .sl-line{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:10px 6px;border-bottom:1px solid var(--ia-border)}
  .sl .sl-line b{display:block;font-size:13px;font-weight:600}
  .sl .sl-line small{font-size:11.5px;color:var(--ia-text-muted)}
  .sl .sl-line .amt{font:500 12.5px var(--ia-font-mono);text-align:right}
  .sl .sl-link{color:var(--ia-text);font-weight:600;text-decoration:underline;text-decoration-color:var(--ia-accent);text-decoration-thickness:2px;text-underline-offset:3px}
  .sl .sl-link:hover{text-decoration-color:currentColor}
  .sl .sl-sib{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--ia-border);font-size:12.5px}
  .sl .sl-sib:last-child{border-bottom:0}
  .sl .sl-sib .rf-mono{font-size:12px}
  .sl .sl-msg textarea{width:100%;resize:vertical;min-height:64px}
  .sl .sl-msg-row{display:flex;gap:8px;align-items:center;justify-content:space-between;margin-top:8px}
  .sl .sl-msg-row select{width:auto;font-size:12px;padding:4px 8px}
  .sl .sl-hint{font-size:11.5px;color:var(--ia-text-muted);margin:6px 0 0}
  @media (max-width:1023px){ .sl .sl-money{grid-template-columns:repeat(3,minmax(0,1fr))} }
  @media (max-width:560px){ .sl .sl-money{grid-template-columns:1fr 1fr} }
</style>
@endpush

@section('content')
@php
  $cust    = $sale->customer;
  $who     = $cust ? (trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')) ?: ($cust->business_name ?? 'Customer')) : 'Walk-in';
  $tones   = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $tone    = $tones[crc32((string) ($sale->customer_id ?? $sale->id)) % 4];
  $ini     = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2)) ?: '?';

  // sale_date is a calendar date (no time), so it's shown as-is. Running it
  // through the tenant-timezone helper read it as UTC midnight and showed
  // the day before for US shops.
  $saleDay = $sale->sale_date ? $sale->sale_date->format('M j, Y') : tlocal_date($sale->created_at);

  $stTone = match($sale->payment_status) {
    'paid', 'overage'           => 'done',
    'refunded', 'partial_refund'=> 'late',
    default                     => 'off',
  };

  // what this sale was for
  $for = null;
  if ($appt) {
      $apptDay = $appt->appointment_date ? $appt->appointment_date->format('D, M j, Y') : null;
      $apptTime = $appt->appointment_time ? \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') : null;
      $paid = (int) $appt->paid_cents; $total = (int) $appt->total_cents;
      $for = [
        'kind'   => 'Work order',
        'title'  => $appt->portalTitle(),
        'number' => $appt->ra_number,
        'url'    => route('tenant.appointments.show', $appt->id),
        'open'   => 'Open work order',
        'status' => \App\Support\AppointmentStatus::label((string) $appt->status),
        'when'   => trim(($apptDay ?? '') . ($apptTime ? ' · ' . $apptTime : '')),
        'total'  => $total, 'paid' => $paid, 'balance' => $total - $paid,
        'tlabel' => 'Work order total',
      ];
  } elseif ($linkedRental) {
      $paid = (int) $linkedRental->paid_cents; $total = (int) $linkedRental->total_cents;
      $for = [
        'kind'   => 'Rental',
        'title'  => 'Rental ' . $linkedRental->rental_number,
        'number' => $linkedRental->rental_number,
        'url'    => route('tenant.rentals.bookings.show', $linkedRental->id),
        'open'   => 'Open rental',
        'status' => ucfirst(str_replace('_', ' ', (string) $linkedRental->status)),
        'when'   => $linkedRental->starts_at ? tlocal_datetime($linkedRental->starts_at, 'M j, g:i A') . ($linkedRental->due_at ? ' → ' . tlocal_datetime($linkedRental->due_at, 'M j, g:i A') : '') : '',
        'total'  => $total, 'paid' => $paid, 'balance' => $total - $paid,
        'tlabel' => 'Rental total',
      ];
  } elseif ($linkedLease) {
      $paid = (int) $linkedLease->paid_cents; $total = (int) $linkedLease->total_cents;
      $for = [
        'kind'   => 'Lease',
        'title'  => ($linkedLease->package_name_snapshot ?: 'Lease') . ' · ' . $linkedLease->lease_number,
        'number' => $linkedLease->lease_number,
        'url'    => route('tenant.rentals.leases.show', $linkedLease->id),
        'open'   => 'Open lease',
        'status' => ucfirst(str_replace('_', ' ', (string) $linkedLease->status)),
        'when'   => $linkedLease->season_start ? tlocal_date($linkedLease->season_start) . ($linkedLease->season_end ? ' → ' . tlocal_date($linkedLease->season_end) : '') : '',
        'total'  => $total, 'paid' => $paid, 'balance' => $total - $paid,
        'tlabel' => 'Lease total',
      ];
  }
  $canMessage = $inboxOn && $cust && ($cust->phone || $cust->email);
  $msgDefault = $cust?->phone && ! $cust?->sms_opt_out_at ? 'sms' : 'email';
@endphp

<div class="sd rf sl" id="sd">
  <header class="sd-head">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.register.history.index') }}">Sales</a> / {{ $sale->sale_number }}</div>
      <h1 class="sd-hi">{{ $sale->sale_number }}<span class="rf-hpill"><span class="rf-st {{ $stTone }}">{{ ucfirst(str_replace('_', ' ', (string) $sale->payment_status)) }}</span></span></h1>
      <p class="sd-sub">{{ $saleDay }}@if($sale->location) · {{ $sale->location->name }}@endif @if($sale->rangUpBy) · Rung up by {{ $sale->rangUpBy->name }}@endif · {{ $who }}</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.register.sales.receipt', $sale->id) }}" target="_blank" rel="noopener" class="ia-btn ia-btn--sm">Print receipt</a>
      @if($for)<a href="{{ $for['url'] }}" class="ia-btn ia-btn--sm ia-btn--primary">{{ $for['open'] }} →</a>@endif
    </div>
  </header>

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  <div class="dash-row">
    <div class="dash-col" style="grid-column:span 8">

      @if($for)
        <section class="rf-blk sd-in">
          <div class="sd-sec-h"><div><h2>What this was for</h2><span>{{ $for['kind'] }}</span></div></div>
          <div class="sl-for">
            <div>
              <h3><a href="{{ $for['url'] }}">{{ $for['title'] }}</a></h3>
              <p><a href="{{ $for['url'] }}" class="sl-link rf-mono">{{ $for['number'] }}</a>@if($for['when']) · {{ $for['when'] }}@endif</p>
            </div>
            <span class="rf-st off">{{ $for['status'] }}</span>
          </div>
          <div class="sl-money">
            <div><small>{{ $for['tlabel'] }}</small><b>{{ format_money($for['total']) }}</b></div>
            <div><small>Paid so far</small><b>{{ format_money($for['paid']) }}</b></div>
            <div class="{{ $for['balance'] > 0 ? 'owe' : 'clear' }}"><small>{{ $for['balance'] < 0 ? 'Overpaid' : 'Balance due' }}</small><b>{{ format_money(abs($for['balance'])) }}</b></div>
          </div>
        </section>
      @endif

      @if($sale->refundOf)
        <section class="rf-blk sd-in">
          <div class="sd-sec-h"><div><h2>Refund</h2><span>This sale refunds another</span></div></div>
          <div class="sl-line"><div><b>Refund of <a href="{{ route('tenant.register.sales.page', $sale->refund_of_sale_id) }}" class="sl-link rf-mono">{{ $sale->refundOf->sale_number }}</a></b></div><div></div></div>
        </section>
      @endif

      <section class="rf-blk sd-in" style="--d:60ms">
        <div class="sd-sec-h"><div><h2>Items</h2><span>{{ $sale->items->count() }} {{ Str::plural('line', $sale->items->count()) }}</span></div></div>
        @forelse($sale->items->sortBy('position') as $item)
          <div class="sl-line">
            <div>
              <b>{{ $item->name_snapshot }}</b>
              @if($item->quantity > 1)<small>{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} × {{ format_money($item->unit_price_cents) }}</small>@endif
            </div>
            <div class="amt">{{ format_money($item->line_total_cents) }}</div>
          </div>
        @empty
          <div class="sd-empty">No line items.</div>
        @endforelse
        <dl class="rf-tot">
          <dt>Subtotal</dt><dd>{{ format_money($sale->subtotal_cents) }}</dd>
          @if($sale->discount_cents)<dt>Discount</dt><dd>−{{ format_money($sale->discount_cents) }}</dd>@endif
          @if($sale->tax_cents)<dt>Tax</dt><dd>{{ format_money($sale->tax_cents) }}</dd>@endif
          <dt class="big">Total</dt><dd>{{ format_money($sale->total_cents) }}</dd>
        </dl>
      </section>

      <section class="rf-blk sd-in" style="--d:120ms">
        <div class="sd-sec-h"><div><h2>Payments</h2><span>@if($sale->paid_at)Paid {{ tlocal_datetime($sale->paid_at, 'M j, Y g:i A') }}@else Not paid yet @endif</span></div></div>
        @forelse($sale->payments as $p)
          <div class="rf-pay">
            <div>
              <b>{{ ucfirst(str_replace('_', ' ', $p->kind ?? 'payment')) }}</b>
              <small>{{ tlocal_datetime($p->recorded_at ?? $p->created_at, 'M j, Y g:i A') }} · {{ $p->methodLabel() }}@if($loop->first && $sale->card_last4) · {{ $sale->card_brand ? ucfirst($sale->card_brand) . ' ' : '' }}····{{ $sale->card_last4 }}@endif</small>
            </div>
            <span class="rf-mono" style="{{ ($p->amount_cents ?? 0) < 0 ? 'color:#E0573E' : '' }}">{{ ($p->amount_cents ?? 0) < 0 ? '−' : '' }}{{ format_money(abs($p->amount_cents ?? 0)) }}</span>
            <span></span>
          </div>
        @empty
          <div class="sd-empty">No payments recorded on this sale.</div>
        @endforelse
      </section>

      @if($refunds->count())
        <section class="rf-blk sd-in" style="--d:180ms">
          <div class="sd-sec-h"><div><h2>Refunds</h2><span>Issued against this sale</span></div></div>
          @foreach($refunds as $r)
            <div class="rf-pay">
              <div><b><a href="{{ route('tenant.register.sales.page', $r->id) }}" class="sl-link rf-mono">{{ $r->sale_number }}</a></b><small>{{ tlocal_date($r->created_at) }}</small></div>
              <span class="rf-mono" style="color:#E0573E">{{ format_money($r->total_cents) }}</span>
              <span></span>
            </div>
          @endforeach
        </section>
      @endif
    </div>

    <div class="dash-col" style="grid-column:span 4">
      <aside class="sd-rail rf-rail sd-in" style="--d:100ms">
        <div class="rf-rh"><b>Customer</b>@if($cust)<a href="{{ route('tenant.customers.show', $cust->id) }}" class="sd-link">Open →</a>@endif</div>
        <div class="rf-person">
          <div class="sd-av" style="--c:var({{ $tone }})">{{ $ini($who) }}</div>
          <div>
            <b>@if($cust)<a href="{{ route('tenant.customers.show', $cust->id) }}" style="color:inherit">{{ $who }}</a>@else{{ $who }}@endif</b>
            @if($cust?->phone)<small>{{ $cust->phone }}</small>@endif
          </div>
        </div>
        @if($cust)
          <dl class="rf-kv">
            @if($cust->email)<dt>Email</dt><dd>{{ $cust->email }}</dd>@endif
            <dt>Sales</dt><dd>{{ $customerSales }} {{ Str::plural('sale', $customerSales) }}</dd>
            @if($cust->created_at)<dt>Customer since</dt><dd>{{ tlocal_date($cust->created_at, 'M Y') }}</dd>@endif
          </dl>
        @else
          <div class="sd-empty">No customer on this sale.</div>
        @endif

        @if($canMessage)
          <div class="rf-rh"><b>Message {{ $cust->first_name ?: 'customer' }}</b></div>
          <form method="POST" action="{{ route('tenant.inbox.start') }}" class="sl-msg">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $cust->id }}">
            <input type="hidden" name="about_type" value="sale">
            <input type="hidden" name="about_id" value="{{ $sale->id }}">
            <input type="hidden" name="stay" value="1">
            <textarea name="body" class="ia-input" maxlength="1200" required placeholder="About this sale&hellip;" aria-label="Message">{{ old('body') }}</textarea>
            <div class="sl-msg-row">
              <select name="channel" class="ia-input" aria-label="Send via">
                @if($cust->phone && ! $cust->sms_opt_out_at)<option value="sms" @selected($msgDefault === 'sms')>Text</option>@endif
                @if($cust->email)<option value="email" @selected($msgDefault === 'email')>Email</option>@endif
              </select>
              <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary">Send</button>
            </div>
            <p class="sl-hint">Lands in the inbox tagged with this sale.</p>
          </form>
        @endif

        @if($for)
          <div class="rf-rh"><b>Other sales on this {{ strtolower($for['kind']) }}</b></div>
          @forelse($siblings as $sib)
            <div class="sl-sib">
              <a href="{{ route('tenant.register.sales.page', $sib->id) }}" class="sl-link rf-mono">{{ $sib->sale_number }}</a>
              <span>{{ $sib->sale_date ? $sib->sale_date->format('M j') : tlocal_date($sib->created_at, 'M j') }} · <span class="rf-mono">{{ format_money($sib->total_cents) }}</span></span>
            </div>
          @empty
            <div class="sd-empty">This is the only sale on it so far.</div>
          @endforelse
        @endif
      </aside>
    </div>
  </div>
</div>
@endsection
