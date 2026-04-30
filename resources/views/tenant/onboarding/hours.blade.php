@extends('tenant.onboarding._layout')

@section('extra-styles')
  /* Force wizard colors over tenant theme injection. */
  .screen { color: #f0f0f0; }
  .screen .screen-eyebrow { color: #D4FF3F !important; }
  .screen .screen-sub { color: #888 !important; }
  .screen .btn-primary { color: #0a0a0a !important; background: #D4FF3F !important; }
  .screen .btn-skip { color: #888 !important; }

  .hours-card {
    background: #1a1a1a !important; border: 1px solid #2a2a2a;
    border-radius: 10px; padding: 8px 22px;
  }
  .hours-row {
    display: grid;
    grid-template-columns: 110px 1fr 1fr 56px;
    gap: 12px; align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid #1f1f1f;
  }
  .hours-row:last-child { border-bottom: none; }
  .hours-day {
    font-weight: 600; color: #c8c8c8 !important; font-size: 14px;
  }
  .hours-closed {
    grid-column: 2 / 4;
    color: #5a5a5a !important; font-style: italic; font-size: 13px;
  }
  .hours-input {
    width: 100%; background: #131313 !important;
    border: 1px solid #2a2a2a; border-radius: 6px;
    padding: 8px 10px;
    color: #f0f0f0 !important;
    font-family: inherit; font-size: 13px;
    text-align: center;
  }
  .hours-input:focus { outline: none; border-color: #D4FF3F; }
  .hours-input.invalid { border-color: #ef4444; }

  .toggle {
    width: 36px; height: 20px;
    background: #131313; border: 1px solid #2a2a2a;
    border-radius: 999px; position: relative;
    cursor: pointer; transition: all 0.15s;
    justify-self: end;
  }
  .toggle::after {
    content: ''; position: absolute;
    width: 14px; height: 14px;
    background: #888; border-radius: 50%;
    top: 2px; left: 2px; transition: all 0.18s;
  }
  .toggle.on { background: #D4FF3F; border-color: #D4FF3F; }
  .toggle.on::after { background: #0a0a0a; left: 18px; }

  .hours-helper {
    font-size: 11.5px; color: #888 !important;
    margin-top: 12px;
  }
@endsection

@section('screen')
  <div class="screen-header">
    <div class="screen-eyebrow">Step 4 of 8</div>
    <h2 class="screen-title">When are you open?</h2>
    <p class="screen-sub">Set your weekly hours. Toggle days off, set custom times. Defaults to Mon-Fri 9-5 — adjust as needed.</p>
  </div>

  <div id="ob-error" class="err"></div>

  @php
    // Map existing capacity rules by day_of_week so we pre-fill on revisit.
    $existing = \App\Models\Tenant\TenantCapacityRule::where('tenant_id', $tenant->id)
        ->where('rule_type', 'default')
        ->whereNull('specific_date')
        ->get()
        ->keyBy('day_of_week');

    $days = [
      0 => 'Sunday',
      1 => 'Monday',
      2 => 'Tuesday',
      3 => 'Wednesday',
      4 => 'Thursday',
      5 => 'Friday',
      6 => 'Saturday',
    ];

    // Sensible default if no rules yet: Mon-Fri 9-5, weekends closed.
    $weekdayOpen   = '09:00';
    $weekdayClose  = '17:00';
  @endphp

  <div class="hours-card">
    @foreach($days as $num => $name)
      @php
        $rule    = $existing->get($num);
        $hasRule = $rule !== null;

        // Default behavior: weekday open by default, weekend closed by default
        $defaultOpen = in_array($num, [1,2,3,4,5]);

        if ($hasRule) {
            $isOpen = !$rule->is_closed;
            // open_time/close_time arrive as 'HH:MM:SS' from MySQL TIME, trim to HH:MM for input[type=time]
            $openTime  = $rule->open_time  ? substr($rule->open_time,  0, 5) : $weekdayOpen;
            $closeTime = $rule->close_time ? substr($rule->close_time, 0, 5) : $weekdayClose;
        } else {
            $isOpen    = $defaultOpen;
            $openTime  = $weekdayOpen;
            $closeTime = $weekdayClose;
        }
      @endphp

      <div class="hours-row" data-day="{{ $num }}">
        <div class="hours-day">{{ $name }}</div>
        <input type="time" class="hours-input" data-field="open"
               value="{{ $openTime }}"
               style="{{ $isOpen ? '' : 'display:none' }}">
        <input type="time" class="hours-input" data-field="close"
               value="{{ $closeTime }}"
               style="{{ $isOpen ? '' : 'display:none' }}">
        <div class="hours-closed" style="{{ $isOpen ? 'display:none' : '' }}">Closed</div>
        <div class="toggle {{ $isOpen ? 'on' : '' }}" data-toggle></div>
      </div>
    @endforeach
  </div>

  <div class="hours-helper">
    Toggle a day off to mark it Closed. You can edit hours anytime from Settings → Hours.
  </div>

  <div class="actions">
    <a href="{{ route('tenant.onboarding.wizard.booking', ['subdomain' => $tenant->subdomain]) }}" class="btn btn-ghost">← Back</a>
    <button type="button" class="btn btn-primary" id="ob-continue">Continue → Services</button>
  </div>
@endsection

@section('scripts')
<script>
(function () {
  const SAVE_URL = @json(route('tenant.onboarding.wizard.hours.save', ['subdomain' => $tenant->subdomain]));

  const errBox = document.getElementById('ob-error');
  const cont   = document.getElementById('ob-continue');

  function csrf() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  }
  function showError(msg) {
    errBox.textContent = msg;
    errBox.classList.add('show');
  }
  function hideError() { errBox.classList.remove('show'); }

  // Toggle wiring
  document.querySelectorAll('[data-toggle]').forEach(t => {
    t.addEventListener('click', () => {
      const row = t.closest('.hours-row');
      const open  = row.querySelector('[data-field="open"]');
      const close = row.querySelector('[data-field="close"]');
      const closedLbl = row.querySelector('.hours-closed');

      const willOpen = !t.classList.contains('on');
      t.classList.toggle('on', willOpen);

      if (willOpen) {
        open.style.display  = '';
        close.style.display = '';
        closedLbl.style.display = 'none';
      } else {
        open.style.display  = 'none';
        close.style.display = 'none';
        closedLbl.style.display = '';
      }
    });
  });

  cont.addEventListener('click', async () => {
    hideError();

    // Collect rows; only send open days
    const rows = document.querySelectorAll('.hours-row');
    const fd = new FormData();
    let openDayCount = 0;
    let invalidPair = false;

    rows.forEach(row => {
      const day      = row.dataset.day;
      const toggle   = row.querySelector('[data-toggle]');
      const isOpen   = toggle.classList.contains('on');
      const openEl   = row.querySelector('[data-field="open"]');
      const closeEl  = row.querySelector('[data-field="close"]');

      // Reset visual error state
      openEl.classList.remove('invalid');
      closeEl.classList.remove('invalid');

      if (isOpen) {
        const o = openEl.value;
        const c = closeEl.value;
        if (!o || !c) {
          openEl.classList.toggle('invalid', !o);
          closeEl.classList.toggle('invalid', !c);
          invalidPair = true;
          return;
        }
        if (o >= c) {
          openEl.classList.add('invalid');
          closeEl.classList.add('invalid');
          invalidPair = true;
          return;
        }
        fd.append(`hours[${openDayCount}][day]`,        day);
        fd.append(`hours[${openDayCount}][open_time]`,  o);
        fd.append(`hours[${openDayCount}][close_time]`, c);
        openDayCount++;
      } else {
        fd.append(`hours[${openDayCount}][day]`,    day);
        fd.append(`hours[${openDayCount}][closed]`, '1');
        openDayCount++;
      }
    });

    if (invalidPair) {
      showError('Open time must be earlier than close time, and both fields are required for any open day.');
      return;
    }

    cont.disabled = true;
    cont.textContent = 'Saving…';

    try {
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
      cont.textContent = 'Continue → Services';
    }
  });
})();
</script>
@endsection
