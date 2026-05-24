@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .booking-section {
    background: #1a1a1a; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 22px; margin-bottom: 16px;
  }
  .booking-section-title {
    font-size: 14px; font-weight: 700; margin-bottom: 4px;
    color: #f0f0f0 !important;
  }
  .booking-section-sub {
    font-size: 12px; color: #888 !important; margin-bottom: 16px;
  }
  .mode-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  }
  @media (max-width: 700px) { .mode-grid { grid-template-columns: 1fr; } }
  .mode-card {
    background: #131313 !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 18px;
    cursor: pointer; transition: all 0.15s;
    position: relative;
    color: #f0f0f0 !important;
  }
  .mode-card:hover { border-color: #5a5a5a; }
  .mode-card.selected {
    border-color: #D4FF3F;
    background: linear-gradient(180deg, rgba(212,255,63,0.05), #131313) !important;
  }
  .mode-card-icon { font-size: 24px; margin-bottom: 8px; display: block; }
  .mode-card-name {
    font-size: 14px; font-weight: 700; margin-bottom: 4px;
    color: #f0f0f0 !important;
  }
  .mode-card-desc {
    font-size: 11.5px; color: #888 !important; line-height: 1.5;
  }
  .mode-rec {
    position: absolute; top: 12px; right: 12px;
    background: #D4FF3F; color: #0a0a0a;
    font-size: 8.5px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.08em; padding: 2px 6px; border-radius: 3px;
  }

  .classes-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 4px 0;
  }
  .classes-toggle-label { flex: 1; }
  .classes-toggle-label strong {
    font-size: 14px; font-weight: 600; color: #f0f0f0 !important;
  }
  .classes-toggle-label .helper {
    font-size: 11.5px; color: #888 !important; margin-top: 4px;
    line-height: 1.5;
  }
  .toggle {
    width: 44px; height: 24px;
    background: #131313; border: 1px solid #2a2a2a;
    border-radius: 999px; position: relative;
    cursor: pointer; transition: all 0.15s;
    flex-shrink: 0;
  }
  .toggle::after {
    content: ''; position: absolute;
    width: 18px; height: 18px;
    background: #888; border-radius: 50%;
    top: 2px; left: 2px; transition: all 0.18s;
  }
  .toggle.on { background: #D4FF3F; border-color: #D4FF3F; }
  .toggle.on::after { background: #0a0a0a; left: 22px; }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 3 of 8</div>
    <h2 class="screen-title">How should customers book?</h2>
    <p class="screen-sub">Two questions: how appointments work, and whether you also offer group classes. Both can change later.</p>
  </div>

  <div id="ob-error" class="err"></div>

  {{-- Appointment style: time-slot vs drop-off (XOR) --}}
  <div class="booking-section">
    <div class="booking-section-title">Appointment style</div>
    <div class="booking-section-sub">Pick one. Time slots is the default for most businesses; drop-off works better for repair-style shops.</div>
    <div class="mode-grid">
      @php
        $currentMode = $tenant->booking_mode ?: 'time_slots';
      @endphp

      <div class="mode-card {{ $currentMode === 'time_slots' ? 'selected' : '' }}" data-mode="time_slots">
        @if($currentMode === 'time_slots')<div class="mode-rec">Default</div>@endif
        <span class="mode-card-icon">⏰</span>
        <div class="mode-card-name">Time slot</div>
        <div class="mode-card-desc">Customer picks a specific time on a specific day. Best for studios, stylists, trainers, and most appointment-based shops.</div>
      </div>

      <div class="mode-card {{ $currentMode === 'drop_off' ? 'selected' : '' }}" data-mode="drop_off">
        <span class="mode-card-icon">📦</span>
        <div class="mode-card-name">Drop-off</div>
        <div class="mode-card-desc">Customer picks a day and method (walk-in, scheduled, ship to us). You handle work in your queue. Best for bike, auto, tailor, repair shops.</div>
      </div>
    </div>
  </div>

  {{-- Classes: independent toggle --}}
  <div class="booking-section">
    <div class="classes-toggle-row">
      <div class="classes-toggle-label">
        <strong>Group classes</strong>
        <div class="helper">Schedule recurring classes (yoga, CrossFit, Pilates, fitness) with capacity limits, registrations, and waitlists. Has its own admin section. Layer on top of appointments — or run classes-only.</div>
      </div>
      <div class="toggle {{ $tenant->classes_enabled ? 'on' : '' }}" id="ob-classes-toggle"></div>
    </div>
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.identity', []) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue">Continue → Hours</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.booking.save', []));

  const cards   = document.querySelectorAll('.mode-card');
  const toggle  = document.getElementById('ob-classes-toggle');
  const cont    = document.getElementById('ob-continue');
  const errBox  = document.getElementById('ob-error');

  let selectedMode    = @json($tenant->booking_mode ?: 'time_slots');
  let classesEnabled  = @json((bool) $tenant->classes_enabled);

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) {
    errBox.textContent = msg;
    errBox.classList.add('show');
  }
  function hideError() { errBox.classList.remove('show'); }

  cards.forEach(c => {
    c.addEventListener('click', () => {
      cards.forEach(x => x.classList.remove('selected'));
      c.classList.add('selected');
      selectedMode = c.dataset.mode;
      // Drop the "Default" badge once user makes a deliberate pick
      const rec = c.querySelector('.mode-rec');
      if (rec) rec.remove();
    });
  });

  toggle.addEventListener('click', () => {
    classesEnabled = !classesEnabled;
    toggle.classList.toggle('on', classesEnabled);
  });

  cont.addEventListener('click', async () => {
    hideError();
    cont.disabled = true;
    cont.textContent = 'Saving…';

    try {
      const fd = new FormData();
      fd.append('booking_mode',    selectedMode);
      fd.append('classes_enabled', classesEnabled ? '1' : '0');

      const res = await fetch(SAVE_URL, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        const text = await res.text();
        throw new Error(`Save failed (${res.status}). ${text.substring(0, 140)}`);
      }
      const json = await res.json();
      window.location.href = json.next_url;
    } catch (err) {
      showError(err.message || 'Something went wrong.');
      cont.disabled = false;
      cont.textContent = 'Continue → Hours';
    }
  });
})();
</script>
@endsection
