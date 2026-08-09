@extends('public.account._shell')
@php $pageTitle = 'Orders'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'orders'])

<div class="ac-section-title">Online orders</div>
<div class="ac-list">
  @forelse($onlineOrders as $o)
    <a class="ac-list-row" href="{{ route('tenant.order.confirmation', $o->token) }}" style="text-decoration:none">
      <div><div class="ac-list-name">{{ $o->order_number }}</div>
        <div class="ac-list-meta">{{ (int) $o->items->sum('quantity') }} {{ \Illuminate\Support\Str::plural('item', (int) $o->items->sum('quantity')) }} &middot; {{ $o->fulfillment_type === 'local_delivery' ? 'delivery' : 'pickup' }} &middot; {{ tlocal_date($o->created_at) }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($o->total_cents / 100, 2) }}</div>
        <div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $o->status) }}</div></div>
    </a>
  @empty
    <div class="ac-empty">No online orders yet</div>
  @endforelse
</div>

{{-- MARKER-PORTAL-V2 — in-store purchase history off tenant_sales --}}
<div class="ac-section-title">In-store purchases</div>
<div class="ac-list">
  @forelse($sales as $s)
    <div class="ac-list-row">
      <div><div class="ac-list-name">Receipt {{ $s->sale_number ? '#' . $s->sale_number : '' }}</div>
        <div class="ac-list-meta">{{ \Carbon\Carbon::parse($s->sale_date)->format('M j, Y') }}@if($s->payment_method) &middot; {{ str_replace('_', ' ', $s->payment_method) }}@endif</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($s->total_cents / 100, 2) }}</div>
        @if($s->payment_status && $s->payment_status !== 'paid')<div style="font-size:11px;opacity:.55;text-transform:capitalize">{{ str_replace('_', ' ', $s->payment_status) }}</div>@endif</div>
    </div>
  @empty
    <div class="ac-empty">No in-store purchases yet</div>
  @endforelse
</div>
@endsection
