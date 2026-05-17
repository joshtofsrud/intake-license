@extends('layouts.tenant.app')
@php
  $pageTitle = 'Vendors';
  $sortLabels = [
    'name_asc'   => 'Name A–Z',
    'name_desc'  => 'Name Z–A',
    'added_desc' => 'Newest first',
    'added_asc'  => 'Oldest first',
  ];
  $currentSortLabel = $sortLabels[$sort] ?? 'Name A–Z';
@endphp

@section('content')

{{-- VENDOR-LIST — parallel desktop + mobile renders matching the
     customers pattern from index.blade.php. --}}

{{-- ========== DESKTOP HEAD ========== --}}
<div class="ia-page-head vendor-desktop-only">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Vendors</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('vendor', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary"
      onclick="document.getElementById('new-vendor-card').style.display='block';this.style.display='none'">
      + New vendor
    </button>
  </div>
</div>

{{-- ========== MOBILE HEAD ========== --}}
<div class="vendor-mobile-only vendor-mobile-head">
  <h1 class="vendor-mobile-title">Vendors</h1>
  <p class="vendor-mobile-sub">{{ number_format($total) }} total</p>
</div>

{{-- ========== FLASH ========== --}}
@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== NEW VENDOR FORM (shared, toggled by desktop button or mobile +) ========== --}}
<div id="new-vendor-card" class="ia-card" style="display:none;margin-bottom:20px">
  <div class="ia-card-head">
    <span class="ia-card-title">New vendor</span>
    <button type="button" class="ia-card-action"
      onclick="document.getElementById('new-vendor-card').style.display='none';
               var d = document.querySelector('.vendor-desktop-only .ia-btn--primary'); if (d) d.style.display='';">
      Cancel
    </button>
  </div>
  <form method="POST" action="{{ route('tenant.vendors.store') }}">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Vendor name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" required value="{{ old('name') }}"
               placeholder="e.g. QBP, Hawley, Amazon Business">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Account number</label>
        <input type="text" name="account_number" class="ia-input" value="{{ old('account_number') }}"
               placeholder="Your account # with this vendor">
      </div>
    </div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Contact email</label>
        <input type="email" name="contact_email" class="ia-input" value="{{ old('contact_email') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Contact phone</label>
        <input type="tel" name="contact_phone" class="ia-input" value="{{ old('contact_phone') }}">
      </div>
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Website</label>
      <input type="text" name="website" class="ia-input" value="{{ old('website') }}"
             placeholder="vendor.com">
    </div>
    <div class="ia-form-group">
      <label class="ia-form-label">Notes</label>
      <textarea name="notes" class="ia-input" rows="2"
                placeholder="Daily cutoff times, rep names, ordering quirks…">{{ old('notes') }}</textarea>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button type="submit" class="ia-btn ia-btn--primary">Save vendor</button>
    </div>
  </form>
</div>

{{-- ========== DESKTOP FILTER TOOLBAR ========== --}}
<form method="get" action="{{ route('tenant.vendors.index') }}" class="ia-toolbar vendor-desktop-only">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
         placeholder="Search vendors by name, email, phone, account #">
  <select name="sort" class="ia-select" onchange="this.form.submit()">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" {{ $sort === $val ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select>
  <button type="submit" class="ia-btn ia-btn--secondary">Search</button>
  @if($search || $sort !== 'name_asc')
    <a href="{{ route('tenant.vendors.index') }}" class="ia-btn ia-btn--ghost">Clear</a>
  @endif
</form>

{{-- ========== MOBILE TOOLBAR ========== --}}
<form method="get" action="{{ route('tenant.vendors.index') }}"
      class="vendor-mobile-only vendor-mfilter" id="vendor-mobile-form">
  <input type="search" name="s" class="vendor-msearch" value="{{ $search }}"
         placeholder="Search vendors" autocomplete="off">
  <button type="button" class="vendor-mfilter-btn {{ $sort !== 'name_asc' ? 'has-dot' : '' }}"
          onclick="VendorSort.open()" aria-label="Sort">⇅</button>
  <input type="hidden" name="sort" id="vendor-sort-mobile" value="{{ $sort }}">
</form>

@if($search || $sort !== 'name_asc')
  <div class="vendor-mobile-only vendor-chips">
    @if($search)
      <a class="vendor-chip" href="{{ route('tenant.vendors.index', array_merge(request()->query(), ['s' => null])) }}">
        “{{ $search }}” <span class="x">×</span>
      </a>
    @endif
    @if($sort !== 'name_asc')
      <a class="vendor-chip muted" href="{{ route('tenant.vendors.index', array_merge(request()->query(), ['sort' => 'name_asc'])) }}">
        {{ $currentSortLabel }} <span class="x">×</span>
      </a>
    @endif
  </div>
@endif

{{-- ========== LIST ========== --}}
@if($vendors->isEmpty())
  <div class="ia-empty">
    <p>No vendors yet.</p>
    <p class="ia-empty-sub">Add the vendors you buy from — QBP, Hawley, Amazon Business, the local distributor. Vendors are required for special orders and useful for tracking receiving.</p>
  </div>
