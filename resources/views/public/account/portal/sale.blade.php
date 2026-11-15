@extends('public.account._shell')
@php
  // MARKER-PORTAL-DETAIL
  $isRefund  = $sale->isRefund();
  $kind      = $isRefund ? 'Refund' : 'Receipt';
  $pageTitle = $kind . ($sale->sale_number ? ' #' . $sale->sale_number : '');
  $sym   = tenant()->currency_symbol ?: '$';
  $money = fn ($c) => ((int) $c < 0 ? '-' : '') . $sym . number_format(abs((int) $c) / 100, 2);
  $when  = $sale->paid_at ? tlocal_datetime($sale->paid_at, 'D, M j, Y \a\t g:i A')
         : ($sale->sale_date ? $sale->sale_date->format('D, M j, Y') : tlocal_datetime($sale->created_at, 'D, M j, Y'));
  $payPill = [
    'paid' => ['paid', 'Paid'], 'partial' => ['due', 'Partly paid'],
    'unpaid' => ['due', 'Unpaid'], 'refunded' => ['refunded', 'Refunded'],
  ][$sale->payment_status] ?? [null, ucfirst((string) $sale->payment_status)];
  $paidSum = (int) $sale->payments->sum('amount_cents');
  $due     = (int) $sale->total_cents - $paidSum;
@endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'orders'])

<a href="{{ $workOrder ? route('tenant.customer.portal.work_order', $workOrder->id) : route('tenant.customer.portal.orders') }}" class="ac-back">&larr; {{ $workOrder ? 'Work order ' . $workOrder->ra_number : 'All orders' }}</a>

@if(session('success'))
  <div class="ac-flash ac-flash--success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="ac-flash ac-flash--error">{{ $errors->first() }}</div>
@endif

<div class="ac-dhead">
  <div>
    <div class="ac-dtitle">{{ $pageTitle }}</div>
    <div class="ac-dsub">{{ $when }}
      @if($payPill[0]) &middot; <span class="ac-pill ac-pill--{{ $payPill[0] }}">{{ $payPill[1] }}</span>@endif
    </div>
  </div>
  <div class="ac-dact">
    <a href="{{ route('tenant.customer.portal.sale.print', $sale->id) }}" target="_blank" rel="noopener">Print</a>
    <a href="#conversation">Message</a>
  </div>
</div>

<dl class="ac-kv">
  @if($sale->location)<dt>Location</dt><dd>{{ $sale->location->name }}</dd>@endif
  @if($workOrder)<dt>Work order</dt><dd><a class="ac-link" href="{{ route('tenant.customer.portal.work_order', $workOrder->id) }}">{{ $workOrder->ra_number ?: 'View work order' }}</a></dd>@endif
  @if($original)<dt>Refund of</dt><dd><a class="ac-link" href="{{ route('tenant.customer.portal.sale', $original->id) }}">Receipt {{ $original->sale_number ? '#' . $original->sale_number : '' }}</a></dd>@endif
  @if($sale->po_number)<dt>PO number</dt><dd>{{ $sale->po_number }}</dd>@endif
  @if($sale->tax_exempt_applied)<dt>Tax</dt><dd>Tax exempt @if($sale->tax_exempt_certificate)({{ $sale->tax_exempt_certificate }})@endif</dd>@endif
</dl>

<div class="ac-section-title">Items</div>
<div class="ac-list">
  @forelse($sale->items->sortBy('position') as $it)
    @php $qty = rtrim(rtrim(number_format((float) $it->quantity, 3), '0'), '.'); @endphp
    <div class="ac-line">
      <div>
        <div>{{ $it->name_snapshot }}</div>
        <div class="ac-line-m">
          {{ $qty }} &times; {{ $money($it->unit_price_cents) }}
          @if((int) $it->discount_cents > 0) &middot; {{ $money($it->discount_cents) }} off @endif
          @if($it->description_snapshot) &middot; {{ \Illuminate\Support\Str::limit($it->description_snapshot, 80) }}@endif
        </div>
      </div>
      <div class="ac-line-r">{{ $money($it->line_total_cents) }}</div>
    </div>
  @empty
    <div class="ac-empty">No items on this receipt</div>
  @endforelse
</div>

