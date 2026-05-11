#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Customer list — mobile redesign.
#
# Replaces the 3-row filter form + 6-col table with:
#   1. Single-row search-and-sort bar (search input, sort icon, + icon)
#   2. Live search (350ms debounce on input)
#   3. Sort opens a bottom sheet with 7 options
#   4. Card-style customer list (name + spend, email + phone, last svc + added)
#   5. + icon reveals the existing new-customer form
#
# Approach: parallel rendering — desktop table + mobile cards both emit;
# CSS hides whichever isn't appropriate for the viewport. Zero backend
# changes. Same $customers loop runs twice (cheap, already in memory).
#
# All scoped to ≤600px. Desktop unchanged.
# ─────────────────────────────────────────────────────────────────────────────

set -e
[ -f artisan ] || { echo "ABORT: not in intake-license repo root"; exit 1; }

echo "=== customer list mobile starting ==="

# Rewrite the customer list page. Single file replacement.
cat > resources/views/tenant/customers/index.blade.php <<'BLADE'
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
  $currentSortLabel = $sortLabels[$sort] ?? 'Name A–Z';
@endphp

@section('content')

{{-- CUSTOMER-LIST-MOBILE v1 — parallel desktop + mobile renders. --}}

{{-- ========== DESKTOP HEAD (hidden on mobile via CSS) ========== --}}
<div class="ia-page-head cust-desktop-only">
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

{{-- ========== MOBILE HEAD (hidden on desktop via CSS) ========== --}}
<div class="cust-mobile-only cust-mobile-head">
  <h1 class="cust-mobile-title">Customers</h1>
  <p class="cust-mobile-sub">{{ number_format($total) }} total</p>
</div>

{{-- ========== NEW CUSTOMER FORM (shared, toggled by either mobile + or desktop button) ========== --}}
<div id="new-customer-card" class="ia-card" style="display:none;margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">New customer</span>
    <button type="button" class="ia-card-action"
      onclick="document.getElementById('new-customer-card').style.display='none';
               var d = document.querySelector('.cust-desktop-only .ia-btn--primary'); if (d) d.style.display='';">
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

{{-- ========== DESKTOP FILTER TOOLBAR (hidden on mobile) ========== --}}
<form method="get" action="{{ route('tenant.customers.index') }}" class="ia-toolbar cust-desktop-only" id="cust-desktop-form">
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

{{-- ========== MOBILE FILTER BAR + SORT SHEET (hidden on desktop) ========== --}}
<form method="get" action="{{ route('tenant.customers.index') }}" class="cust-mobile-only cust-mfilter" id="cust-mobile-form">
  <div class="cust-mfilter-search-wrap">
    <svg class="cust-mfilter-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="search" name="s" class="cust-mfilter-search" value="{{ $search }}"
      placeholder="Search name, email, or phone" autocomplete="off" id="cust-search-mobile">
  </div>
  <input type="hidden" name="sort" id="cust-sort-mobile" value="{{ $sort }}">
  <button type="button" class="cust-mfilter-iconbtn {{ $sort !== 'name_asc' ? 'is-active' : '' }}" onclick="CustSort.open()" aria-label="Sort">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M3 6h18M6 12h12M10 18h4"/>
    </svg>
    @if($sort !== 'name_asc')
      <span class="cust-mfilter-badge" aria-hidden="true"></span>
    @endif
  </button>
  <button type="button" class="cust-mfilter-iconbtn" onclick="document.getElementById('new-customer-card').style.display='block';window.scrollTo({top:0,behavior:'smooth'})" aria-label="Add new customer">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </svg>
  </button>
</form>

{{-- Sort bottom sheet --}}
<div class="cust-sort-sheet-backdrop" id="cust-sort-backdrop" onclick="CustSort.close()" aria-hidden="true"></div>
<div class="cust-sort-sheet" id="cust-sort-sheet" role="dialog" aria-modal="true" aria-label="Sort customers" aria-hidden="true">
  <div class="cust-sort-handle" aria-hidden="true"></div>
  <div class="cust-sort-title">Sort by</div>
  @foreach($sortLabels as $val => $label)
    <button type="button"
            class="cust-sort-row {{ $sort === $val ? 'is-active' : '' }}"
            onclick="CustSort.pick('{{ $val }}')">
      <span>{{ $label }}</span>
      @if($sort === $val)
        <span class="cust-sort-check" aria-hidden="true">✓</span>
      @endif
    </button>
  @endforeach
