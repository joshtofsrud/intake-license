@extends('public.account._shell')
@php $pageTitle = 'Rentals'; @endphp
{{-- MARKER-PORTAL-V2 --}}
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'rentals'])

@if($active->isNotEmpty())
  <div class="ac-section-title">Active</div>
  @foreach($active as $r)
    @php $overdue = $r->due_at && $r->due_at->isPast(); @endphp
    <div class="ac-card" style="padding:20px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
          <div style="font-weight:700;font-size:16px">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}@if($r->lines->count() > 1) <span style="opacity:.5;font-weight:500">+ {{ $r->lines->count() - 1 }} more</span>@endif</div>
          <div class="ac-list-meta" style="margin-top:3px">{{ $r->rental_number }} &middot; out since {{ tlocal_datetime($r->checked_out_at ?? $r->starts_at, 'D, M j · g:i A') }}</div>
        </div>
        <span class="ac-pill {{ $overdue ? 'ac-pill--refunded' : 'ac-pill--due' }}">{{ $overdue ? 'Overdue' : 'Due ' . tlocal($r->due_at, 'D, M j') }}</span>
      </div>
      <div style="display:flex;gap:18px;margin-top:14px;font-size:13px;flex-wrap:wrap">
        <div><div class="ac-chip-k">Due back</div><div style="font-weight:600">{{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}</div></div>
        <div><div class="ac-chip-k">Waiver</div><div style="font-weight:600">{{ $r->agreement_signed_at ? 'Signed ✓' : 'Not signed' }}</div></div>
        @if($r->deposit_hold_cents)
          <div><div class="ac-chip-k">Deposit hold</div><div style="font-weight:600">${{ number_format($r->deposit_hold_cents / 100, 2) }}</div></div>
        @endif
        <div><div class="ac-chip-k">Total</div><div style="font-weight:600">${{ number_format($r->total_cents / 100, 2) }}</div></div>
      </div>
      <div style="margin-top:16px">
        <a href="{{ route('tenant.customer.portal.messages') }}" class="ac-btn ac-btn--ghost" style="padding:10px;font-size:13.5px;text-decoration:none">Ask about this rental</a>
      </div>
    </div>
  @endforeach
@endif

@if($reserved->isNotEmpty())
  <div class="ac-section-title">Reserved</div>
  <div class="ac-list">
    @foreach($reserved as $r)
      <div class="ac-list-row">
        <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}</div>
          <div class="ac-list-meta">starts {{ tlocal_datetime($r->starts_at, 'D, M j · g:i A') }} &middot; back {{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--pending">Reserved</span></div>
      </div>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Past rentals</div>
<div class="ac-list">
  @forelse($past as $r)
    <div class="ac-list-row">
      <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}</div>
        <div class="ac-list-meta">{{ tlocal_date($r->starts_at) }}@if($r->returned_at) &ndash; {{ tlocal_date($r->returned_at) }}@endif</div></div>
      <div class="ac-list-right"><div style="font-weight:700">${{ number_format($r->total_cents / 100, 2) }}</div>
        <span class="ac-pill ac-pill--{{ $r->status === 'returned' ? 'returned' : 'cancelled' }}">{{ ucfirst($r->status) }}</span></div>
    </div>
  @empty
    <div class="ac-empty">No rentals yet</div>
  @endforelse
</div>
@endsection
