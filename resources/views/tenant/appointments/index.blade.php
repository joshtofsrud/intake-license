@extends('layouts.tenant.app')
@php
  $pageTitle = 'Appointments';
  $statusLabels = \App\Support\AppointmentStatus::LABELS; // MARKER-PATCH-287 single source
  $paymentLabels = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partial',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
  ];
  // Status transitions — must match AppointmentController::TRANSITIONS exactly.
  // Used to populate the inline-edit dropdown with only valid next states.
  $statusTransitions = \App\Support\AppointmentStatus::TRANSITIONS; // MARKER-PATCH-287 single source
  $sortLabels = [
    'date_desc'  => 'Newest first',
    'date_asc'   => 'Oldest first',
    'name_asc'   => 'Customer A–Z',
    'name_desc'  => 'Customer Z–A',
    'status'     => 'By status',
    'total_desc' => 'Total (high–low)',
    'total_asc'  => 'Total (low–high)',
  ];
@endphp

@push('styles')
<style>
/* Inline-edit pattern for the appointments table.
   Click a badge → it transforms into a select with valid options.
   Pick a different value → row goes "dirty," save/cancel buttons appear in the actions column.
   Save fires PATCH, applies in-place. Cancel reverts. */
.ia-inline-cell {
  position: relative;
}
.ia-inline-select {
  background: var(--ia-input-bg);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  color: var(--ia-text);
  font-size: 12px;
  padding: 4px 22px 4px 9px;
  appearance: none;
  cursor: pointer;
  font-family: inherit;
  outline: none;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.45)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
  background-repeat: no-repeat;
  background-position: right 7px center;
  transition: border-color var(--ia-t);
}
.ia-inline-select:focus {
  border-color: var(--ia-accent);
}
.ia-inline-select.is-dirty {
  border-color: var(--ia-accent);
  box-shadow: 0 0 0 1px var(--ia-accent);
}
.ia-inline-actions {
  display: none;
  gap: 4px;
  align-items: center;
  white-space: nowrap;
}
tr.is-dirty .ia-inline-actions {
  display: inline-flex;
}
.ia-inline-btn {
  width: 26px;
  height: 26px;
  padding: 0;
  border-radius: var(--ia-r-md);
  border: 0.5px solid var(--ia-border);
  background: var(--ia-surface);
  color: var(--ia-text-muted);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all var(--ia-t);
  font-family: inherit;
}
.ia-inline-btn:hover { color: var(--ia-text); border-color: var(--ia-border-strong); }
.ia-inline-btn--save { color: var(--ia-accent); border-color: var(--ia-accent); }
.ia-inline-btn--save:hover { background: var(--ia-accent); color: var(--ia-bg); }
.ia-inline-btn--save:disabled { opacity: .5; cursor: wait; }
.ia-inline-btn--cancel:hover { color: #EF4444; border-color: #EF4444; }
.appt-resource-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  margin-bottom: 16px;
  background: var(--ia-surface-2, rgba(255,255,255,0.03));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  font-size: 13px;
  color: var(--ia-text-2);
}
.appt-resource-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.appt-resource-clear {
  margin-left: 6px;
  color: var(--ia-text-3);
  text-decoration: none;
  font-size: 11px;
}
.appt-resource-clear:hover {
  color: var(--ia-accent, #BEF264);
}
.ia-inline-resource {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--ia-text);
}
.ia-inline-resource-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ia-inline-resource--unassigned {
  color: var(--ia-text-muted);
  font-style: italic;
}
.ia-inline-error {
  position: absolute;
  top: 100%;
  left: 0;
  margin-top: 4px;
  padding: 4px 8px;
  background: #EF4444;
  color: #fff;
  font-size: 11px;
  border-radius: var(--ia-r-md);
  white-space: nowrap;
  z-index: 5;
}
/* Cells with editors should not propagate clicks to the row's modal-opener. */
td.ia-inline-cell { cursor: default; }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    @if(!empty($filter) && !empty($filterLabels[$filter]))
      <h1 class="ia-page-title">{{ $filterLabels[$filter] }}</h1>
      <p class="ia-page-subtitle">
        {{ $total }} {{ Str::plural('appointment', $total) }} · 
        <a href="{{ route('tenant.appointments.index') }}" style="color: inherit; text-decoration: underline">Clear filter</a>
      </p>
    @else
      <h1 class="ia-page-title">Appointments</h1>
      <p class="ia-page-subtitle">Every booking, every status.</p>
    @endif
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" onclick="openApptModal()">
      + New appointment
    </button>
  </div>
