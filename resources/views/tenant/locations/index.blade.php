@extends('layouts.tenant.app')
@php
  $pageTitle = 'Locations';
@endphp

@push('styles')
<style>
.loc-add-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 20px 24px;
  margin-bottom: 24px;
  display: none;
}
.loc-add-card.open { display: block; }

.loc-list {
  display: flex; flex-direction: column; gap: 10px;
}
.loc-card {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 16px 18px;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 16px;
  align-items: center;
}
.loc-card.is-inactive { opacity: .55; }

.loc-card-main { min-width: 0; }
.loc-card-name {
  display: flex; align-items: center; gap: 8px;
  font-size: 15px; font-weight: 600; margin-bottom: 4px;
}
.loc-card-meta {
  font-size: 12.5px; opacity: .6; line-height: 1.5;
  display: flex; flex-wrap: wrap; gap: 12px;
}
.loc-card-meta-item { display: inline-flex; align-items: center; gap: 4px; }

.loc-actions { display: flex; gap: 4px; align-items: center; }
.loc-icon-btn {
  width: 32px; height: 32px;
  border-radius: 6px;
  border: 0.5px solid var(--ia-border);
  background: transparent;
  color: var(--ia-text-muted, rgba(255,255,255,.55));
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  transition: background var(--ia-t), color var(--ia-t), border-color var(--ia-t);
  padding: 0;
}
.loc-icon-btn:hover {
  background: var(--ia-hover, rgba(255,255,255,.05));
  color: var(--ia-text);
  border-color: var(--ia-border-strong, rgba(255,255,255,.18));
}
.loc-icon-btn.is-danger:hover {
  background: rgba(226,75,74,.10);
  color: #F09595;
  border-color: rgba(226,75,74,.30);
}
.loc-icon-svg { width: 15px; height: 15px; }

/* Inline edit form */
.loc-edit-form { display: none; margin-top: 14px; padding-top: 14px; border-top: 0.5px solid var(--ia-border); }
.loc-edit-form.open { display: block; }
.loc-edit-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 12px; }
.loc-edit-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }

