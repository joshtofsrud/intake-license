@extends('layouts.tenant.app')
@php $pageTitle = 'Lease Packages'; @endphp

{{-- MARKER-RENTAL-PACKAGES-STUDIO: lease package builder in the Studio design.
     A slot = category + size filter + quantity; packages own no units (they're
     pulled from the fleet when a lease is created). --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <div class="rf-crumb"><a href="{{ route('tenant.rentals.leases.index') }}">Leases</a> / Packages</div>
      <h1 class="sd-hi">Lease packages</h1>
      <p class="sd-sub">Season-long tiers that pull from your fleet. Define them once, fill them at the counter.</p>
    </div>
    <div class="sd-head-r">
      <a href="{{ route('tenant.rentals.leases.index') }}" class="ia-btn ia-btn--sm">All leases</a>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-pkg-new>New package</button>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'leases'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  @if($packages->isEmpty())
    <div class="sd-empty sd-in">
      No lease packages yet. Create your first tier — like “Junior Complete” — to start leasing.
      <div class="sd-empty-acts"><button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-pkg-new>New package</button></div>
    </div>
  @else
    <div class="ls-pkgs">
      @foreach($packages as $pkg)
        <section class="ls-pkg sd-in">
          <div class="ls-pkg-h">
            <div><h2>{{ $pkg->name }}</h2>@if($pkg->subtitle)<small>{{ $pkg->subtitle }}</small>@endif</div>
            <button type="button" class="ia-btn ia-btn--sm" data-pkg-edit
              data-id="{{ $pkg->id }}" data-name="{{ $pkg->name }}" data-subtitle="{{ $pkg->subtitle }}"
              data-price="{{ number_format($pkg->season_price_cents / 100, 2, '.', '') }}" data-deposit="{{ number_format($pkg->deposit_cents / 100, 2, '.', '') }}">Edit</button>
          </div>
          <div class="ls-price">{{ format_money($pkg->season_price_cents) }} <span>/ season · {{ format_money($pkg->deposit_cents) }} deposit hold</span></div>

          <div style="margin-top:10px">
            @forelse($pkg->slots as $slot)
              @php $free = $slotFree[$slot->id] ?? 0; @endphp
              <div class="ls-slotline">
                <span class="ls-qty">{{ $slot->quantity }}</span>
                <div><b>{{ $slot->category->name ?? '—' }}</b>@if($slot->size_filter)<small>{{ $slot->size_filter }}</small>@endif</div>
                <span class="ls-free {{ $free < $slot->quantity ? 'low' : '' }}">{{ $free }} free</span>
                <form method="POST" action="{{ route('tenant.rentals.leases.packages.slots.remove', ['id' => $pkg->id, 'slotId' => $slot->id]) }}" data-slot-remove style="display:contents">
                  @csrf @method('DELETE')
                  <button type="submit" class="rf-ib" aria-label="Remove slot">×</button>
                </form>
              </div>
            @empty
              <div class="sd-empty">No slots yet — add what this package includes.</div>
            @endforelse
          </div>

          <form method="POST" action="{{ route('tenant.rentals.leases.packages.slots.add', ['id' => $pkg->id]) }}" class="ls-addslot">
            @csrf
            <label><span class="rf-lbl">Qty</span><input class="ia-input" type="number" name="quantity" value="1" min="1" max="20"></label>
            <label><span class="rf-lbl">Category</span>
              <select class="ia-input" name="category_id" required>
                <option value="">—</option>
                @foreach($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
              </select>
            </label>
            <label><span class="rf-lbl">Size filter</span><input class="ia-input" name="size_filter" placeholder="100–140 cm"></label>
            <button type="submit" class="ia-btn ia-btn--sm">Add slot</button>
          </form>
        </section>
      @endforeach
    </div>
  @endif

  <p class="sd-legend">A package never owns units. Slots describe what's needed; the units themselves stay in your fleet, shared with rentals, and are pulled at the counter when a lease is created. “Free” is live fleet availability right now. Editing a package doesn't change leases already created from it.</p>
</div>

{{-- ============ new / edit package drawer (outside .sd so the page's motion can't pin it) ============ --}}
<div class="rf-drawers" id="rfDrawers">
  <div class="rf-scrim" id="rfScrim" hidden></div>
  <aside class="rf-drawer" id="d-pkg" aria-hidden="true" role="dialog" aria-label="Lease package">
    <form method="POST" id="lp-form" action="{{ route('tenant.rentals.leases.packages.store') }}" style="display:contents">
      @csrf
      <input type="hidden" name="_method" id="lp-method" value="POST">
      <div class="rf-dh"><div><h3 id="lp-title">New package</h3><span id="lp-sub">A season-long tier</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
      <div class="rf-db">
        <div class="rf-fg">
          <label class="rf-c4"><span class="rf-lbl">Name</span><input class="ia-input" name="name" id="lp-name" placeholder="Junior Complete" required></label>
          <label class="rf-c4"><span class="rf-lbl">Subtitle (optional)</span><input class="ia-input" name="subtitle" id="lp-subtitle" placeholder="Ages 12 &amp; under"></label>
          <label class="rf-c2"><span class="rf-lbl">Season price ($)</span><input class="ia-input" type="number" step="0.01" min="0" name="season_price" id="lp-price" placeholder="199.00" required></label>
          <label class="rf-c2"><span class="rf-lbl">Deposit hold ($)</span><input class="ia-input" type="number" step="0.01" min="0" name="deposit" id="lp-deposit" placeholder="120.00"></label>
        </div>
        <p class="rf-fine" style="text-align:left;margin-top:16px">Slots are added on the package itself once it's saved.</p>
      </div>
      <div class="rf-df"><span class="sp"></span><button type="button" class="ia-btn" data-close>Cancel</button><button type="submit" class="ia-btn ia-btn--primary">Save package</button></div>
    </form>
  </aside>
</div>
@endsection

@push('scripts')
@include('layouts.tenant._rental-boot')
<script>
(function () {
  'use strict';
  function $(s, e) { return (e || document).querySelector(s); }
  var drawer = $('#d-pkg'), scrim = $('#rfScrim'), form = $('#lp-form'), lastFocus = null;
  var storeUrl = '{{ route('tenant.rentals.leases.packages.store') }}', baseUrl = '{{ route('tenant.rentals.leases.packages') }}';

  function open(mode, d) {
    lastFocus = document.activeElement;
    var edit = mode === 'edit';
    form.action = edit ? baseUrl + '/' + d.id : storeUrl;
    $('#lp-method').value = edit ? 'PATCH' : 'POST';
    $('#lp-title').textContent = edit ? 'Edit package' : 'New package';
    $('#lp-sub').textContent = edit ? d.name : 'A season-long tier';
    $('#lp-name').value = edit ? d.name : ''; $('#lp-subtitle').value = edit ? (d.subtitle || '') : '';
    $('#lp-price').value = edit ? d.price : ''; $('#lp-deposit').value = edit ? d.deposit : '';
    scrim.hidden = false;
    requestAnimationFrame(function () { scrim.classList.add('on'); drawer.classList.add('on'); });
    drawer.setAttribute('aria-hidden', 'false');
    setTimeout(function () { if (innerWidth > 900) $('#lp-name').focus({ preventScroll: true }); }, 340);
  }
  function close() {
    drawer.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); scrim.classList.remove('on');
    setTimeout(function () { scrim.hidden = true; }, 260);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
  }
  document.addEventListener('click', function (e) {
    var n = e.target.closest('[data-pkg-new]'); if (n) { open('new'); return; }
    var ed = e.target.closest('[data-pkg-edit]'); if (ed) { open('edit', ed.dataset); return; }
    if (e.target.closest('[data-close]') || e.target === scrim) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('on')) close(); });

  // removing a slot asks first, in the app's own dialog
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('[data-slot-remove]'); if (!f) return;
    if (f.dataset.ok === '1') return;
    e.preventDefault();
    IntakeConfirm.show({
      title: 'Remove this slot?',
      message: 'Leases already created from the package keep their units. New leases won’t include this slot.',
      confirmText: 'Remove', danger: true
    }).then(function (ok) { if (ok) { f.dataset.ok = '1'; f.submit(); } });
  });
})();
</script>
@endpush
