@extends('layouts.tenant.app')
@php
  $pageTitle = 'Appointments';
  $statusLabels = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'shipped'     => 'Shipped',
    'closed'      => 'Closed',
    'cancelled'   => 'Cancelled',
    'refunded'    => 'Refunded',
  ];
  $paymentLabels = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partial',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
  ];
  // Status transitions — must match AppointmentController::TRANSITIONS exactly.
  // Used to populate the inline-edit dropdown with only valid next states.
  $statusTransitions = [
    'pending'     => ['confirmed', 'in_progress', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
    'confirmed'   => ['pending', 'in_progress', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
    'in_progress' => ['pending', 'confirmed', 'completed', 'shipped', 'closed', 'cancelled', 'refunded'],
    'completed'   => ['pending', 'confirmed', 'in_progress', 'shipped', 'closed', 'cancelled', 'refunded'],
    'shipped'     => ['pending', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled', 'refunded'],
    'closed'      => ['pending', 'confirmed', 'in_progress', 'completed', 'shipped', 'cancelled', 'refunded'],
    'cancelled'   => ['pending'],
    'refunded'    => ['pending'],
  ];
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

<x-tenant.schedule-tabs active="appointments" />

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

@if(!empty($attentionForBar['cards']))
  <div style="margin-bottom: 24px;">
    @include('tenant.dashboard._attention_cards', [
      'cards' => $attentionForBar['cards'],
      'activeFilter' => $filter ?? '',
    ])
  </div>
@endif

<form method="get" action="{{ route('tenant.appointments.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search ITO#, name, email…" style="max-width:260px">

  <select name="status" class="ia-input" style="width:auto">
    <option value="">All statuses</option>
    @foreach($statusLabels as $val => $label)
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

<p class="ia-result-count">
  <strong>{{ number_format($total) }}</strong> {{ Str::plural('appointment', $total) }}
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
  <div class="ia-table-wrap">
    <table class="ia-table" id="ia-appts-table" data-update-url="{{ route('tenant.appointments.update', ['subdomain' => $currentTenant->subdomain, 'id' => '__ID__']) }}">
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
    } catch (e) {
      console.error('inline-edit save failed', e);
      showError(tr, 'Network error. Try again.');
    } finally {
      saveBtn.disabled = false;
    }
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
