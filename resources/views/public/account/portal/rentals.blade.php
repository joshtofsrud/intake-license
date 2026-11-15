@extends('public.account._shell')
@php $pageTitle = 'Rentals'; @endphp
@push('styles')
  @include('public.account.portal._portal-css')
@endpush

@section('content')
@include('public.account.portal._nav', ['active' => 'rentals'])

{{-- MARKER-PORTAL-RENTALS: every rental opens its own page --}}
@if($active->isNotEmpty())
  <div class="ac-section-title">Out now</div>
  <div class="ac-list">
    @foreach($active as $r)
      @php $overdue = $r->due_at && $r->due_at->isPast(); $canExtend = isset(($extendable ?? [])[$r->id]); @endphp
      <a class="ac-list-row" href="{{ route('tenant.customer.portal.rental', $r->id) }}{{ $canExtend ? '#extend' : '' }}">
        <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}@if($r->lines->count() > 1) <span style="opacity:.5;font-weight:400">+ {{ $r->lines->count() - 1 }} more</span>@endif</div>
          <div class="ac-list-meta">{{ $r->rental_number }} &middot; due back {{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}@if($canExtend) &middot; you can extend @endif</div></div>
        <div class="ac-list-right"><span class="ac-pill {{ $overdue ? 'ac-pill--refunded' : 'ac-pill--due' }}">{{ $overdue ? 'Overdue' : 'Out' }}</span><span class="ac-go">&rsaquo;</span></div>
      </a>
    @endforeach
  </div>
@endif

@if($reserved->isNotEmpty())
  <div class="ac-section-title">Reserved</div>
  <div class="ac-list">
    @foreach($reserved as $r)
      <a class="ac-list-row" href="{{ route('tenant.customer.portal.rental', $r->id) }}">
        <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}@if($r->lines->count() > 1) <span style="opacity:.5;font-weight:400">+ {{ $r->lines->count() - 1 }} more</span>@endif</div>
          <div class="ac-list-meta">starts {{ tlocal_datetime($r->starts_at, 'D, M j · g:i A') }} &middot; back {{ tlocal_datetime($r->due_at, 'D, M j · g:i A') }}</div></div>
        <div class="ac-list-right"><span class="ac-pill ac-pill--pending">Reserved</span><span class="ac-go">&rsaquo;</span></div>
      </a>
    @endforeach
  </div>
@endif

<div class="ac-section-title">Past rentals</div>
<div class="ac-list">
  @forelse($past as $r)
    <a class="ac-list-row" href="{{ route('tenant.customer.portal.rental', $r->id) }}">
      <div><div class="ac-list-name">{{ $r->lines->first()?->name_snapshot ?? 'Rental' }}</div>
        <div class="ac-list-meta">{{ tlocal_date($r->starts_at) }}@if($r->returned_at) &ndash; {{ tlocal_date($r->returned_at) }}@endif &middot; {{ $r->rental_number }}</div></div>
      <div class="ac-list-right"><div style="font-weight:700">{{ format_money($r->total_cents) }}</div>
        <span class="ac-pill ac-pill--{{ $r->status === 'returned' ? 'returned' : 'cancelled' }}">{{ ucfirst($r->status) }}</span></div>
    </a>
  @empty
    <div class="ac-empty">No rentals yet</div>
  @endforelse
</div>
@if($past->hasPages())
  <div class="ac-pager">
    <span>@if($past->previousPageUrl())<a href="{{ $past->previousPageUrl() }}">&larr; Newer</a>@endif</span>
    <span>@if($past->nextPageUrl())<a href="{{ $past->nextPageUrl() }}">Older &rarr;</a>@endif</span>
  </div>
@endif
@endsection
