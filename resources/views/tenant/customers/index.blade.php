@extends('layouts.tenant.app')
@php
  $pageTitle = 'Customers';
  $sortLabels = [
    'name_asc'     => 'Name A–Z',
    'name_desc'    => 'Name Z–A',
    'added_desc'   => 'Newest first',
    'added_asc'    => 'Oldest first',
    'spend_desc'   => 'Top spenders',
    'spend_asc'    => 'Lowest spend',
    'last_service' => 'Last service',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Customers</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('customer', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('new-customer-card').style.display='block';this.style.display='none'">
      + New customer
    </button>
  </div>
</div>

<div id="new-customer-card" class="ia-card" style="display:none;margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">New customer</span>
    <button type="button" class="ia-card-action"
      onclick="document.getElementById('new-customer-card').style.display='none';document.querySelector('.ia-btn--primary').style.display=''">
      Cancel
    </button>
  </div>
  <form method="POST" action="{{ route('tenant.customers.store') }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">First name <span class="ia-required">*</span></label>
        <input type="text" name="first_name" class="ia-input" required value="{{ old('first_name') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Last name <span class="ia-required">*</span></label>
        <input type="text" name="last_name" class="ia-input" required value="{{ old('last_name') }}">
      </div>
    </div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Email <span class="ia-required">*</span></label>
        <input type="email" name="email" class="ia-input" required value="{{ old('email') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Phone</label>
        <input type="tel" name="phone" class="ia-input" value="{{ old('phone') }}">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button type="submit" class="ia-btn ia-btn--primary">Save customer</button>
    </div>
  </form>
</div>

<form method="get" action="{{ route('tenant.customers.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, email, or phone…" style="max-width:300px">

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Search</button>
  @if($search || $sort !== 'name_asc')
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

<p class="ia-result-count">
  <strong>{{ number_format($total) }}</strong> {{ Str::plural('customer', $total) }}
</p>

@if($customers->isEmpty())
  <div class="ia-empty">
    <div class="ia-empty-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="opacity:.4">
        <circle cx="10" cy="7" r="4" stroke="currentColor" stroke-width="1.4"/>
        <path d="M2.5 18c0-4 3.5-7 7.5-7s7.5 3 7.5 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="ia-empty-title">
      @if($search) No customers match "{{ $search }}" @else No customers yet @endif
    </div>
    <div class="ia-empty-desc">
      @if($search) Try a different search term. @else Customers are created when appointments are booked, or you can add one manually. @endif
    </div>
  </div>
@else
  <div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Last service</th>
          <th class="ia-num">Total spend</th>
          <th>Added</th>
        </tr>
      </thead>
      <tbody>
        @foreach($customers as $c)
          @php $stat = $stats[$c->id] ?? null; @endphp
          <tr style="cursor:pointer" onclick="openDetailModal('customer','{{ $c->id }}')">
            <td><span style="font-weight:500">{{ $c->first_name }} {{ $c->last_name }}</span></td>
            <td class="ia-muted-cell">{{ $c->email }}</td>
            <td class="ia-muted-cell">{{ $c->phone ?: '—' }}</td>
            <td class="ia-muted-cell">
              {{ $stat?->last_service_date ? \Carbon\Carbon::parse($stat->last_service_date)->format('M j, Y') : '—' }}
            </td>
            <td class="ia-num">{{ format_money((int)($stat?->total_spend_cents ?? 0)) }}</td>
            <td class="ia-muted-cell">{{ $c->created_at->format('M j, Y') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.customers.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

@endsection
