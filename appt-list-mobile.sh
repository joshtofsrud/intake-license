#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Appointments list — mobile redesign.
#
# Matches the customer-list-mobile pattern. Replaces three+ vertical filter
# rows (status / payment / date / sort) with:
#   1. Single-row search-and-filter bar (search input, filter icon, + icon)
#   2. Combined bottom-sheet picker with all filters grouped:
#      - Status (8 options including "All")
#      - Payment (4 options including "All")
#      - Date range (from/to)
#      - Sort (7 options)
#   3. Card list replacing desktop 8-col table
#
# Parallel desktop + mobile renders. Desktop preserved unchanged. Live
# search on input (350ms debounce). Filter sheet has Apply / Clear actions.
#
# Mobile cards drop the inline-edit dropdowns (status / payment / resource).
# Tapping a card opens the detail modal where edits happen there. Inline
# editing was a desktop power-user feature; mobile users open detail.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== appointments list mobile starting ==="

# Wrap the desktop filter form, the result count, the attention cards block,
# and the table in .appt-desktop-only. Inject parallel mobile UI.
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/index.blade.php')
s = p.read_text()
marker = "APPT-LIST-MOBILE v1"
if marker in s:
    print("SKIP (mobile already present)")
    raise SystemExit(0)

# ─────────────────────────────────────────────────────────────────────────
# 1. Wrap desktop filter form in .appt-desktop-only
# ─────────────────────────────────────────────────────────────────────────
old_form = '<form method="get" action="{{ route(\'tenant.appointments.index\') }}" class="ia-toolbar">'
new_form = '<form method="get" action="{{ route(\'tenant.appointments.index\') }}" class="ia-toolbar appt-desktop-only" id="appt-desktop-form">'
assert s.count(old_form) == 1, f"desktop form count = {s.count(old_form)}"
s = s.replace(old_form, new_form)

# ─────────────────────────────────────────────────────────────────────────
# 2. Wrap desktop result count
# ─────────────────────────────────────────────────────────────────────────
old_count = '<p class="ia-result-count">'
new_count = '<p class="ia-result-count appt-desktop-only">'
assert s.count(old_count) == 1
s = s.replace(old_count, new_count)

# ─────────────────────────────────────────────────────────────────────────
# 3. Wrap desktop table
# ─────────────────────────────────────────────────────────────────────────
old_tbl = '<div class="ia-table-wrap">'
new_tbl = '<div class="ia-table-wrap appt-desktop-only">'
assert s.count(old_tbl) == 1
s = s.replace(old_tbl, new_tbl)

# ─────────────────────────────────────────────────────────────────────────
# 4. Inject mobile filter bar + mobile cards.
#    Anchor: directly after the </form> of the desktop toolbar.
# ─────────────────────────────────────────────────────────────────────────
old_after_form = """  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $status || $payment || $dateFrom || $dateTo || $sort !== 'date_desc')
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>"""

# Compute "has any non-default filter" once for badge + the mobile sheet's clear-all
mobile_block = old_after_form + """

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

{{-- Mobile result header --}}
<div class="appt-mobile-only appt-list-header">
  <span>{{ number_format($total) }} {{ Str::plural('appointment', $total) }} · {{ $currentSortLabel }}</span>
  @if($hasAnyFilter)
    <a href="{{ route('tenant.appointments.index') }}" class="appt-list-clear">Clear</a>
  @endif
</div>"""

assert s.count(old_after_form) == 1, "anchor count not 1"
s = s.replace(old_after_form, mobile_block)

# ─────────────────────────────────────────────────────────────────────────
# 5. Inject mobile card list right after the desktop table-wrap </div>.
#    Anchor: the closing </table></div> followed by pagination check.
# ─────────────────────────────────────────────────────────────────────────
old_after_table = """      </tbody>
    </table>
  </div>

  @if($totalPages > 1)"""

mobile_cards = """      </tbody>
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

  @if($totalPages > 1)"""

assert s.count(old_after_table) == 1, "table-end anchor count not 1"
s = s.replace(old_after_table, mobile_cards)

p.write_text(s)
print("OK 1 (markup injected)")
PY

# ─────────────────────────────────────────────────────────────────────────────
# Add CSS + JS — append to the existing @push('styles') and @push('scripts')
# blocks. Append in a new push block at end of file to keep this patch isolated.
# ─────────────────────────────────────────────────────────────────────────────
python3 <<'PY'
from pathlib import Path
p = Path('resources/views/tenant/appointments/index.blade.php')
s = p.read_text()
if "APPT-LIST-MOBILE-CSS v1" in s:
    print("SKIP 2 (CSS already present)")
else:
    css_js = '''

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
'''
    # Append to end of file (after the existing @endsection / @push blocks)
    p.write_text(s + css_js)
    print("OK 2 (CSS + JS appended)")
PY

echo ""
echo "=== verifying ==="
fail=0
verify() {
  local file="$1" needle="$2" label="$3"
  local n
  n=$(grep -c -F -- "$needle" "$file" 2>/dev/null | tr -d '\n' || true)
  : "${n:=0}"
  if [ "${n:-0}" -ge 1 ] 2>/dev/null; then
    echo "  ✓ $label  (${n}×)"
  else
    echo "  ✗ MISSING: $label"
    fail=1
  fi
}

verify "resources/views/tenant/appointments/index.blade.php" "APPT-LIST-MOBILE v1"        "mobile marker"
verify "resources/views/tenant/appointments/index.blade.php" "appt-mfilter"               "filter bar class"
verify "resources/views/tenant/appointments/index.blade.php" "appt-filter-sheet"          "filter sheet"
verify "resources/views/tenant/appointments/index.blade.php" "appt-cards"                 "card list"
verify "resources/views/tenant/appointments/index.blade.php" "ApptFilter"                 "JS controller"
verify "resources/views/tenant/appointments/index.blade.php" "appt-desktop-only"          "desktop wrappers"

# Blade balance
python3 <<'PY'
import sys
src = open('resources/views/tenant/appointments/index.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush')]
ok = True
for o, c in checks:
    no, nc = src.count(o), src.count(c)
    if no != nc:
        print(f'  ✗ {o}({no}) != {c}({nc})')
        ok = False
    else:
        print(f'  ✓ {o}/{c}: {no}')
if not ok: sys.exit(1)
PY

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "✗ FAIL"
  exit 1
fi

echo ""
echo "✓ all green"
echo ""
echo "Deploy:"
echo "  git add -A && git commit -m 'mobile: appointments list redesign — search bar + filter sheet + card list'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== appointments list mobile complete ==="