</div>

{{-- ========== RESULT COUNT (desktop) ========== --}}
<p class="ia-result-count cust-desktop-only">
  <strong>{{ number_format($total) }}</strong> {{ Str::plural('customer', $total) }}
</p>

{{-- ========== MOBILE LIST HEADER (count + current sort) ========== --}}
<div class="cust-mobile-only cust-list-header">
  <span>{{ number_format($total) }} {{ Str::plural('customer', $total) }} · {{ $currentSortLabel }}</span>
</div>

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
  {{-- ========== DESKTOP TABLE ========== --}}
  <div class="ia-table-wrap cust-desktop-only">
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

  {{-- ========== MOBILE CARD LIST ========== --}}
  <div class="cust-mobile-only cust-cards">
    @foreach($customers as $c)
      @php
        $stat = $stats[$c->id] ?? null;
        $spend = (int)($stat?->total_spend_cents ?? 0);
        $lastSvc = $stat?->last_service_date
          ? \Carbon\Carbon::parse($stat->last_service_date)->format('M j')
          : null;
        $contactParts = array_filter([$c->email, $c->phone]);
      @endphp
      <button type="button" class="cust-card" onclick="openDetailModal('customer','{{ $c->id }}')">
        <div class="cust-card-top">
          <span class="cust-card-name">{{ $c->first_name }} {{ $c->last_name }}</span>
          @if($spend > 0)
            <span class="cust-card-spend">{{ format_money($spend) }}</span>
          @endif
        </div>
        @if($contactParts)
          <div class="cust-card-contact">{{ implode(' · ', $contactParts) }}</div>
        @endif
        <div class="cust-card-meta">
          @if($lastSvc)Last service {{ $lastSvc }} · @endif
          Added {{ $c->created_at->format('M j, Y') }}
        </div>
      </button>
    @endforeach
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