@else

  {{-- Desktop table --}}
  <div class="ia-card vendor-desktop-only">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Vendor</th>
          <th>Contact</th>
          <th>Items sourced</th>
          <th>Open SOs</th>
          <th>Account #</th>
          <th>Added</th>
        </tr>
      </thead>
      <tbody>
        @foreach($vendors as $v)
          <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.vendors.show', ['subdomain' => tenant()->subdomain, 'id' => $v->id]) }}'">
            <td>
              <strong>{{ $v->name }}</strong>
              @if(!$v->is_active)
                <span class="ia-pill ia-pill--muted" style="margin-left:6px">Inactive</span>
              @endif
              @if($v->website)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $v->website }}</div>
              @endif
            </td>
            <td>
              @if($v->contact_email)
                <div>{{ $v->contact_email }}</div>
              @endif
              @if($v->contact_phone)
                <div class="ia-text-muted" style="font-size:12px">{{ $v->contact_phone }}</div>
              @endif
              @if(!$v->contact_email && !$v->contact_phone)
                <span class="ia-text-muted" style="font-size:12px">—</span>
              @endif
            </td>
            <td>{{ (int) ($itemCounts[$v->id] ?? 0) }}</td>
            <td>
              @php $openCount = (int) ($openSoCounts[$v->id] ?? 0); @endphp
              @if($openCount > 0)
                <strong>{{ $openCount }}</strong>
              @else
                <span class="ia-text-muted">—</span>
              @endif
            </td>
            <td>{{ $v->account_number ?: '—' }}</td>
            <td class="ia-text-muted" style="font-size:12px">{{ $v->created_at->format('M j, Y') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- Mobile cards --}}
  <div class="vendor-mobile-only vendor-cards">
    @foreach($vendors as $v)
      <a href="{{ route('tenant.vendors.show', ['subdomain' => tenant()->subdomain, 'id' => $v->id]) }}"
         class="vendor-card">
        <div class="vendor-card-top">
          <span class="vendor-card-name">{{ $v->name }}</span>
          @php $openCount = (int) ($openSoCounts[$v->id] ?? 0); @endphp
          @if($openCount > 0)
            <span class="vendor-card-so">{{ $openCount }} open</span>
          @endif
        </div>
        @php $contactParts = array_filter([$v->contact_email, $v->contact_phone]); @endphp
        @if($contactParts)
          <div class="vendor-card-contact">{{ implode(' · ', $contactParts) }}</div>
        @endif
        <div class="vendor-card-meta">
          {{ (int) ($itemCounts[$v->id] ?? 0) }} {{ Str::plural('item', (int) ($itemCounts[$v->id] ?? 0)) }} sourced
          @if($v->account_number) · #{{ $v->account_number }} @endif
          @if(!$v->is_active) · <em>inactive</em> @endif
        </div>
      </a>
    @endforeach
  </div>

  @if($totalPages > 1)
    <div class="ia-pagination">
      @for($p = 1; $p <= $totalPages; $p++)
        <a href="{{ route('tenant.vendors.index', array_merge(request()->query(), ['page' => $p])) }}"
           class="ia-page-btn {{ $p === $page ? 'active' : '' }}">{{ $p }}</a>
      @endfor
    </div>
  @endif
@endif

{{-- ========== MOBILE SORT SHEET ========== --}}
<div id="vendor-sort-backdrop" class="vendor-sheet-overlay" onclick="VendorSort.close()" aria-hidden="true"></div>
<div id="vendor-sort-sheet" class="vendor-sheet" aria-hidden="true">
  <div class="vendor-sheet-handle"></div>
  <div class="vendor-sheet-title">Sort</div>
  <div class="vendor-sheet-options">
    @foreach($sortLabels as $val => $label)
      <button type="button" class="vendor-sheet-option {{ $sort === $val ? 'active' : '' }}"
              onclick="VendorSort.pick('{{ $val }}')">{{ $label }}</button>
    @endforeach
  </div>
  <button type="button" class="vendor-sheet-secondary" onclick="VendorSort.close()">Close</button>
</div>

@push('scripts')
<script>
(function () {
  window.VendorSort = {
    open: function () {
      document.getElementById('vendor-sort-backdrop').classList.add('is-open');
      document.getElementById('vendor-sort-sheet').classList.add('is-open');
      document.body.style.overflow = 'hidden';
    },
    close: function () {
      document.getElementById('vendor-sort-backdrop').classList.remove('is-open');
      document.getElementById('vendor-sort-sheet').classList.remove('is-open');
      document.body.style.overflow = '';
    },
    pick: function (val) {
      document.getElementById('vendor-sort-mobile').value = val;
      document.getElementById('vendor-mobile-form').submit();
    },
  };
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') VendorSort.close();
  });
})();
</script>
@endpush