</div>

@php
  // Inline-fetch the attention cards so the same row appears on this page.
  // Cheap — same query the dashboard runs.
  try {
    $svc = new \App\Services\Tenant\DashboardDataService(tenant());
    $attentionForBar = $svc->zoneAttention();
  } catch (\Throwable $e) {
    $attentionForBar = ['cards' => [], 'total_items' => 0];
  }
@endphp

{{-- MARKER-PATCH-113 - resource filter chip --}}
@if(!empty($resourceFilter))
  <div class="appt-resource-chip">
    <span class="appt-resource-dot" style="background: {{ $resourceFilter->color_hex }}"></span>
    Showing appointments for <strong>{{ $resourceFilter->name }}</strong>
    <a href="{{ route('tenant.appointments.index') }}" class="appt-resource-clear">clear ×</a>
  </div>
@endif

@if(!empty($attentionForBar['cards']))
  <div style="margin-bottom: 24px;">
    @include('tenant.dashboard._attention_cards', [
      'cards' => $attentionForBar['cards'],
      'activeFilter' => $filter ?? '',
    ])
  </div>
@endif

<form method="get" action="{{ route('tenant.appointments.index') }}" class="ia-toolbar appt-desktop-only" id="appt-desktop-form">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search ITO#, name, email…" style="max-width:260px">

  <select name="status" class="ia-input" style="width:auto">
    <option value="">All statuses</option>
    {{-- MARKER-PATCH-285 — only the selectable set; \$statusLabels still resolves legacy rows below --}}
    @foreach(\App\Support\AppointmentStatus::selectable() as $val => $label)
      <option value="{{ $val }}" @selected($status === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="payment" class="ia-input" style="width:auto">
    <option value="">All payments</option>
    <option value="unpaid"  @selected($payment === 'unpaid')>Unpaid</option>
    <option value="partial" @selected($payment === 'partial')>Partial</option>
    <option value="paid"    @selected($payment === 'paid')>Paid</option>
  </select>

  <x-tenant.date-range
    fromName="date_from"
    toName="date_to"
    :fromValue="$dateFrom"
    :toValue="$dateTo"
    placeholder="Date range" />

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $status || $payment || $dateFrom || $dateTo || $sort !== 'date_desc')
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

{{-- APPT-LIST-MOBILE v1 — mobile filter bar + sheet. --}}
@php
  $hasAnyFilter = $search || $status || $payment || $dateFrom || $dateTo || $sort !== 'date_desc';
  $currentSortLabel = $sortLabels[$sort] ?? 'Newest first';
  $currentStatusLabel = $status ? ($statusLabels[$status] ?? $status) : 'All statuses';
@endphp

<form method="get" action="{{ route('tenant.appointments.index') }}" class="appt-mobile-only appt-mfilter" id="appt-mobile-form">
  <div class="appt-mfilter-search-wrap">
    <svg class="appt-mfilter-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="search" name="s" class="appt-mfilter-search" value="{{ $search }}"
      placeholder="Search ITO#, name, email" autocomplete="off" id="appt-search-mobile">
  </div>
  {{-- Hidden fields preserve filter state when search submits --}}
  <input type="hidden" name="status"    id="appt-status-mobile"    value="{{ $status }}">
  <input type="hidden" name="payment"   id="appt-payment-mobile"   value="{{ $payment }}">
  <input type="hidden" name="date_from" id="appt-datefrom-mobile"  value="{{ $dateFrom }}">
  <input type="hidden" name="date_to"   id="appt-dateto-mobile"    value="{{ $dateTo }}">
  <input type="hidden" name="sort"      id="appt-sort-mobile"      value="{{ $sort }}">
  <button type="button" class="appt-mfilter-iconbtn {{ $hasAnyFilter ? 'is-active' : '' }}" onclick="ApptFilter.open()" aria-label="Filter and sort">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
    </svg>
    @if($hasAnyFilter)
      <span class="appt-mfilter-badge" aria-hidden="true"></span>
    @endif
  </button>
  <button type="button" class="appt-mfilter-iconbtn" onclick="openApptModal()" aria-label="New appointment">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
  </button>
</form>

