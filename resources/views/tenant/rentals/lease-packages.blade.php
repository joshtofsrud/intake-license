@extends('layouts.tenant.app')
@php $pageTitle = 'Lease Packages'; @endphp

{{-- MARKER-PATCH-229 — lease package builder. A slot = category + size
     filter + quantity; packages own no units (pulled from fleet at
     fulfillment, patch 230). --}}

@section('content')

@include('layouts.tenant._rental-nav', ['active' => 'leases'])

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Lease Packages</h1>
    <p class="ia-page-subtitle">Season-long tiers that pull from your rental fleet. Define them once; fill them at the counter.</p>
  </div>
  <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('lp-new').showModal()">+ New package</button>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

@if($packages->isEmpty())
  <div class="ia-card" style="padding:40px;text-align:center">
    <p style="font-size:14px;opacity:.6;margin-bottom:16px">No lease packages yet. Create your first tier — like "Junior Complete" — to start leasing.</p>
    <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('lp-new').showModal()">+ New package</button>
  </div>
@else
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px">
    @foreach($packages as $pkg)
      <div class="ia-card" style="padding:0;overflow:hidden">
        <div style="padding:16px 18px;border-bottom:.5px solid var(--ia-border)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
            <div>
              <div style="font-size:16px;font-weight:640">{{ $pkg->name }}</div>
              @if($pkg->subtitle)<div style="font-size:11.5px;opacity:.5;margin-top:2px">{{ $pkg->subtitle }}</div>@endif
            </div>
            <div style="display:flex;gap:4px">
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                onclick="lpEdit('{{ $pkg->id }}','{{ addslashes($pkg->name) }}','{{ addslashes($pkg->subtitle) }}','{{ $pkg->season_price_cents/100 }}','{{ $pkg->deposit_cents/100 }}')">Edit</button>
            </div>
          </div>
          <div style="display:flex;align-items:baseline;gap:6px;margin-top:8px">
            <span style="font-size:23px;font-weight:700;letter-spacing:-.02em">{{ format_money($pkg->season_price_cents) }}</span>
            <span style="font-size:12px;opacity:.5">/ season</span>
          </div>
          <div style="font-size:11.5px;opacity:.5;margin-top:3px">{{ format_money($pkg->deposit_cents) }} deposit hold</div>
        </div>

        <div style="padding:12px 18px">
          @forelse($pkg->slots as $slot)
            <div style="display:grid;grid-template-columns:auto 1fr auto auto;gap:11px;align-items:center;padding:8px 0;border-bottom:.5px solid var(--ia-border)">
              <span style="width:24px;height:24px;border-radius:5px;background:var(--ia-surface-2,rgba(255,255,255,.06));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:650">{{ $slot->quantity }}</span>
              <div>
                <div style="font-size:13px;font-weight:550">{{ $slot->category->name ?? '—' }}</div>
                @if($slot->size_filter)<div style="font-size:11px;opacity:.5">{{ $slot->size_filter }}</div>@endif
              </div>
              @php $free = $slotFree[$slot->id] ?? 0; @endphp
              <span style="font-size:11px;{{ $free >= $slot->quantity ? 'color:#7BC96F' : 'color:#E0A82E' }}">{{ $free }} free</span>
              <form method="POST" action="{{ route('tenant.rentals.leases.packages.slots.remove', ['id' => $pkg->id, 'slotId' => $slot->id]) }}" onsubmit="return confirm('Remove this slot?')">
                @csrf @method('DELETE')
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm" style="padding:2px 7px" title="Remove">&times;</button>
              </form>
            </div>
          @empty
            <p style="font-size:12px;opacity:.45;padding:6px 0">No slots yet — add what this package includes.</p>
          @endforelse

          {{-- add-slot inline --}}
          <form method="POST" action="{{ route('tenant.rentals.leases.packages.slots.add', ['id' => $pkg->id]) }}" style="display:flex;gap:6px;align-items:end;margin-top:10px;flex-wrap:wrap">
            @csrf
            <div style="width:54px">
              <label class="ia-label" style="display:block;font-size:10px;margin-bottom:3px">Qty</label>
              <input type="number" name="quantity" value="1" min="1" max="20" class="ia-input" style="padding:6px 8px;font-size:12px">
            </div>
            <div style="flex:1;min-width:90px">
              <label class="ia-label" style="display:block;font-size:10px;margin-bottom:3px">Category</label>
              <select name="category_id" class="ia-input" style="padding:6px 8px;font-size:12px" required>
                <option value="">—</option>
                @foreach($categories as $cat)
                  <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
              </select>
            </div>
            <div style="flex:1;min-width:90px">
              <label class="ia-label" style="display:block;font-size:10px;margin-bottom:3px">Size filter</label>
              <input type="text" name="size_filter" placeholder="100-140cm" class="ia-input" style="padding:6px 8px;font-size:12px">
            </div>
            <button type="submit" class="ia-btn ia-btn--sm">Add slot</button>
          </form>
        </div>
      </div>
    @endforeach
  </div>
