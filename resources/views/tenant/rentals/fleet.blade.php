@extends('layouts.tenant.app')
@php $pageTitle = 'Fleet'; @endphp

{{-- MARKER-PATCH-218 — Fleet admin: categories & rates, units, condition checklists. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Fleet</h1>
    <p class="ia-page-subtitle">Categories set the rate card and deposit. Units are the individual things customers take out the door.</p>
  </div>
  <a href="{{ route('tenant.rentals.desk') }}" class="ia-btn">Rental Desk</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif

{{-- ============================================== categories & rates --}}
<div class="ia-card" style="padding:18px 20px;margin-bottom:20px">
  <h2 class="ia-h3" style="margin-bottom:12px">Add a category</h2>
  <form method="POST" action="{{ route('tenant.rentals.fleet.categories.store') }}">
    @csrf
    <div style="display:grid;grid-template-columns:1.6fr repeat(4, 1fr) auto;gap:10px;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Mountain bikes" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Hourly $</label>
        <input type="number" name="hourly_rate" min="0" step="0.01" placeholder="—" class="ia-input" style="width:100%;text-align:right">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Daily $</label>
        <input type="number" name="daily_rate" min="0" step="0.01" placeholder="—" class="ia-input" style="width:100%;text-align:right">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Weekend $</label>
        <input type="number" name="weekend_rate" min="0" step="0.01" placeholder="—" class="ia-input" style="width:100%;text-align:right">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Deposit $</label>
        <input type="number" name="deposit" min="0" step="0.01" placeholder="0" class="ia-input" style="width:100%;text-align:right">
      </div>
      <div><button type="submit" class="ia-btn ia-btn--primary">Add</button></div>
    </div>
  </form>
</div>

<div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:28px">
  <div style="padding:14px 20px;border-bottom:0.5px solid var(--ia-border);display:flex;justify-content:space-between;align-items:center">
    <span class="ia-label">{{ $categories->count() }} categor{{ $categories->count() === 1 ? 'y' : 'ies' }}</span>
    <span style="font-size:11px;opacity:.5">Rates blank = not offered at that duration · Edits save on blur</span>
  </div>

  @if($categories->isEmpty())
    <div class="ia-empty" style="padding:36px;text-align:center">
      <div class="ia-empty-title">No categories yet</div>
      <div class="ia-empty-body" style="margin-top:6px">Categories carry the rate card — add one above, then add units into it.</div>
    </div>
  @else
    @foreach($categories as $cat)
      <div data-kind="category" data-url="{{ route('tenant.rentals.fleet.categories.update', $cat->id) }}" data-destroy="{{ route('tenant.rentals.fleet.categories.destroy', $cat->id) }}"
           style="display:grid;grid-template-columns:1.6fr repeat(4, 1fr) auto auto;gap:10px;align-items:center;padding:12px 20px;border-bottom:0.5px solid var(--ia-border)">
        <input type="text" class="ia-input fl-edit" data-field="name" value="{{ $cat->name }}" maxlength="120" style="width:100%">
        <input type="number" class="ia-input fl-edit" data-field="hourly_rate" min="0" step="0.01" placeholder="—"
               value="{{ $cat->hourly_rate_cents !== null ? number_format($cat->hourly_rate_cents / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
        <input type="number" class="ia-input fl-edit" data-field="daily_rate" min="0" step="0.01" placeholder="—"
               value="{{ $cat->daily_rate_cents !== null ? number_format($cat->daily_rate_cents / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
        <input type="number" class="ia-input fl-edit" data-field="weekend_rate" min="0" step="0.01" placeholder="—"
               value="{{ $cat->weekend_rate_cents !== null ? number_format($cat->weekend_rate_cents / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
        <input type="number" class="ia-input fl-edit" data-field="deposit" min="0" step="0.01" placeholder="0"
               value="{{ number_format(($cat->deposit_cents ?? 0) / 100, 2, '.', '') }}" style="width:100%;text-align:right">
        <span style="font-size:11.5px;opacity:.55;white-space:nowrap">{{ $cat->units_count }} unit{{ $cat->units_count === 1 ? '' : 's' }}</span>
        <button type="button" class="ia-btn fl-archive" title="Archive category">Archive</button>
      </div>
    @endforeach
  @endif
</div>

{{-- ============================================== units --}}
<div class="ia-card" style="padding:18px 20px;margin-bottom:20px">
  <h2 class="ia-h3" style="margin-bottom:12px">Add a unit</h2>
  @if($categories->isEmpty())
    <p style="font-size:13px;opacity:.6">Add a category first — every unit belongs to one.</p>
  @else
  <form method="POST" action="{{ route('tenant.rentals.fleet.units.store') }}">
    @csrf
    <div style="display:grid;grid-template-columns:1.6fr 1.2fr 1fr 0.8fr auto;gap:10px;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="160" placeholder="e.g. Trek Roscoe 8" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Category</label>
        <select name="category_id" required class="ia-input" style="width:100%">
          @foreach($categories as $cat)
            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Tag (optional)</label>
        <input type="text" name="identifier" maxlength="60" placeholder="#BH-088" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Size</label>
        <input type="text" name="size" maxlength="40" placeholder="M / 17.5&quot;" class="ia-input" style="width:100%">
      </div>
      <div><button type="submit" class="ia-btn ia-btn--primary">Add</button></div>
    </div>
  </form>
  @endif
</div>

<div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:28px">
  <div style="padding:14px 20px;border-bottom:0.5px solid var(--ia-border);display:flex;justify-content:space-between;align-items:center">
    <span class="ia-label">{{ $units->count() }} unit{{ $units->count() === 1 ? '' : 's' }}</span>
    <span style="font-size:11px;opacity:.5">Rentable = can be booked at all · Online = bookable on your public site · Buffer pads turnaround after each return</span>
  </div>

  @if($units->isEmpty())
    <div class="ia-empty" style="padding:36px;text-align:center">
      <div class="ia-empty-title">No units yet</div>
      <div class="ia-empty-body" style="margin-top:6px">Each unit is its own bookable resource with its own history.</div>
    </div>
  @else
    @foreach($units as $u)
      <div data-kind="unit" data-url="{{ route('tenant.rentals.fleet.units.update', $u->id) }}" data-destroy="{{ route('tenant.rentals.fleet.units.destroy', $u->id) }}"
           style="padding:12px 20px;border-bottom:0.5px solid var(--ia-border);{{ $u->status === 'retired' ? 'opacity:.45' : '' }}">
        <div style="display:grid;grid-template-columns:1.6fr 0.9fr 1.2fr 0.8fr 1fr auto auto 0.7fr auto;gap:10px;align-items:center">
          <input type="text" class="ia-input fl-edit" data-field="name" value="{{ $u->name }}" maxlength="160" style="width:100%">
          <input type="text" class="ia-input fl-edit" data-field="identifier" value="{{ $u->identifier }}" maxlength="60" placeholder="—" style="width:100%">
          <select class="ia-input fl-edit" data-field="category_id" style="width:100%">
            @foreach($categories as $cat)
              <option value="{{ $cat->id }}" {{ $u->category_id === $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
            @endforeach
          </select>
          <input type="text" class="ia-input fl-edit" data-field="size" value="{{ $u->size }}" maxlength="40" placeholder="—" style="width:100%">
          <select class="ia-input fl-edit" data-field="status" style="width:100%">
            <option value="available"   {{ $u->status === 'available' ? 'selected' : '' }}>Available</option>
            <option value="maintenance" {{ $u->status === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
            <option value="retired"     {{ $u->status === 'retired' ? 'selected' : '' }}>Retired</option>
          </select>
          <button type="button" class="ia-toggle fl-toggle {{ $u->available_for_rent ? 'on' : '' }}" data-field="available_for_rent" title="Rentable at all"></button>
          <button type="button" class="ia-toggle fl-toggle {{ $u->online_booking ? 'on' : '' }}" data-field="online_booking" title="Bookable on the public site"></button>
          <input type="number" class="ia-input fl-edit" data-field="buffer_minutes" min="0" max="1440" value="{{ $u->buffer_minutes }}" title="Buffer minutes after each return" style="width:100%;text-align:right">
          <button type="button" class="ia-btn fl-archive" title="Archive unit">Archive</button>
        </div>
        <details style="margin-top:8px">
          <summary style="font-size:11.5px;opacity:.55;cursor:pointer">Rate &amp; deposit overrides ({{ $u->category->name ?? '—' }} card applies when blank)</summary>
          <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:10px;margin-top:8px;max-width:560px">
            <div>
              <label class="ia-label" style="display:block;margin-bottom:4px">Hourly $</label>
              <input type="number" class="ia-input fl-edit" data-field="hourly_rate_override" min="0" step="0.01" placeholder="inherit"
                     value="{{ $u->hourly_rate_cents_override !== null ? number_format($u->hourly_rate_cents_override / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
            </div>
            <div>
              <label class="ia-label" style="display:block;margin-bottom:4px">Daily $</label>
              <input type="number" class="ia-input fl-edit" data-field="daily_rate_override" min="0" step="0.01" placeholder="inherit"
                     value="{{ $u->daily_rate_cents_override !== null ? number_format($u->daily_rate_cents_override / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
            </div>
            <div>
              <label class="ia-label" style="display:block;margin-bottom:4px">Weekend $</label>
              <input type="number" class="ia-input fl-edit" data-field="weekend_rate_override" min="0" step="0.01" placeholder="inherit"
                     value="{{ $u->weekend_rate_cents_override !== null ? number_format($u->weekend_rate_cents_override / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
            </div>
            <div>
              <label class="ia-label" style="display:block;margin-bottom:4px">Deposit $</label>
              <input type="number" class="ia-input fl-edit" data-field="deposit_override" min="0" step="0.01" placeholder="inherit"
                     value="{{ $u->deposit_cents_override !== null ? number_format($u->deposit_cents_override / 100, 2, '.', '') : '' }}" style="width:100%;text-align:right">
            </div>
          </div>
        </details>
      </div>
    @endforeach
  @endif
</div>

{{-- ============================================== condition checklists --}}
<div class="ia-card" style="padding:18px 20px;margin-bottom:20px">
  <h2 class="ia-h3" style="margin-bottom:4px">Condition checklists</h2>
  <p style="font-size:12.5px;opacity:.6;margin-bottom:12px">Run at check-out and check-in. Differences between the two flag damage and authorize a deposit capture. One item per line.</p>
  <form method="POST" action="{{ route('tenant.rentals.fleet.ct.store') }}">
    @csrf
    <div style="display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:end">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Bike checklist" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Items (one per line)</label>
        <textarea name="items" required rows="3" maxlength="4000" placeholder="Frame — no new damage&#10;Tires — tread + pressure&#10;Brakes — pads + levers" class="ia-input" style="width:100%;resize:vertical"></textarea>
      </div>
      <div><button type="submit" class="ia-btn ia-btn--primary">Add</button></div>
    </div>
  </form>
</div>

<div class="ia-card" style="padding:0;overflow:hidden;margin-bottom:28px">
  <div style="padding:14px 20px;border-bottom:0.5px solid var(--ia-border)">
    <span class="ia-label">{{ $conditionTemplates->count() }} checklist{{ $conditionTemplates->count() === 1 ? '' : 's' }}</span>
  </div>
  @if($conditionTemplates->isEmpty())
    <div class="ia-empty" style="padding:30px;text-align:center">
      <div class="ia-empty-body">No checklists yet — optional, but they make damage claims defensible.</div>
    </div>
  @else
    @foreach($conditionTemplates as $ct)
      <div data-kind="ct" data-url="{{ route('tenant.rentals.fleet.ct.update', $ct->id) }}" data-destroy="{{ route('tenant.rentals.fleet.ct.destroy', $ct->id) }}"
           style="display:grid;grid-template-columns:1fr 2fr auto;gap:10px;align-items:start;padding:12px 20px;border-bottom:0.5px solid var(--ia-border)">
        <input type="text" class="ia-input fl-edit" data-field="name" value="{{ $ct->name }}" maxlength="120" style="width:100%">
        <textarea class="ia-input fl-edit" data-field="items" rows="3" maxlength="4000" style="width:100%;resize:vertical">{{ collect($ct->items)->pluck('label')->implode("\n") }}</textarea>
        <button type="button" class="ia-btn fl-archive" title="Delete checklist">Delete</button>
      </div>
    @endforeach
  @endif
</div>

<script>
(function () {
  var csrf = '{{ csrf_token() }}';

  function send(url, method, payload) {
    return fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: payload ? JSON.stringify(payload) : null
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, json: j }; });
    });
  }

  function rowOf(el) {
    while (el && !el.getAttribute('data-url')) el = el.parentElement;
    return el;
  }

  // Inline edits: save on change (blur for text/number, immediate for select).
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el.classList || !el.classList.contains('fl-edit')) return;
    var row = rowOf(el);
    if (!row) return;
    send(row.getAttribute('data-url'), 'PATCH', { field: el.getAttribute('data-field'), value: el.value })
      .then(function (res) {
        if (!res.ok) {
          alert(res.json && res.json.message ? res.json.message : 'Could not save.');
        } else {
          el.style.borderColor = 'var(--ia-accent, #BEF264)';
          setTimeout(function () { el.style.borderColor = ''; }, 600);
        }
      })
      .catch(function () { alert('Could not save.'); });
  });

  // Toggles.
  document.addEventListener('click', function (e) {
    var el = e.target;

    if (el.classList && el.classList.contains('fl-toggle')) {
      var row = rowOf(el);
      if (!row) return;
      var next = el.classList.contains('on') ? 0 : 1;
      send(row.getAttribute('data-url'), 'PATCH', { field: el.getAttribute('data-field'), value: next })
        .then(function (res) {
          if (res.ok) { el.classList.toggle('on', next === 1); }
          else { alert(res.json && res.json.message ? res.json.message : 'Could not save.'); }
        })
        .catch(function () { alert('Could not save.'); });
      return;
    }

    if (el.classList && el.classList.contains('fl-archive')) {
      var row2 = rowOf(el);
      if (!row2) return;
      if (!confirm('Remove this from your fleet? History is kept.')) return;
      send(row2.getAttribute('data-destroy'), 'DELETE', null)
        .then(function (res) {
          if (res.ok) { row2.remove(); }
          else { alert(res.json && res.json.message ? res.json.message : 'Could not remove.'); }
        })
        .catch(function () { alert('Could not remove.'); });
    }
  });
})();
</script>

@endsection