@push('styles')
<style>
/* VENDOR-LIST — desktop/mobile parallel styles.
   Matches the pattern from customers/index.blade.php to keep
   visual language consistent across the admin. */

/* Default: desktop shows, mobile hides */
.vendor-mobile-only { display: none; }

@media (max-width: 700px) {
  .vendor-desktop-only { display: none; }
  .vendor-mobile-only  { display: block; }

  .vendor-mobile-head { padding: 16px 0 12px; }
  .vendor-mobile-title { font-size: 22px; font-weight: 600; margin: 0; color: var(--ia-text); }
  .vendor-mobile-sub { font-size: 12px; color: var(--ia-text-muted); margin: 2px 0 0; }

  .vendor-mfilter { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
  .vendor-msearch {
    flex: 1; padding: 10px 12px;
    background: var(--ia-surface); border: 0.5px solid var(--ia-border);
    border-radius: 10px; color: var(--ia-text); font-size: 14px;
    font-family: inherit; outline: none;
  }
  .vendor-mfilter-btn {
    width: 40px; height: 40px; border-radius: 10px;
    background: var(--ia-surface); border: 0.5px solid var(--ia-border);
    color: var(--ia-text-muted); display: inline-flex;
    align-items: center; justify-content: center;
    position: relative; cursor: pointer; font-family: inherit;
  }
  .vendor-mfilter-btn.has-dot::after {
    content: ''; position: absolute; top: 7px; right: 7px;
    width: 7px; height: 7px; background: var(--ia-accent); border-radius: 50%;
  }

  .vendor-chips {
    display: flex; gap: 6px; margin-bottom: 12px;
    overflow-x: auto; scrollbar-width: none; padding-bottom: 2px;
  }
  .vendor-chips::-webkit-scrollbar { display: none; }
  .vendor-chip {
    flex-shrink: 0; padding: 5px 11px; border-radius: 999px;
    background: var(--ia-surface); border: 0.5px solid var(--ia-border);
    color: var(--ia-text); font-size: 12px;
    display: inline-flex; align-items: center; gap: 4px;
    text-decoration: none; font-family: inherit;
  }
  .vendor-chip.muted { color: var(--ia-text-muted); }
  .vendor-chip .x { opacity: 0.6; padding-left: 2px; }

  .vendor-cards { display: flex; flex-direction: column; gap: 8px; }
  .vendor-card {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 14px 16px;
    text-decoration: none; color: inherit;
    display: block;
  }
  .vendor-card-top {
    display: flex; align-items: center; justify-content: space-between;
    gap: 10px; margin-bottom: 4px;
  }
  .vendor-card-name { font-size: 15px; font-weight: 600; color: var(--ia-text); }
  .vendor-card-so {
    font-size: 11.5px; font-weight: 600;
    color: var(--ia-text);
    background: rgba(190,242,100,0.10);
    padding: 2px 8px; border-radius: 99px;
  }
  .vendor-card-contact { font-size: 12.5px; color: var(--ia-text-muted); margin-bottom: 4px; }
  .vendor-card-meta { font-size: 11.5px; color: var(--ia-text-muted); }
}

/* Mobile filter sheet — shared pattern with customers */
.vendor-sheet-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.55); z-index: 90;
  opacity: 0; pointer-events: none; transition: opacity .15s;
}
.vendor-sheet-overlay.is-open { opacity: 1; pointer-events: all; display: block; }
.vendor-sheet {
  display: none; position: fixed; bottom: 0; left: 0; right: 0;
  background: var(--ia-bg, #0a0a0a);
  border-radius: 18px 18px 0 0;
  padding: 12px 16px calc(20px + env(safe-area-inset-bottom, 0px));
  z-index: 91; border-top: 0.5px solid var(--ia-border);
  transform: translateY(100%); transition: transform .2s ease;
  max-height: 80%; overflow-y: auto;
}
.vendor-sheet.is-open { display: block; transform: translateY(0); }
.vendor-sheet-handle {
  width: 36px; height: 4px; border-radius: 2px;
  background: rgba(255,255,255,0.2); margin: 0 auto 14px;
}
.vendor-sheet-title { font-size: 16px; font-weight: 600; margin-bottom: 16px; color: var(--ia-text); }
.vendor-sheet-options { display: flex; flex-wrap: wrap; gap: 6px; }
.vendor-sheet-option {
  padding: 8px 14px; border-radius: 8px;
  background: var(--ia-surface); border: 0.5px solid var(--ia-border);
  color: var(--ia-text); font-size: 13px; cursor: pointer;
  font-family: inherit;
}
.vendor-sheet-option.active {
  background: var(--ia-accent); color: #000; border-color: var(--ia-accent);
}
.vendor-sheet-secondary {
  width: 100%; padding: 12px; background: transparent;
  color: var(--ia-text-muted); border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md); font-size: 14px;
  margin-top: 18px; cursor: pointer; font-family: inherit;
  text-align: center;
}
</style>
@endpush

@endsection