@endif

<div class="ia-note" style="display:flex;gap:9px;align-items:flex-start;background:var(--ia-card,rgba(255,255,255,.03));border:.5px solid var(--ia-border);border-radius:8px;padding:11px 14px;font-size:12.5px;opacity:.75;margin-top:18px">
  A package never owns units. Slots describe what's needed; the actual skis, boots, and poles stay in your fleet — shared with rentals — and get pulled at the counter when you create a lease. The "free" counts are live fleet availability right now.
</div>

{{-- new/edit package dialog --}}
<dialog id="lp-new" style="border:none;border-radius:14px;padding:0;max-width:440px;width:92%;background:var(--ia-card,#1c1c1c);color:var(--ia-text,#f0f0f0);box-shadow:0 20px 60px rgba(0,0,0,.4)">
  <form method="POST" id="lp-form" action="{{ route('tenant.rentals.leases.packages.store') }}">
    @csrf
    <input type="hidden" name="_method" id="lp-method" value="POST">
    <div style="padding:18px 20px;border-bottom:.5px solid var(--ia-border);font-size:15px;font-weight:620" id="lp-title">New package</div>
    <div style="padding:18px 20px;display:flex;flex-direction:column;gap:14px">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" id="lp-name" class="ia-input" placeholder="Junior Complete" required>
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Subtitle (optional)</label>
        <input type="text" name="subtitle" id="lp-subtitle" class="ia-input" placeholder="ages 12 & under">
      </div>
      <div style="display:flex;gap:12px">
        <div style="flex:1">
          <label class="ia-label" style="display:block;margin-bottom:5px">Season price ($)</label>
          <input type="number" step="0.01" min="0" name="season_price" id="lp-price" class="ia-input" placeholder="199.00" required>
        </div>
        <div style="flex:1">
          <label class="ia-label" style="display:block;margin-bottom:5px">Deposit hold ($)</label>
          <input type="number" step="0.01" min="0" name="deposit" id="lp-deposit" class="ia-input" placeholder="120.00">
        </div>
      </div>
    </div>
    <div style="padding:14px 20px;border-top:.5px solid var(--ia-border);display:flex;justify-content:flex-end;gap:8px">
      <button type="button" class="ia-btn" onclick="document.getElementById('lp-new').close()">Cancel</button>
      <button type="submit" class="ia-btn ia-btn--primary">Save package</button>
    </div>
  </form>
</dialog>

<script>
  function lpEdit(id, name, subtitle, price, deposit) {
    var f = document.getElementById('lp-form');
    f.action = '{{ route('tenant.rentals.leases.packages') }}/' + id;
    document.getElementById('lp-method').value = 'PATCH';
    document.getElementById('lp-title').textContent = 'Edit package';
    document.getElementById('lp-name').value = name;
    document.getElementById('lp-subtitle').value = subtitle;
    document.getElementById('lp-price').value = price;
    document.getElementById('lp-deposit').value = deposit;
    document.getElementById('lp-new').showModal();
  }
  // reset to create-mode when opened via the New button
  document.querySelectorAll('[onclick*="lp-new"][onclick*="showModal"]').forEach(function (b) {
    if (b.getAttribute('onclick').indexOf('lpEdit') !== -1) return;
    b.addEventListener('click', function () {
      var f = document.getElementById('lp-form');
      f.action = '{{ route('tenant.rentals.leases.packages.store') }}';
      document.getElementById('lp-method').value = 'POST';
      document.getElementById('lp-title').textContent = 'New package';
      f.reset();
    });
  });
</script>

@endsection