{{-- Filter bottom sheet --}}
<div class="appt-filter-backdrop" id="appt-filter-backdrop" onclick="ApptFilter.close()" aria-hidden="true"></div>
<div class="appt-filter-sheet" id="appt-filter-sheet" role="dialog" aria-modal="true" aria-label="Filter appointments" aria-hidden="true">
  <div class="appt-filter-handle" aria-hidden="true"></div>
  <div class="appt-filter-header">
    <span class="appt-filter-title">Filter &amp; sort</span>
    <button type="button" class="appt-filter-close" onclick="ApptFilter.close()" aria-label="Close">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <div class="appt-filter-body">
    {{-- Status --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Status</div>
      <div class="appt-filter-chips" data-field="status">
        <button type="button" class="appt-filter-chip {{ !$status ? 'is-active' : '' }}" data-value="">All statuses</button>
        @foreach($statusLabels as $val => $label)
          <button type="button" class="appt-filter-chip {{ $status === $val ? 'is-active' : '' }}" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>

    {{-- Payment --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Payment</div>
      <div class="appt-filter-chips" data-field="payment">
        <button type="button" class="appt-filter-chip {{ !$payment ? 'is-active' : '' }}" data-value="">All payments</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'unpaid' ? 'is-active' : '' }}" data-value="unpaid">Unpaid</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'partial' ? 'is-active' : '' }}" data-value="partial">Partial</button>
        <button type="button" class="appt-filter-chip {{ $payment === 'paid' ? 'is-active' : '' }}" data-value="paid">Paid</button>
      </div>
    </div>

    {{-- Date range --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Date range</div>
      <div class="appt-filter-daterange">
        <input type="date" id="appt-filter-datefrom" class="appt-filter-dateinput" value="{{ $dateFrom }}" placeholder="From">
        <span class="appt-filter-dash">–</span>
        <input type="date" id="appt-filter-dateto" class="appt-filter-dateinput" value="{{ $dateTo }}" placeholder="To">
      </div>
    </div>

    {{-- Sort --}}
    <div class="appt-filter-group">
      <div class="appt-filter-grouplabel">Sort by</div>
      <div class="appt-filter-chips" data-field="sort">
        @foreach($sortLabels as $val => $label)
          <button type="button" class="appt-filter-chip {{ $sort === $val ? 'is-active' : '' }}" data-value="{{ $val }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>
  </div>

  <div class="appt-filter-actions">
    <button type="button" class="appt-filter-btn-clear" onclick="ApptFilter.clear()">Clear all</button>
    <button type="button" class="appt-filter-btn-apply" onclick="ApptFilter.apply()">Apply</button>
  </div>
</div>

{{-- MARKER-PATCH-439 — section tabs sit below the controls, right above the list --}}
<x-tenant.schedule-tabs active="appointments" />

{{-- Mobile result header --}}
<div class="appt-mobile-only appt-list-header">
  <span>{{ number_format($total) }} {{ Str::plural('appointment', $total) }} · {{ $currentSortLabel }}</span>
  @if($hasAnyFilter)
    <a href="{{ route('tenant.appointments.index') }}" class="appt-list-clear">Clear</a>
  @endif
</div>

<p class="ia-result-count appt-desktop-only">
  <strong id="appt-result-count" data-count="{{ $total }}">{{ number_format($total) }}</strong> <span id="appt-result-noun">{{ Str::plural('appointment', $total) }}</span>
</p>

@if($appointments->isEmpty())
  <div class="ia-empty">
    <div class="ia-empty-icon">
      <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="opacity:.4">
        <rect x="2" y="4" width="16" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/>
        <path d="M7 4V2M13 4V2M2 8h16" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </div>
    <div class="ia-empty-title">No appointments found</div>
    <div class="ia-empty-desc">
      @if($search || $status || $payment)
        Try adjusting your filters.
      @else
        When customers book, they'll appear here.
      @endif
    </div>
    @if(!$search && !$status && !$payment)
      <button type="button" class="ia-btn ia-btn--primary" onclick="openApptModal()">
        + New appointment
      </button>
    @endif
  </div>
@else
  <div class="ia-table-wrap appt-desktop-only">
    <table class="ia-table" id="ia-appts-table" data-update-url="{{ route('tenant.appointments.update', ['id' => '__ID__']) }}" data-active-filter="{{ $filter ?? '' }}" data-status-filter="{{ $status ?? '' }}">
      <thead>
        <tr>
          <th>ITO #</th>
          <th>Customer</th>
          <th>Date</th>
          <th>Resource</th>
          <th>Status</th>
          <th>Payment</th>
          <th class="ia-num">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($appointments as $appt)
          @php
            $allowedStatuses = $statusTransitions[$appt->status] ?? [];
            // Include current status in dropdown so it shows as the selected value
            $statusOptions = array_merge([$appt->status], $allowedStatuses);
            $statusOptions = array_values(array_unique($statusOptions));
          @endphp
          <tr data-appt-id="{{ $appt->id }}"
              data-orig-status="{{ $appt->status }}"
              data-orig-payment="{{ $appt->payment_status }}"
              data-orig-resource="{{ $appt->resource_id ?? '' }}">
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" style="cursor:pointer">
              <span style="font-weight:500">{{ $appt->ra_number }}</span>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" style="cursor:pointer">
              <div style="font-weight:500">{{ $appt->customerName() }}</div>
              <div class="ia-muted-cell" style="font-size:12px">{{ $appt->customer_email }}</div>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" class="ia-muted-cell" style="cursor:pointer">{{ $appt->appointment_date->format('M j, Y') }}</td>
            <td class="ia-inline-cell" data-field="resource">
              <select class="ia-inline-select" data-field="resource">
                <option value="">— Unassigned —</option>
                @foreach($resources as $r)
                  <option value="{{ $r->id }}" @selected($appt->resource_id === $r->id)>{{ $r->name }}</option>
                @endforeach
              </select>
            </td>
            <td class="ia-inline-cell" data-field="status">
              <select class="ia-inline-select" data-field="status">
                @foreach($statusOptions as $s)
                  <option value="{{ $s }}" @selected($appt->status === $s)>{{ $statusLabels[$s] ?? $s }}</option>
                @endforeach
              </select>
            </td>
            <td class="ia-inline-cell" data-field="payment">
              <select class="ia-inline-select" data-field="payment">
                @foreach($paymentLabels as $val => $label)
                  <option value="{{ $val }}" @selected($appt->payment_status === $val)>{{ $label }}</option>
                @endforeach
              </select>
            </td>
            <td onclick="openDetailModal('appointment','{{ $appt->id }}')" class="ia-num" style="cursor:pointer">{{ format_money($appt->total_cents) }}</td>
            <td>
              <span class="ia-inline-actions">
                <button type="button" class="ia-inline-btn ia-inline-btn--save" data-action="save" title="Save changes (Enter)">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M2.5 6.5l2.5 2.5L10.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </button>
                <button type="button" class="ia-inline-btn ia-inline-btn--cancel" data-action="cancel" title="Discard (Esc)">
                  <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                    <path d="M3 3l7 7M10 3l-7 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                </button>
              </span>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Mobile card list (parallel to desktop table) --}}
  <div class="appt-mobile-only appt-cards">
    @foreach($appointments as $appt)
      @php
        $statusKey = $appt->status;
        $statusToneMap = [
          'pending'     => 'pending',
          'confirmed'   => 'confirmed',
          'in_progress' => 'in-progress',
          'completed'   => 'completed',
          'shipped'     => 'completed',
          'closed'      => 'completed',
          'cancelled'   => 'cancelled',
          'refunded'    => 'cancelled',
        ];
        $statusTone = $statusToneMap[$statusKey] ?? 'completed';
        $paymentTone = match($appt->payment_status) {
          'paid'     => 'success',
          'partial'  => 'warning',
          'unpaid'   => 'danger',
          'refunded' => 'cancelled',
          default    => 'neutral',
        };
      @endphp
      <button type="button" class="appt-card" onclick="openDetailModal('appointment','{{ $appt->id }}')">
        <div class="appt-card-row1">
          <span class="appt-card-status is-{{ $statusTone }}"></span>
          <span class="appt-card-ito">{{ $appt->ra_number }}</span>
          <span class="appt-card-date">{{ $appt->appointment_date->format('M j') }}</span>
          <span class="appt-card-total">{{ format_money($appt->total_cents) }}</span>
        </div>
        <div class="appt-card-row2">
          <span class="appt-card-name">{{ $appt->customerName() }}</span>
        </div>
        <div class="appt-card-row3">
          <span class="appt-card-pill appt-card-pill--status appt-card-pill--{{ $statusTone }}">{{ $statusLabels[$statusKey] ?? $statusKey }}</span>
          <span class="appt-card-pill appt-card-pill--{{ $paymentTone }}">{{ $paymentLabels[$appt->payment_status] ?? $appt->payment_status }}</span>
        </div>
      </button>
    @endforeach
  </div>

  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.appointments.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

@include('tenant.appointments._create_modal')

@endsection

@push('scripts')
<script>
/**
 * Inline editing for the appointments list.
 * Each row carries data-orig-{field} attributes captured at render time.
 * When a select's value differs from its data-orig, the row is "dirty" and
 * Save/Cancel buttons appear. Save fires PATCH per changed field.
 *
 * Backend ops: status, payment, change_resource. All exist already on
 * AppointmentController::handleUpdate. Resource changes can return 409 on
 * conflict — we surface the message and let the user pick again or click
 * the conflicting appointment in the detail modal.
 */
(function() {
  const table = document.getElementById('ia-appts-table');
  if (!table) return;

  const updateUrlTpl = table.dataset.updateUrl;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!csrfToken) {
    console.warn('inline-edit: no CSRF token found, save will fail');
  }

  /**
   * Walk all selects in a row, returning {field: newValue} for those changed.
   * Empty string for resource means "Unassigned" — but the backend's
   * change_resource op rejects empty strings ("Resource is required"), so
   * we filter resource changes to non-empty before sending. Status and
   * payment selects always have non-empty values.
   */
  function getDirtyFields(tr) {
    const dirty = {};
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      if (sel.value !== orig) {
        dirty[field] = sel.value;
      }
    });
    return dirty;
  }

  function setDirtyState(tr, isDirty) {
    tr.classList.toggle('is-dirty', isDirty);
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      sel.classList.toggle('is-dirty', sel.value !== orig);
    });
  }

  function showError(tr, msg) {
    clearError(tr);
    const cell = tr.querySelector('.ia-inline-cell');
    if (!cell) return;
    const err = document.createElement('div');
    err.className = 'ia-inline-error';
    err.textContent = msg;
    cell.appendChild(err);
    setTimeout(() => clearError(tr), 4000);
  }
  function clearError(tr) {
    tr.querySelectorAll('.ia-inline-error').forEach(e => e.remove());
  }

  /**
   * Send a single field update. Resolves with {ok, status, body}. Caller
   * decides what to do based on those.
   */
  async function patchField(apptId, op, payload) {
    const url = updateUrlTpl.replace('__ID__', apptId);
    const body = new FormData();
    body.append('_token', csrfToken);
    body.append('op', op);
    Object.entries(payload).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(url, {
      method: 'POST', // Laravel accepts POST + _method, but we use real PATCH
      headers: {
        'X-HTTP-Method-Override': 'PATCH',
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json',
      },
      body,
      credentials: 'same-origin',
    });
    let parsed = null;
    try { parsed = await res.json(); } catch (e) { /* non-JSON */ }
    return { ok: res.ok, status: res.status, body: parsed };
  }

  async function saveRow(tr) {
    const apptId = tr.dataset.apptId;
    const dirty = getDirtyFields(tr);
    if (Object.keys(dirty).length === 0) {
      setDirtyState(tr, false);
      return;
    }
    clearError(tr);

    const saveBtn = tr.querySelector('[data-action="save"]');
    saveBtn.disabled = true;

    // Field → backend op + payload key
    const ops = [];
    if ('status' in dirty)   ops.push({ field: 'status',   op: 'status',          payload: { status: dirty.status } });
    if ('payment' in dirty)  ops.push({ field: 'payment',  op: 'payment',         payload: { payment_status: dirty.payment } });
    if ('resource' in dirty) {
      // Backend rejects empty resource_id. If the user selected "Unassigned",
      // we'd need a different op (not built). For now, just refuse it client-side.
      if (dirty.resource === '') {
        showError(tr, 'Cannot unassign resource via inline edit yet.');
        saveBtn.disabled = false;
        return;
      }
      ops.push({ field: 'resource', op: 'change_resource', payload: { resource_id: dirty.resource } });
    }

    try {
      for (const o of ops) {
        const res = await patchField(apptId, o.op, o.payload);

        if (res.ok && res.body?.ok) {
          // Update the orig dataset attr so the field is no longer "dirty"
          const newVal = dirty[o.field];
          tr.dataset['orig' + o.field.charAt(0).toUpperCase() + o.field.slice(1)] = newVal;
          continue;
        }

        // 409 on resource means conflict. Server returns details; we just
        // show the message and bail. User can re-select or click the
        // conflicting appointment.
        if (res.status === 409 && o.field === 'resource') {
          showError(tr, res.body?.message || 'Resource conflict.');
          saveBtn.disabled = false;
          return;
        }

        // 422 — backend rejected the value. Show its message and bail.
        showError(tr, res.body?.message || 'Save failed.');
        saveBtn.disabled = false;
        return;
      }
      // All ops succeeded
      setDirtyState(tr, false);
      // MARKER-PATCH-179 — if this row no longer matches the active filter
      // (e.g. confirming a booking on the "Unconfirmed bookings" list), fade
      // it out and remove it, and decrement the result count — so the list
      // stays accurate without a manual refresh.
      maybePruneRow(tr, dirty);
    } catch (e) {
      console.error('inline-edit save failed', e);
      showError(tr, 'Network error. Try again.');
    } finally {
      saveBtn.disabled = false;
    }
  }

  // MARKER-PATCH-179 — remove a row that no longer belongs in the current
  // filtered view after an inline status change.
  function maybePruneRow(tr, dirty) {
    if (!('status' in dirty)) return; // only status changes can drop a row
    const table = document.getElementById('ia-appts-table');
    if (!table) return;
    const activeFilter = (table.dataset.activeFilter || '').toLowerCase();
    const statusFilter = (table.dataset.statusFilter || '').toLowerCase();
    const newStatus = (dirty.status || '').toLowerCase();

    // Decide whether the row still belongs. Two filter sources:
    //  - the "Unconfirmed bookings" attention filter (pending only)
    //  - the explicit status dropdown filter (must match exactly)
    let belongs = true;
    // MARKER-PATCH-179B — the real attention-filter value is
    // 'unconfirmed_bookings'; treat any 'unconfirmed'/'pending' variant as the
    // pending-only scope.
    if (activeFilter.indexOf('unconfirmed') !== -1 || activeFilter === 'pending') {
      belongs = (newStatus === 'pending');
    } else if (statusFilter) {
      belongs = (newStatus === statusFilter);
    }
    if (belongs) return;

    // Fade + remove, then decrement the count(s).
    tr.style.transition = 'opacity .25s ease';
    tr.style.opacity = '0';
    setTimeout(() => {
      tr.remove();
      const strong = document.getElementById('appt-result-count');
      if (strong) {
        const n = Math.max(0, (parseInt(strong.dataset.count, 10) || 1) - 1);
        strong.dataset.count = n;
        strong.textContent = n.toLocaleString();
        const noun = document.getElementById('appt-result-noun');
        if (noun) noun.textContent = (n === 1 ? 'appointment' : 'appointments');
      }
    }, 260);
  }

  function cancelRow(tr) {
    clearError(tr);
    tr.querySelectorAll('.ia-inline-select').forEach(sel => {
      const field = sel.dataset.field;
      const orig = tr.dataset['orig' + field.charAt(0).toUpperCase() + field.slice(1)];
      sel.value = orig;
    });
    setDirtyState(tr, false);
  }

  // Wire up change detection on selects
  table.addEventListener('change', (e) => {
    const sel = e.target.closest('.ia-inline-select');
    if (!sel) return;
    const tr = sel.closest('tr[data-appt-id]');
    if (!tr) return;
    const dirty = getDirtyFields(tr);
    setDirtyState(tr, Object.keys(dirty).length > 0);
  });

  // Wire up save/cancel button clicks
  table.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    e.stopPropagation();
    const tr = btn.closest('tr[data-appt-id]');
    if (!tr) return;
    if (btn.dataset.action === 'save') saveRow(tr);
    else if (btn.dataset.action === 'cancel') cancelRow(tr);
  });

  // Stop select clicks from bubbling to row's modal-opener cells (only their
  // cells have onclick now, but defensive)
  table.addEventListener('click', (e) => {
    if (e.target.closest('.ia-inline-cell')) {
      e.stopPropagation();
    }
  }, true);

  // Keyboard: Esc cancels, Enter saves the focused row
  table.addEventListener('keydown', (e) => {
    const tr = e.target.closest('tr[data-appt-id].is-dirty');
    if (!tr) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      cancelRow(tr);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      saveRow(tr);
    }
  });
})();
</script>
@endpush