@push('scripts')
<script>
(function () {
  // ── Sort sheet open/close + pick ─────────────────────────────────────────
  window.CustSort = {
    open: function () {
      document.getElementById('cust-sort-backdrop').classList.add('is-open');
      document.getElementById('cust-sort-sheet').classList.add('is-open');
      document.getElementById('cust-sort-backdrop').setAttribute('aria-hidden','false');
      document.getElementById('cust-sort-sheet').setAttribute('aria-hidden','false');
      document.body.style.overflow = 'hidden';
    },
    close: function () {
      document.getElementById('cust-sort-backdrop').classList.remove('is-open');
      document.getElementById('cust-sort-sheet').classList.remove('is-open');
      document.getElementById('cust-sort-backdrop').setAttribute('aria-hidden','true');
      document.getElementById('cust-sort-sheet').setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    },
    pick: function (val) {
      document.getElementById('cust-sort-mobile').value = val;
      document.getElementById('cust-mobile-form').submit();
    },
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') CustSort.close();
  });

  // ── Live search (mobile only) ────────────────────────────────────────────
  var searchInput = document.getElementById('cust-search-mobile');
  var form = document.getElementById('cust-mobile-form');
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

@push('styles')
<style>
/* CUSTOMER-LIST-MOBILE v1 ────────────────────────────────────────────────── */

.cust-mobile-only { display: none; }

@media (max-width: 600px) {
  .cust-desktop-only { display: none !important; }
  .cust-mobile-only { display: block; }

  /* Mobile head */
  .cust-mobile-head {
    margin-bottom: 14px;
  }
  .cust-mobile-title {
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
    margin: 0;
  }
  .cust-mobile-sub {
    font-size: 12px;
    color: var(--ia-text-muted);
    margin: 2px 0 0;
  }

  /* Filter bar */
  .cust-mfilter {
    display: grid !important;
    grid-template-columns: 1fr 40px 40px;
    gap: 6px;
    margin-bottom: 14px;
  }
  .cust-mfilter-search-wrap {
    position: relative;
  }
  .cust-mfilter-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--ia-text-muted);
    pointer-events: none;
  }
  .cust-mfilter-search {
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
  .cust-mfilter-search:focus {
    outline: none;
    border-color: var(--ia-accent);
  }
  .cust-mfilter-iconbtn {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ia-text-muted);
    cursor: pointer;
    position: relative;
    -webkit-tap-highlight-color: transparent;
  }
  .cust-mfilter-iconbtn:active { transform: scale(0.95); }
  .cust-mfilter-iconbtn.is-active {
    color: var(--ia-accent);
    border-color: rgba(190,242,100,.3);
    background: var(--ia-accent-soft);
  }
  .cust-mfilter-badge {
    position: absolute;
    top: 4px; right: 4px;
    width: 8px; height: 8px;
    background: var(--ia-accent);
    border-radius: 50%;
    border: 2px solid var(--ia-bg, #0a0a0a);
  }

  /* List header */
  .cust-list-header {
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }

  /* Customer cards */
  .cust-cards {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .cust-card {
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
  .cust-card:active { transform: scale(0.99); }
  .cust-card-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 10px;
  }
  .cust-card-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--ia-text);
    letter-spacing: -.01em;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cust-card-spend {
    font-size: 14px;
    font-weight: 500;
    color: var(--ia-text);
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
  }
  .cust-card-contact {
    font-size: 12px;
    color: var(--ia-text-muted);
    margin-top: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cust-card-meta {
    font-size: 11px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    margin-top: 3px;
  }
}

/* Sort sheet — base styles outside media query so transitions work
   when the sheet opens. Hidden via translate when not .is-open. */
.cust-sort-sheet-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.cust-sort-sheet-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}
.cust-sort-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  padding: 12px 0 calc(24px + env(safe-area-inset-bottom, 0px));
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 88vh;
  overflow-y: auto;
}
.cust-sort-sheet.is-open { transform: translateY(0); }
.cust-sort-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 0 auto 12px;
}
body.ia-theme-b .cust-sort-handle { background: rgba(0,0,0,.18); }
.cust-sort-title {
  padding: 0 20px 12px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
}
.cust-sort-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 14px 20px;
  background: transparent;
  border: none;
  border-top: 0.5px solid var(--ia-border);
  color: var(--ia-text);
  font-size: 15px;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.cust-sort-row:active { background: rgba(255,255,255,.04); }
body.ia-theme-b .cust-sort-row:active { background: rgba(0,0,0,.04); }
.cust-sort-row.is-active { color: var(--ia-accent); }
.cust-sort-check {
  color: var(--ia-accent);
  font-weight: 600;
}

/* Hide the sort sheet entirely on desktop — never reachable */
@media (min-width: 601px) {
  .cust-sort-sheet,
  .cust-sort-sheet-backdrop { display: none !important; }
}
</style>
@endpush

@endsection
BLADE
echo "OK 1 (customer list rewritten)"

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
verify "resources/views/tenant/customers/index.blade.php"  "CUSTOMER-LIST-MOBILE v1"  "marker"
verify "resources/views/tenant/customers/index.blade.php"  "cust-mfilter"             "mobile filter bar class"
verify "resources/views/tenant/customers/index.blade.php"  "cust-cards"               "mobile cards class"
verify "resources/views/tenant/customers/index.blade.php"  "cust-sort-sheet"          "sort sheet"
verify "resources/views/tenant/customers/index.blade.php"  "CustSort"                 "sort JS"
verify "resources/views/tenant/customers/index.blade.php"  "ia-table-wrap"            "desktop table preserved"

# Blade balance
python3 <<'PY'
src = open('resources/views/tenant/customers/index.blade.php').read()
checks = [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@push','@endpush'), ('@for','@endfor')]
import sys
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
echo "Next:"
echo "  git add -A && git commit -m 'mobile: customer list redesign — search bar, sort sheet, card list'"
echo "  git push"
echo "  Server: git pull && php artisan view:clear && \\"
echo "    sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm"
echo ""
echo "=== customer list mobile complete ==="