@media (max-width: 720px) {
  .loc-card { grid-template-columns: 1fr; }
  .loc-actions { justify-content: flex-start; }
  .loc-edit-grid, .loc-edit-grid-2 { grid-template-columns: 1fr; }
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Locations</h1>
    <p class="ia-page-subtitle">{{ $locations->count() }} {{ Str::plural('location', $locations->count()) }}</p>
  </div>
  {{-- MARKER-LOCGATE — at the cap the button goes away and says why, rather
       than letting someone fill in a form that the server will refuse. --}}
  <div class="ia-page-actions">
    @if($currentTenant->canAddLocation())
      <button type="button" class="ia-btn ia-btn--primary" id="loc-add-toggle">+ Add location</button>
    @else
      <span style="font-size:12.5px;opacity:.55">
        Licensed for {{ (int) ($currentTenant->licensed_locations ?? 1) }}
        {{ \Illuminate\Support\Str::plural('location', (int) ($currentTenant->licensed_locations ?? 1)) }}
        &middot; get in touch to add another
      </span>
    @endif
  </div>
</div>

{{-- Add location form --}}
<div class="loc-add-card" id="loc-add-card">
  <div style="font-size:13px;font-weight:500;margin-bottom:16px">New location</div>
  <form method="POST" action="{{ route('tenant.locations.store') }}">
    @csrf
    <div class="loc-edit-grid">
      <div class="ia-form-group">
        <label class="ia-form-label">Name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name') }}" placeholder="e.g. Westside" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Phone</label>
        <input type="tel" name="phone" class="ia-input" value="{{ old('phone') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Email</label>
        <input type="email" name="email" class="ia-input" value="{{ old('email') }}">
      </div>
      {{-- MARKER-TZ-WAVE3 — timezone field removed from the form: nothing
           consumes it yet (effectiveTimezone() is unwired). Column and
           validation retained for the future multi-location tz feature. --}}
    </div>
    <div class="loc-edit-grid-2" style="margin-top:14px">
      <div class="ia-form-group">
        <label class="ia-form-label">Street address</label>
        <input type="text" name="address_line_1" class="ia-input" value="{{ old('address_line_1') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Suite, unit (optional)</label>
        <input type="text" name="address_line_2" class="ia-input" value="{{ old('address_line_2') }}">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-top:10px">
      <div class="ia-form-group">
        <label class="ia-form-label">City</label>
        <input type="text" name="city" class="ia-input" value="{{ old('city') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">State</label>
        <input type="text" name="state" class="ia-input" value="{{ old('state') }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">ZIP</label>
        <input type="text" name="postal_code" class="ia-input" value="{{ old('postal_code') }}">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:18px">
      <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Add location</button>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="loc-add-cancel">Cancel</button>
    </div>
  </form>
</div>

{{-- Location list --}}
<div class="loc-list">
  @foreach($locations as $loc)
    <div class="loc-card {{ $loc->is_active ? '' : 'is-inactive' }}" data-loc-card="{{ $loc->id }}">
      <div class="loc-card-main">
        <div class="loc-card-name">
          {{ $loc->name }}
          @if($loc->is_default)
            <span class="ia-badge ia-badge--completed" style="font-size:10.5px">Default</span>
          @endif
          @if(! $loc->is_active)
            <span class="ia-badge ia-badge--cancelled" style="font-size:10.5px">Inactive</span>
          @endif
        </div>
        <div class="loc-card-meta">
          @if($loc->address_line_1)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
              </svg>
              {{ trim($loc->address_line_1 . ', ' . ($loc->city ?? '') . ' ' . ($loc->state ?? ''), ', ') }}
            </span>
          @endif
          @if($loc->phone)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/>
              </svg>
              {{ $loc->phone }}
            </span>
          @endif
          @if($loc->timezone)
            <span class="loc-card-meta-item">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
              </svg>
              {{ $loc->timezone }}
            </span>
          @endif
          @if(! $loc->address_line_1 && ! $loc->phone && ! $loc->timezone)
            <span style="opacity:.4;font-style:italic">No address or contact set</span>
          @endif
        </div>

        {{-- Inline edit form (hidden by default) --}}
        <form method="POST" action="{{ route('tenant.locations.update', $loc->id) }}" class="loc-edit-form" data-loc-edit="{{ $loc->id }}">
          @csrf @method('PATCH')
          <div class="loc-edit-grid">
            <div class="ia-form-group">
              <label class="ia-form-label">Name <span class="ia-required">*</span></label>
              <input type="text" name="name" class="ia-input" value="{{ $loc->name }}" required>
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Phone</label>
              <input type="tel" name="phone" class="ia-input" value="{{ $loc->phone }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Email</label>
              <input type="email" name="email" class="ia-input" value="{{ $loc->email }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Timezone</label>
              <input type="text" name="timezone" class="ia-input" value="{{ $loc->timezone }}" placeholder="America/Los_Angeles">
            </div>
          </div>
          <div class="loc-edit-grid-2" style="margin-top:10px">
            <div class="ia-form-group">
              <label class="ia-form-label">Street address</label>
              <input type="text" name="address_line_1" class="ia-input" value="{{ $loc->address_line_1 }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">Suite, unit</label>
              <input type="text" name="address_line_2" class="ia-input" value="{{ $loc->address_line_2 }}">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-top:10px">
            <div class="ia-form-group">
              <label class="ia-form-label">City</label>
              <input type="text" name="city" class="ia-input" value="{{ $loc->city }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">State</label>
              <input type="text" name="state" class="ia-input" value="{{ $loc->state }}">
            </div>
            <div class="ia-form-group">
              <label class="ia-form-label">ZIP</label>
              <input type="text" name="postal_code" class="ia-input" value="{{ $loc->postal_code }}">
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:18px">
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-loc-edit-cancel="{{ $loc->id }}">Cancel</button>
          </div>
        </form>
      </div>

      <div class="loc-actions">
        {{-- Edit --}}
        <button type="button" class="loc-icon-btn" title="Edit" aria-label="Edit" data-loc-edit-toggle="{{ $loc->id }}">
          <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 20h9"/>
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
          </svg>
        </button>

        {{-- Set default (only if not already default and is active) --}}
        @if(! $loc->is_default && $loc->is_active)
        <form method="POST" action="{{ route('tenant.locations.set-default', $loc->id) }}">
          @csrf
          <button type="submit" class="loc-icon-btn" title="Set as default" aria-label="Set as default" data-confirm="Set {{ $loc->name }} as the default location?">
            <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>
        </form>
        @endif

        {{-- Toggle active --}}
        @if(! $loc->is_default)
        <form method="POST" action="{{ route('tenant.locations.toggle-active', $loc->id) }}">
          @csrf
          <button type="submit" class="loc-icon-btn" title="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }}" aria-label="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }}" data-confirm="{{ $loc->is_active ? 'Deactivate' : 'Reactivate' }} {{ $loc->name }}?">
            @if($loc->is_active)
              <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            @else
              <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            @endif
          </button>
        </form>
        @endif

        {{-- Delete --}}
        @if(! $loc->is_default)
        <form method="POST" action="{{ route('tenant.locations.destroy', $loc->id) }}">
          @csrf @method('DELETE')
          <button type="submit" class="loc-icon-btn is-danger" title="Delete location" aria-label="Delete location" data-confirm="Delete {{ $loc->name }}? This cannot be undone.">
            <svg class="loc-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </button>
        </form>
        @endif
      </div>
    </div>
  @endforeach
</div>

@endsection

@push('scripts')
<script>
// Add-card toggle
(function() {
  var toggle = document.getElementById('loc-add-toggle');
  var card   = document.getElementById('loc-add-card');
  var cancel = document.getElementById('loc-add-cancel');
  if (toggle) toggle.addEventListener('click', function() {
    card.classList.add('open');
    toggle.style.display = 'none';
  });
  if (cancel) cancel.addEventListener('click', function() {
    card.classList.remove('open');
    toggle.style.display = '';
  });
})();

// Inline edit toggles
document.querySelectorAll('[data-loc-edit-toggle]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = btn.dataset.locEditToggle;
    var form = document.querySelector('[data-loc-edit="' + id + '"]');
    if (form) form.classList.add('open');
  });
});
document.querySelectorAll('[data-loc-edit-cancel]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var id = btn.dataset.locEditCancel;
    var form = document.querySelector('[data-loc-edit="' + id + '"]');
    if (form) form.classList.remove('open');
  });
});
</script>
@endpush