@push('styles')
<style>
/* APPT-LIST-MOBILE-CSS v1 */

.appt-mobile-only { display: none; }

@media (max-width: 600px) {
  .appt-desktop-only { display: none !important; }
  .appt-mobile-only { display: block; }

  /* ── Filter bar (same shape as customer list) ── */
  .appt-mfilter {
    display: grid !important;
    grid-template-columns: 1fr 40px 40px;
    gap: 6px;
    margin: 4px 0 14px;
  }
  .appt-mfilter-search-wrap {
    position: relative;
  }
  .appt-mfilter-search-icon {
    position: absolute;
    left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--ia-text-muted);
    pointer-events: none;
  }
  .appt-mfilter-search {
    width: 100%;
    height: 40px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 0 12px 0 36px;
    color: var(--ia-text);
    font-size: 14px;
    font-family: inherit;
    -webkit-appearance: none;
    appearance: none;
  }
  .appt-mfilter-search:focus {
    outline: none;
    border-color: var(--ia-accent);
  }
  .appt-mfilter-iconbtn {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    color: var(--ia-text-muted);
    cursor: pointer;
    position: relative;
    -webkit-tap-highlight-color: transparent;
  }
  .appt-mfilter-iconbtn:active { transform: scale(0.95); }
  .appt-mfilter-iconbtn.is-active {
    color: var(--ia-accent);
    border-color: rgba(190,242,100,.3);
    background: rgba(190,242,100,.08);
  }
  .appt-mfilter-badge {
    position: absolute;
    top: 4px; right: 4px;
    width: 8px; height: 8px;
    background: var(--ia-accent);
    border-radius: 50%;
    border: 2px solid var(--ia-bg, #0a0a0a);
  }

  /* ── List header ── */
  .appt-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }
  .appt-list-clear {
    color: var(--ia-accent);
    text-decoration: none;
    font-size: 11px;
  }

  /* ── Cards ── */
  .appt-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .appt-card {
    display: block;
    width: 100%;
    text-align: left;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    font-family: inherit;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .appt-card:active { transform: scale(0.99); }
  .appt-card-row1 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
  }
  .appt-card-status {
    width: 8px; height: 8px; border-radius: 50%;
    flex-shrink: 0;
    background: var(--ia-text-muted);
  }
  .appt-card-status.is-pending     { background: #F59E0B; }
  .appt-card-status.is-confirmed   { background: #34D399; }
  .appt-card-status.is-in-progress { background: var(--ia-accent); }
  .appt-card-status.is-completed   { background: #6b7280; }
  .appt-card-status.is-cancelled   { background: #EF4444; opacity: .6; }

  .appt-card-ito {
    font-size: 12px;
    color: var(--ia-text-muted);
    font-variant-numeric: tabular-nums;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .appt-card-date {
    font-size: 11px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    text-transform: uppercase;
    letter-spacing: .04em;
    font-variant-numeric: tabular-nums;
  }
  .appt-card-total {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
    font-variant-numeric: tabular-nums;
    margin-left: 4px;
  }
  .appt-card-row2 {
    margin-bottom: 8px;
  }
  .appt-card-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--ia-text);
    letter-spacing: -.01em;
  }
  .appt-card-row3 {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .appt-card-pill {
    font-size: 10px;
    padding: 3px 8px;
    border-radius: 99px;
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    font-weight: 500;
    white-space: nowrap;
  }
  .appt-card-pill--pending     { background: rgba(245,158,11,.15); color: #F59E0B; }
  .appt-card-pill--confirmed   { background: rgba(52,211,153,.15); color: #34D399; }
  .appt-card-pill--in-progress { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .appt-card-pill--completed   { background: rgba(107,114,128,.15); color: #9ca3af; }
  .appt-card-pill--cancelled   { background: rgba(239,68,68,.15);  color: #EF4444; }
  .appt-card-pill--success     { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .appt-card-pill--warning     { background: rgba(245,158,11,.15); color: #F59E0B; }
  .appt-card-pill--danger      { background: rgba(239,68,68,.15);  color: #EF4444; }
  .appt-card-pill--neutral     { background: var(--ia-surface-2);  color: var(--ia-text-muted); }
}

/* ── Filter bottom sheet ── */
.appt-filter-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.appt-filter-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}
.appt-filter-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 88vh;
  display: flex;
  flex-direction: column;
}
.appt-filter-sheet.is-open { transform: translateY(0); }

.appt-filter-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 12px auto 8px;
  flex-shrink: 0;
}
body.ia-theme-b .appt-filter-handle { background: rgba(0,0,0,.18); }

.appt-filter-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 20px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.appt-filter-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text);
}
.appt-filter-close {
  background: transparent;
  border: none;
  color: var(--ia-text-muted);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.appt-filter-body {
  padding: 16px 20px;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1;
}
.appt-filter-group {
  margin-bottom: 20px;
}
.appt-filter-grouplabel {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
  margin-bottom: 8px;
}
.appt-filter-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.appt-filter-chip {
  padding: 8px 14px;
  border-radius: 99px;
  background: var(--ia-surface-2);
  border: 0.5px solid var(--ia-border);
  color: var(--ia-text);
  font-size: 13px;
  font-weight: 500;
  font-family: inherit;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.appt-filter-chip:active { transform: scale(0.96); }
.appt-filter-chip.is-active {
  background: rgba(190,242,100,.12);
  color: var(--ia-accent);
  border-color: rgba(190,242,100,.35);
}

.appt-filter-daterange {
  display: flex;
  align-items: center;
  gap: 8px;
}
.appt-filter-dateinput {
  flex: 1;
  background: var(--ia-input-bg, var(--ia-surface-2));
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-family: inherit;
  -webkit-appearance: none;
  appearance: none;
}
.appt-filter-dash {
  color: var(--ia-text-muted);
  font-size: 14px;
}

.appt-filter-actions {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 8px;
  padding: 14px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  border-top: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.appt-filter-btn-clear {
  background: transparent;
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.appt-filter-btn-apply {
  background: var(--ia-accent);
  color: var(--ia-bg, #0a0a0a);
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}

/* Hide entirely on desktop */
@media (min-width: 601px) {
  .appt-filter-sheet,
  .appt-filter-backdrop { display: none !important; }
}
</style>
@endpush

@push('scripts')
<script>
// APPT-LIST-MOBILE-JS v1
(function () {
  // Track pending changes inside the sheet — apply commits, close discards
  var pending = {
    status:    document.getElementById('appt-status-mobile')?.value || '',
    payment:   document.getElementById('appt-payment-mobile')?.value || '',
    date_from: document.getElementById('appt-datefrom-mobile')?.value || '',
    date_to:   document.getElementById('appt-dateto-mobile')?.value || '',
    sort:      document.getElementById('appt-sort-mobile')?.value || 'date_desc',
  };

  window.ApptFilter = {
    open: function () {
      var b = document.getElementById('appt-filter-backdrop');
      var s = document.getElementById('appt-filter-sheet');
      if (!b || !s) return;
      // Reset pending to current applied state
      pending = {
        status:    document.getElementById('appt-status-mobile').value,
        payment:   document.getElementById('appt-payment-mobile').value,
        date_from: document.getElementById('appt-datefrom-mobile').value,
        date_to:   document.getElementById('appt-dateto-mobile').value,
        sort:      document.getElementById('appt-sort-mobile').value,
      };
      b.classList.add('is-open');
      s.classList.add('is-open');
      b.setAttribute('aria-hidden','false');
      s.setAttribute('aria-hidden','false');
      document.body.style.overflow = 'hidden';
    },
    close: function () {
      var b = document.getElementById('appt-filter-backdrop');
      var s = document.getElementById('appt-filter-sheet');
      if (!b || !s) return;
      b.classList.remove('is-open');
      s.classList.remove('is-open');
      b.setAttribute('aria-hidden','true');
      s.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    },
    apply: function () {
      // Write pending → hidden fields, submit form
      document.getElementById('appt-status-mobile').value    = pending.status;
      document.getElementById('appt-payment-mobile').value   = pending.payment;
      document.getElementById('appt-datefrom-mobile').value  = pending.date_from;
      document.getElementById('appt-dateto-mobile').value    = pending.date_to;
      document.getElementById('appt-sort-mobile').value      = pending.sort;
      document.getElementById('appt-mobile-form').submit();
    },
    clear: function () {
      window.location = '{{ route("tenant.appointments.index") }}';
    },
  };

  // Chip toggles
  document.querySelectorAll('.appt-filter-chips').forEach(function (group) {
    var field = group.getAttribute('data-field');
    group.querySelectorAll('.appt-filter-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var val = chip.getAttribute('data-value');
        pending[field] = val;
        // Update active state visually
        group.querySelectorAll('.appt-filter-chip').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
      });
    });
  });

  // Date inputs update pending live
  var df = document.getElementById('appt-filter-datefrom');
  var dt = document.getElementById('appt-filter-dateto');
  if (df) df.addEventListener('change', function () { pending.date_from = df.value; });
  if (dt) dt.addEventListener('change', function () { pending.date_to = dt.value; });

  // Esc closes
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') ApptFilter.close();
  });

  // Live search submit on the mobile bar
  var searchInput = document.getElementById('appt-search-mobile');
  var form = document.getElementById('appt-mobile-form');
  if (searchInput && form) {
    var t = null;
    searchInput.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { form.submit(); }, 350);
    });
  }
})();
</script>
@endpush
