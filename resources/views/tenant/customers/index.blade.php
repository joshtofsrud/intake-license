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
    'vips_only'    => 'VIPs only',
    'businesses_only' => 'Businesses only', // MARKER-BIZ-LIST
    'has_account'  => 'Has portal account',   // MARKER-CUST-ACCOUNT
    'no_account'   => 'No portal account',    // MARKER-CUST-ACCOUNT
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
  <form method="POST" action="{{ route('tenant.customers.store') }}" data-biz-form>
    @csrf

    {{-- MARKER-BIZ-CUSTOMER — individual is the default, so this form opens
         exactly as it always has. Choosing Business reveals the extra fields
         and relaxes the person-name requirement. --}}
    @php $bizDefaults = tenant()->settings['customers'] ?? []; @endphp
    <div class="ia-form-group">
      <label class="ia-form-label">Customer type</label>
      <div class="biz-type-row">
        <label class="biz-type">
          <input type="radio" name="customer_type" value="individual" @checked(old('customer_type', 'individual') !== 'business')>
          <span>Individual</span>
        </label>
        <label class="biz-type">
          <input type="radio" name="customer_type" value="business" @checked(old('customer_type') === 'business')>
          <span>Business</span>
        </label>
      </div>
    </div>

    <div data-biz-only style="display:none">
      <div class="ia-form-group">
        <label class="ia-form-label">Business name <span class="ia-required">*</span></label>
        <input type="text" name="business_name" class="ia-input" value="{{ old('business_name') }}"
               placeholder="Spokane Public Schools">
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Payment terms</label>
          <select name="payment_terms" class="ia-input">
            <option value="">Due at service</option>
            <option value="net_15" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_15')>Net 15</option>
            <option value="net_30" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_30')>Net 30</option>
            <option value="net_60" @selected(old('payment_terms', $bizDefaults['default_payment_terms'] ?? '') === 'net_60')>Net 60</option>
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Purchase order</label>
          <label class="biz-check">
            <input type="checkbox" name="po_required" value="1"
                   @checked(old('po_required', ($bizDefaults['default_po_required'] ?? false) ? '1' : ''))>
            <span>Requires a PO number</span>
          </label>
        </div>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Tax status</label>
          <label class="biz-check">
            <input type="checkbox" name="tax_exempt" value="1" data-biz-exempt @checked(old('tax_exempt'))>
            <span>Tax exempt</span>
          </label>
        </div>
        <div class="ia-form-group" data-biz-cert style="display:none">
          <label class="ia-form-label">Exemption certificate #</label>
          <input type="text" name="tax_exempt_certificate" class="ia-input" value="{{ old('tax_exempt_certificate') }}">
        </div>
      </div>
    </div>

    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label"><span data-biz-namelabel>First name</span> <span class="ia-required" data-biz-req>*</span></label>
        <input type="text" name="first_name" class="ia-input" required value="{{ old('first_name') }}" data-biz-name>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Last name <span class="ia-required" data-biz-req>*</span></label>
        <input type="text" name="last_name" class="ia-input" required value="{{ old('last_name') }}" data-biz-name>
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
<style>
.cust-resource-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  margin-bottom: 16px;
  background: var(--ia-surface-2, rgba(255,255,255,0.03));
  border: 0.5px solid var(--ia-border);
  border-radius: 999px;
  font-size: 13px;
  color: var(--ia-text-2);
}
.cust-resource-chip strong { color: var(--ia-text); }
.cust-resource-clear {
  margin-left: 6px;
  color: var(--ia-text-3);
  text-decoration: none;
  font-size: 11px;
}
.cust-resource-clear:hover { color: var(--ia-accent, #BEF264); }
</style>

{{-- MARKER-PATCH-114 - created_after filter chip --}}
@if(!empty($createdAfter))
  <div class="cust-resource-chip">
    Showing customers added since
    <strong>{{ \Carbon\Carbon::parse($createdAfter)->format('M j, Y') }}</strong>
    <a href="{{ route('tenant.customers.index') }}" class="cust-resource-clear">clear ×</a>
  </div>
@endif

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
  {{-- MARKER-CONSENT-CLEANUP — say plainly which way the switch works today,
       since "on" and "off" have different rules. --}}
  @if(tenant()->consentCleanupOpen())
    <div style="border:1px solid var(--ia-accent);background:var(--ia-accent-soft);border-radius:8px;padding:9px 12px;font-size:12.5px;margin-bottom:12px">
      Consent cleanup is open until {{ \Carbon\Carbon::parse(tenant()->consent_cleanup_until)->format('M j, g:ia') }}.
      You can switch marketing consent on or off here and remove customers; each change is recorded against your account.
      Removing deletes a customer outright only when nothing references them — otherwise their personal details are erased and they're hidden, because sales and bookings still need the record.
    </div>
  @else
    <div style="font-size:11.5px;opacity:.45;margin-bottom:10px">
      Marketing consent can be switched off here at any time. Switching it on needs an attestation — ask Intake to open a cleanup window.
    </div>
  @endif

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
          <th title="Accepts marketing email">Marketing</th>{{-- MARKER-CONSENT-CLEANUP --}}
          @if(tenant()->customerAdminOpen())<th></th>@endif{{-- MARKER-CUST-ADMIN --}}
        </tr>
      </thead>
      <tbody>
        @foreach($customers as $c)
          @php $stat = $stats[$c->id] ?? null; @endphp
          {{-- MARKER-PATCH-503 — straight to the customer page, no modal hop --}}
          <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.customers.show', $c->id) }}'">
            <td>
              <span style="font-weight:500">{{ $c->fullName() }}</span>@if($c->is_vip)<span class="vip-list-star" title="VIP">★</span>@endif
              {{-- MARKER-BIZ-LIST --}}
              @if($c->isBusiness())
                <span class="biz-pill">Business</span>
                @if($c->tax_exempt)<span class="biz-pill exempt">Tax exempt</span>@endif
              @endif
              {{-- MARKER-CUST-ACCOUNT --}}
              @if($c->password)<span class="biz-pill acct-pill" title="Has a portal account">Account</span>@endif
            </td>
            <td class="ia-muted-cell">{{ $c->email }}</td>
            <td class="ia-muted-cell">{{ $c->phone ?: '—' }}</td>
            <td class="ia-muted-cell">
              {{ $stat?->last_service_date ? \Carbon\Carbon::parse($stat->last_service_date)->format('M j, Y') : '—' }}
            </td>
            <td class="ia-num">{{ format_money((int)($stat?->total_spend_cents ?? 0)) }}</td>
            <td class="ia-muted-cell">{{ $c->created_at->format('M j, Y') }}</td>
            {{-- MARKER-CONSENT-CLEANUP — the row is a link, so this cell swallows
                 its own clicks. --}}
            <td onclick="event.stopPropagation()">
              @php $accepts = $c->emailMarketingMailable(); @endphp
              <label class="cm-toggle" title="{{ $accepts ? 'Accepts marketing email' : 'Not opted in' }}">
                <input type="checkbox" {{ $accepts ? 'checked' : '' }}
                  {{ $c->email ? '' : 'disabled' }}
                  onchange="cmToggle(this, '{{ $c->id }}')">
                <span></span>
              </label>
            </td>
            {{-- MARKER-CUST-ADMIN --}}
            @if(tenant()->customerAdminOpen())
              <td onclick="event.stopPropagation()">
                <button type="button" class="cm-remove" title="Remove customer"
                  onclick="cmRemove('{{ $c->id }}')">Remove</button>
              </td>
            @endif
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
      <button type="button" class="cust-card" onclick="window.location.href='{{ route('tenant.customers.show', $c->id) }}'">
        <div class="cust-card-top">
          <span class="cust-card-name">{{ $c->fullName() }}</span>
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
          @if($c->password) · Account @endif{{-- MARKER-CUST-ACCOUNT --}}
        </div>
      </button>
      {{-- MARKER-CUST-ADMIN-FIX — these were desktop-only, with nothing on
           mobile saying so. Outside the card button: it navigates on click. --}}
      <div class="cust-card-admin">
        <label class="cm-toggle" title="{{ $c->emailMarketingMailable() ? 'Accepts marketing email' : 'Not opted in' }}">
          <input type="checkbox" {{ $c->emailMarketingMailable() ? 'checked' : '' }}
            {{ $c->email ? '' : 'disabled' }}
            onchange="cmToggle(this, '{{ $c->id }}')">
          <span></span>
        </label>
        <span class="cust-card-admin-lbl">Marketing</span>
        @if(tenant()->customerAdminOpen())
          <button type="button" class="cm-remove" style="margin-left:auto"
            onclick="cmRemove('{{ $c->id }}')">Remove</button>
        @endif
      </div>
    @endforeach
  </div>

  @if($totalPages > 1)
    {{-- MARKER-PATCH-368 — windowed pager (prev/next + ellipses) replaces the full 1..N wall. --}}
    @php
      $pgUrl     = fn($p) => route('tenant.customers.index', array_merge(request()->query(), ['page' => $p]));
      $winStart  = max(1, $page - 2);
      $winEnd    = min($totalPages, $page + 2);
      $shownFrom = $total > 0 ? ($page - 1) * 25 + 1 : 0;
      $shownTo   = min($page * 25, $total);
    @endphp
    <div class="ia-pagination" role="navigation" aria-label="Customer pages">
      @if($page > 1)
        <a href="{{ $pgUrl($page - 1) }}" class="ia-page-btn" rel="prev" aria-label="Previous page">&lsaquo;</a>
      @else
        <span class="ia-page-btn is-disabled" aria-disabled="true">&lsaquo;</span>
      @endif

      @if($winStart > 1)
        <a href="{{ $pgUrl(1) }}" class="ia-page-btn">1</a>
        @if($winStart > 2)<span class="ia-page-ellipsis">&hellip;</span>@endif
      @endif

      @for($p = $winStart; $p <= $winEnd; $p++)
        <a href="{{ $pgUrl($p) }}" class="ia-page-btn {{ $p === $page ? 'active' : '' }}"@if($p === $page) aria-current="page"@endif>{{ $p }}</a>
      @endfor

      @if($winEnd < $totalPages)
        @if($winEnd < $totalPages - 1)<span class="ia-page-ellipsis">&hellip;</span>@endif
        <a href="{{ $pgUrl($totalPages) }}" class="ia-page-btn">{{ $totalPages }}</a>
      @endif

      @if($page < $totalPages)
        <a href="{{ $pgUrl($page + 1) }}" class="ia-page-btn" rel="next" aria-label="Next page">&rsaquo;</a>
      @else
        <span class="ia-page-btn is-disabled" aria-disabled="true">&rsaquo;</span>
      @endif
    </div>
    <div class="cust-page-count">Showing {{ number_format($shownFrom) }}&ndash;{{ number_format($shownTo) }} of {{ number_format($total) }}</div>
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

/* MARKER-PATCH-368 — windowed pager extras */
.ia-page-btn.is-disabled { opacity: .35; pointer-events: none; }
.ia-page-ellipsis { display: inline-flex; align-items: center; padding: 0 4px; color: var(--ia-text-3, #888); font-size: 12px; }
.cust-page-count { margin-top: 8px; font-size: 11.5px; color: var(--ia-text-3, #888); }
</style>
@endpush


{{-- MARKER-BIZ-CUSTOMER — inside the section: Blade discards markup placed
     after @endsection. --}}
<style>
  .biz-type-row{display:flex;gap:8px}
  .biz-type{flex:1;display:flex;align-items:center;gap:8px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:11px 13px;cursor:pointer;font-size:13.5px}
  .biz-type:has(input:checked){border-color:var(--ia-accent);background:color-mix(in srgb, var(--ia-accent) 10%, transparent)}
  .biz-check{display:flex;align-items:center;gap:8px;font-size:13px;padding:10px 0}
</style>
<script>
(function () {
  function sync(form) {
    var isBiz = !!form.querySelector('input[name="customer_type"][value="business"]:checked');
    var only  = form.querySelector('[data-biz-only]');
    if (only) only.style.display = isBiz ? '' : 'none';

    // A business is identified by its business name, so the person's name
    // stops being required — matching the server-side rule exactly.
    form.querySelectorAll('[data-biz-name]').forEach(function (i) { i.required = !isBiz; });
    form.querySelectorAll('[data-biz-req]').forEach(function (r) { r.style.display = isBiz ? 'none' : ''; });
    var lbl = form.querySelector('[data-biz-namelabel]');
    if (lbl) lbl.textContent = isBiz ? 'Contact first name' : 'First name';

    var ex   = form.querySelector('[data-biz-exempt]');
    var cert = form.querySelector('[data-biz-cert]');
    if (cert) cert.style.display = (isBiz && ex && ex.checked) ? '' : 'none';
  }

  document.querySelectorAll('[data-biz-form]').forEach(function (form) {
    form.addEventListener('change', function (e) {
      if (e.target.name === 'customer_type' || e.target.hasAttribute('data-biz-exempt')) sync(form);
    });
    sync(form);
  });
})();
</script>

{{-- MARKER-BIZ-LIST --}}
<style>
  .biz-pill{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;border-radius:100px;padding:2px 7px;margin-left:6px;border:0.5px solid var(--ia-border);color:var(--ia-text-muted);vertical-align:1px}
  .biz-pill.exempt{border-color:rgba(232,163,61,.4);color:#E8A33D}
  /* MARKER-CUST-ACCOUNT */
  .biz-pill.acct-pill{border-color:var(--ia-accent);color:var(--ia-accent)}
</style>

@endsection


{{-- MARKER-CONSENT-CLEANUP --}}
<style>
  .cm-toggle { display:inline-flex; cursor:pointer; }
  .cm-toggle input { display:none; }
  .cm-toggle span {
    width:34px; height:19px; border-radius:999px; background:rgba(255,255,255,.14);
    position:relative; transition:background .12s; display:block;
  }
  .cm-toggle span::after {
    content:''; position:absolute; top:2px; left:2px; width:15px; height:15px;
    border-radius:50%; background:#fff; transition:transform .12s;
  }
  .cm-toggle input:checked + span { background: var(--ia-accent); }
  .cm-toggle input:checked + span::after { transform: translateX(15px); }
  .cm-toggle input:disabled + span { opacity:.3; cursor:not-allowed; }
</style>
<script>
function cmToggle(el, id) {
  var on = el.checked;
  el.disabled = true;
  fetch('{{ url('admin/customers') }}/' + id + '/marketing-toggle', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json'
    },
    body: JSON.stringify({ on: on ? 1 : 0 })
  })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      el.disabled = false;
      if (!j || j.success === false) {
        el.checked = !on; // put it back — nothing was saved
        IntakeConfirm.alert({
          title: 'Not changed',
          message: (j && j.message) || 'Could not update marketing consent.'
        });
      }
    })
    .catch(function () {
      el.disabled = false;
      el.checked = !on;
      IntakeConfirm.alert({ title: 'Not changed', message: "Couldn't reach the server — check your connection." });
    });
}
</script>


{{-- MARKER-CUST-ADMIN --}}
<style>
  .cm-remove {
    font-size: 11px; padding: 3px 9px; border-radius: 5px; cursor: pointer;
    border: 0.5px solid var(--ia-border); background: none; color: var(--ia-text-dim, rgba(255,255,255,.55));
  }
  .cm-remove:hover { border-color: #E0573E; color: #E0573E; }
</style>
<script>
// Ask the server what removal would actually do, then say so plainly before
// anything happens — delete and erase are different enough to spell out.
function cmRemove(id) {
  fetch('{{ url('admin/customers') }}/' + id + '/removal-preview', {
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j || !j.success) {
        IntakeConfirm.alert({ title: 'Could not check', message: 'Please try again.' });
        return;
      }

      var msg;
      if (j.mode === 'delete') {
        msg = 'Nothing references ' + j.name + ', so they will be deleted outright. This cannot be undone.';
      } else {
        var parts = [];
        for (var k in j.links) { parts.push(j.links[k] + ' ' + k); }
        msg = j.name + ' is referenced by ' + parts.join(', ') + '. Those records need the customer row, so instead '
            + 'their personal details are erased and they are hidden from your customer list, search and campaigns. '
            + 'This cannot be undone.';
      }

      IntakeConfirm.show({
        title: j.mode === 'delete' ? 'Delete this customer?' : 'Erase and hide this customer?',
        message: msg,
        confirmText: j.mode === 'delete' ? 'Delete' : 'Erase',
        danger: true
      }).then(function (ok) {
        if (!ok) return;
        fetch('{{ url('admin/customers') }}/' + id + '/remove', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
          },
          body: '{}'
        })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (res && res.success) { window.location.reload(); return; }
            IntakeConfirm.alert({ title: 'Not removed', message: (res && res.message) || 'Please try again.' });
          })
          .catch(function () {
            IntakeConfirm.alert({ title: 'Not removed', message: "Couldn't reach the server." });
          });
      });
    })
    .catch(function () {
      IntakeConfirm.alert({ title: 'Could not check', message: "Couldn't reach the server." });
    });
}
</script>


{{-- MARKER-CUST-ADMIN-FIX --}}
<style>
  .cust-card-admin {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px 12px; margin-top: -6px;
  }
  .cust-card-admin-lbl { font-size: 11.5px; opacity: .5; }
</style>