<div class="ac-list">
  <div class="ac-tot"><span>Subtotal</span><span>{{ $money($sale->subtotal_cents) }}</span></div>
  @if((int) $sale->discount_cents > 0)<div class="ac-tot"><span>Item discounts</span><span>-{{ $money($sale->discount_cents) }}</span></div>@endif
  @if((int) ($sale->sale_discount_cents ?? 0) > 0)<div class="ac-tot"><span>Discount</span><span>-{{ $money($sale->sale_discount_cents) }}</span></div>@endif
  @if((int) $sale->tax_cents !== 0)<div class="ac-tot"><span>Tax</span><span>{{ $money($sale->tax_cents) }}</span></div>@endif
  @if((int) $sale->surcharge_cents > 0)<div class="ac-tot"><span>Surcharge</span><span>{{ $money($sale->surcharge_cents) }}</span></div>@endif
  @if((int) $sale->tip_cents > 0)<div class="ac-tot"><span>Tip</span><span>{{ $money($sale->tip_cents) }}</span></div>@endif
  <div class="ac-tot ac-tot--grand"><span>Total</span><span>{{ $money($sale->total_cents) }}</span></div>
  @if(! $isRefund && $due > 0 && in_array($sale->payment_status, ['unpaid', 'partial'], true))
    <div class="ac-tot ac-tot--due"><span>Balance due</span><span>{{ $money($due) }}</span></div>
  @endif
</div>

@if($sale->payments->isNotEmpty())
  <div class="ac-section-title">Payments</div>
  <div class="ac-list">
    @foreach($sale->payments->sortBy('recorded_at') as $p)
      <div class="ac-line">
        <div><div>{{ $p->isRefund() ? 'Refund' : 'Payment' }} &middot; {{ $p->methodLabel() }}@if(in_array($p->method, ['card', 'card_terminal', 'stripe'], true) && $sale->card_last4) &middot; {{ $sale->card_brand ? ucfirst($sale->card_brand) . ' ' : '' }}&bull;&bull;&bull;&bull; {{ $sale->card_last4 }}@endif</div>
          <div class="ac-line-m">{{ $p->recorded_at ? tlocal_datetime($p->recorded_at, 'M j, Y g:i A') : '' }}</div></div>
        <div class="ac-line-r">{{ $money($p->amount_cents) }}</div>
      </div>
    @endforeach
  </div>
@endif

@if($refunds->isNotEmpty())
  <div class="ac-section-title">Refunds</div>
  <div class="ac-list">
    @foreach($refunds as $r)
      <a class="ac-list-row" href="{{ route('tenant.customer.portal.sale', $r->id) }}">
        <div><div class="ac-list-name">Refund {{ $r->sale_number ? '#' . $r->sale_number : '' }}</div>
          <div class="ac-list-meta">{{ tlocal_date($r->paid_at ?? $r->created_at) }}</div></div>
        <div class="ac-list-right"><span style="font-weight:700">{{ $money($r->total_cents) }}</span><span class="ac-go">&rsaquo;</span></div>
      </a>
    @endforeach
  </div>
@endif

@if($specialOrders->isNotEmpty())
  <div class="ac-section-title">Special orders</div>
  <div class="ac-list">
    @foreach($specialOrders as $so)
      <div class="ac-line">
        <div><div>{{ $so->item_name_snapshot }}@if((int) $so->quantity > 1) &times; {{ (int) $so->quantity }}@endif</div>
          <div class="ac-line-m">
            @if($so->arrived_at) Arrived {{ tlocal_date($so->arrived_at, 'M j') }}
            @elseif($so->expected_arrival_date) Expected {{ $so->expected_arrival_date->format('M j') }}
            @endif
          </div></div>
        <div class="ac-line-r"><span class="ac-pill ac-pill--{{ $so->status === 'arrived' || $so->status === 'pulled' ? 'paid' : 'pending' }}">{{ $so->statusLabel() }}</span></div>
      </div>
    @endforeach
  </div>
@endif

@include('public.account.portal._record-conversation', [
  'conversation' => $conversation,
  'action'       => route('tenant.customer.portal.sale.message', $sale->id),
  'aboutLabel'   => 'this ' . strtolower($kind),
])
@endsection
