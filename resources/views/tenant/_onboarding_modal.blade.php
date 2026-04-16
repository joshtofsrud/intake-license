{{--
  Onboarding modal.

  Renders ONLY when $progress['show_modal'] is true. The modal blurs the
  dashboard behind it and steps the user through branding → services →
  hours. Each step submits via fetch() to a JSON endpoint; the modal
  advances based on the server's reply.

  The user can "Skip for now" — this sets a cookie (valid for the session)
  that hides the modal. Progress itself lives in the DB, never in the
  cookie, so dismissing doesn't fake completion: the modal reappears next
  login until all three steps are actually done.
--}}
@if(!empty($progress['show_modal']))
<div id="ob-modal-root">
  <style>
    #ob-backdrop {
      position: fixed; inset: 0;
      background: rgba(0, 0, 0, .6);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
      animation: ob-fade-in .2s ease-out;
    }
    @keyframes ob-fade-in {
      from { opacity: 0; } to { opacity: 1; }
    }
    #ob-card {
      background: var(--ia-surface, #1a1a1a);
      color: var(--ia-text, #f0f0f0);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-lg, 16px);
      width: 100%; max-width: 560px;
      max-height: 90vh;
      overflow-y: auto;
      animation: ob-pop-in .25s cubic-bezier(.2,1.1,.3,1);
    }
    @keyframes ob-pop-in {
      from { transform: scale(.95); opacity: 0; }
      to   { transform: scale(1);   opacity: 1; }
    }
    .ob-head {
      padding: 24px 28px 0;
    }
    .ob-kicker {
      font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
      opacity: .5; margin-bottom: 6px;
    }
    .ob-title {
      font-size: 22px; font-weight: 700; letter-spacing: -.01em;
      margin-bottom: 4px;
    }
    .ob-subtitle {
      font-size: 14px; opacity: .6;
    }
    .ob-progress {
      display: flex; gap: 6px; padding: 18px 28px 0;
    }
    .ob-progress-bar {
      flex: 1; height: 3px; border-radius: 3px;
      background: rgba(255,255,255,.08);
      position: relative; overflow: hidden;
    }
    .ob-progress-bar.active {
      background: var(--ia-accent, #BEF264);
    }
    .ob-progress-bar.done {
      background: var(--ia-accent, #BEF264);
      opacity: .4;
    }
    .ob-body {
      padding: 22px 28px 20px;
    }
    .ob-step { display: none; }
    .ob-step.active { display: block; }
    .ob-field {
      margin-bottom: 16px;
    }
    .ob-label {
      display: block;
      font-size: 12px; font-weight: 500;
      opacity: .6; text-transform: uppercase; letter-spacing: .06em;
      margin-bottom: 6px;
    }
    .ob-hint {
      font-size: 12px; opacity: .5; margin-top: 6px;
    }
    .ob-input,
    .ob-select {
      width: 100%;
      padding: 10px 14px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0);
      font-size: 14px;
      font-family: inherit;
      transition: border-color .12s;
    }
    .ob-input:focus,
    .ob-select:focus {
      outline: none; border-color: var(--ia-accent, #BEF264);
    }
    .ob-row {
      display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
    }
    .ob-color-row {
      display: flex; gap: 10px; align-items: center;
    }
    .ob-color-row input[type=color] {
      width: 44px; height: 44px;
      padding: 0; border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      background: transparent; cursor: pointer;
    }
    .ob-radio-group {
      display: flex; gap: 8px; margin-bottom: 14px;
    }
    .ob-radio-btn {
      flex: 1; padding: 10px; text-align: center;
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      font-size: 13px; cursor: pointer; user-select: none;
      transition: all .12s;
    }
    .ob-radio-btn.selected {
      border-color: var(--ia-accent, #BEF264);
      background: rgba(190, 242, 100, .08);
    }
    .ob-hours-grid {
      display: grid;
      grid-template-columns: 90px 1fr 1fr;
      gap: 8px;
      align-items: center;
      margin-bottom: 6px;
    }
    .ob-hours-grid input[type=time] {
      padding: 7px 10px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0);
      font-size: 13px; font-family: inherit;
    }
    .ob-day-label {
      font-size: 13px; opacity: .7;
    }
    .ob-err {
      background: rgba(226,75,74,.12); color: #f39999;
      border-radius: 8px; padding: 10px 14px;
      font-size: 13px; margin-bottom: 12px;
    }
    .ob-foot {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px;
      padding: 16px 28px 22px;
      border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
    }
    .ob-skip {
      background: transparent; border: 0; cursor: pointer;
      font-size: 13px; opacity: .5; padding: 8px 12px;
      color: inherit; font-family: inherit;
    }
    .ob-skip:hover { opacity: .8; }
    .ob-btns {
      display: flex; gap: 8px;
    }
    .ob-btn {
      padding: 10px 18px;
      border-radius: var(--ia-r-md, 8px);
      font-size: 14px; font-weight: 600; cursor: pointer;
      font-family: inherit;
      transition: filter .12s, opacity .12s;
      border: 0;
    }
    .ob-btn--secondary {
      background: rgba(255,255,255,.06);
      color: var(--ia-text, #f0f0f0);
    }
    .ob-btn--primary {
      background: var(--ia-accent, #BEF264);
      color: #000;
    }
    .ob-btn:hover:not(:disabled) { filter: brightness(.92); }
    .ob-btn:disabled { opacity: .5; cursor: not-allowed; }
    .ob-spin {
      display: inline-block; width: 12px; height: 12px;
      border: 2px solid currentColor; border-right-color: transparent;
      border-radius: 50%;
      animation: ob-spin .6s linear infinite;
      vertical-align: -2px; margin-right: 6px;
    }
    @keyframes ob-spin { to { transform: rotate(360deg); } }
  </style>

  <div id="ob-backdrop" role="dialog" aria-modal="true" aria-labelledby="ob-title">
    <div id="ob-card">
      <div class="ob-head">
        <div class="ob-kicker">Welcome to Intake</div>
        <h2 class="ob-title" id="ob-title">Let's get {{ $currentTenant->name }} set up</h2>
        <p class="ob-subtitle">Three quick steps to start taking bookings.</p>
      </div>

      <div class="ob-progress">
        <div class="ob-progress-bar {{ $progress['branding'] ? 'done' : 'active' }}" data-step="branding"></div>
        <div class="ob-progress-bar {{ $progress['services'] ? 'done' : '' }}" data-step="services"></div>
        <div class="ob-progress-bar {{ $progress['hours']    ? 'done' : '' }}" data-step="hours"></div>
      </div>

      <div class="ob-body">
        <div id="ob-error" class="ob-err" style="display:none"></div>

        {{-- ================================================================
             STEP 1 — Branding
             ================================================================ --}}
        <section class="ob-step" data-step="branding">
          <div class="ob-field">
            <label class="ob-label" for="ob-name">Business name</label>
            <input type="text" class="ob-input" id="ob-name"
                   value="{{ $currentTenant->name }}"
                   placeholder="The Bike Hub">
          </div>

          <div class="ob-field">
            <label class="ob-label" for="ob-tagline">Tagline</label>
            <input type="text" class="ob-input" id="ob-tagline"
                   value="{{ $currentTenant->tagline }}"
                   placeholder="Fast, friendly service. Book online.">
            <div class="ob-hint">Shows under your name on your home page.</div>
          </div>

          <div class="ob-field">
            <label class="ob-label">Accent color</label>
            <div class="ob-color-row">
              <input type="color" id="ob-color"
                     value="{{ $currentTenant->accent_color ?: '#BEF264' }}">
              <input type="text" class="ob-input" id="ob-color-text"
                     value="{{ $currentTenant->accent_color ?: '#BEF264' }}"
                     style="flex:1; font-family: ui-monospace, monospace;">
            </div>
            <div class="ob-hint">Used for buttons, highlights, and brand accents.</div>
          </div>
        </section>

        {{-- ================================================================
             STEP 2 — Services
             ================================================================ --}}
        <section class="ob-step" data-step="services">
          <div class="ob-field">
            <label class="ob-label" for="ob-tier">Service tier</label>
            <input type="text" class="ob-input" id="ob-tier" value="Standard"
                   placeholder="Standard">
            <div class="ob-hint">e.g. Standard, Premium, Economy. You can add more later.</div>
          </div>

          <div class="ob-field">
            <label class="ob-label" for="ob-category">Category</label>
            <input type="text" class="ob-input" id="ob-category" value="Services"
                   placeholder="Services">
            <div class="ob-hint">A bucket to group similar services.</div>
          </div>

          <div class="ob-row">
            <div class="ob-field">
              <label class="ob-label" for="ob-item">First service</label>
              <input type="text" class="ob-input" id="ob-item"
                     placeholder="Basic tune-up">
            </div>
            <div class="ob-field">
              <label class="ob-label" for="ob-price">Price</label>
              <input type="number" class="ob-input" id="ob-price" min="0" step="0.01"
                     placeholder="0.00">
            </div>
          </div>

          <div class="ob-hint" style="opacity:.6">
            This gets you to a working booking page. You can build out your full
            service catalog from the Services section later.
          </div>
        </section>

        {{-- ================================================================
             STEP 3 — Hours
             ================================================================ --}}
        <section class="ob-step" data-step="hours">
          <div class="ob-radio-group">
            <div class="ob-radio-btn" data-mode="always" id="ob-mode-always">
              Always available
            </div>
            <div class="ob-radio-btn selected" data-mode="weekly" id="ob-mode-weekly">
              Set weekly hours
            </div>
          </div>

          <div id="ob-weekly-hours">
            @php
              $days = [
                0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
                4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'
              ];
            @endphp
            @foreach($days as $num => $name)
              <div class="ob-hours-grid">
                <span class="ob-day-label">{{ $name }}</span>
                <input type="time" data-day="{{ $num }}" data-field="open"
                       value="{{ in_array($num, [1,2,3,4,5]) ? '09:00' : '' }}">
                <input type="time" data-day="{{ $num }}" data-field="close"
                       value="{{ in_array($num, [1,2,3,4,5]) ? '17:00' : '' }}">
              </div>
            @endforeach
            <div class="ob-hint" style="margin-top:10px">
              Leave a day blank to mark it closed.
            </div>
          </div>

          <div id="ob-always-hint" class="ob-hint" style="display:none">
            Great — we'll mark you as always open. You can change this any time
            from the Hours section.
          </div>
        </section>
      </div>

      <div class="ob-foot">
        <button type="button" class="ob-skip" id="ob-skip">Skip for now</button>
        <div class="ob-btns">
          <button type="button" class="ob-btn ob-btn--secondary" id="ob-back" style="display:none">
            ← Back
          </button>
          <button type="button" class="ob-btn ob-btn--primary" id="ob-next">
            Continue →
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
  (function() {
    const STEPS = ['branding', 'services', 'hours'];
    const progress = @json($progress);

    // Start on the first step that isn't done yet
    let currentIdx = 0;
    for (let i = 0; i < STEPS.length; i++) {
      if (!progress[STEPS[i]]) { currentIdx = i; break; }
      if (i === STEPS.length - 1) currentIdx = i; // all done edge case
    }

    const root      = document.getElementById('ob-modal-root');
    const sections  = root.querySelectorAll('.ob-step');
    const bars      = root.querySelectorAll('.ob-progress-bar');
    const errorBox  = document.getElementById('ob-error');
    const nextBtn   = document.getElementById('ob-next');
    const backBtn   = document.getElementById('ob-back');
    const skipBtn   = document.getElementById('ob-skip');

    // Color picker sync
    const colorEl    = document.getElementById('ob-color');
    const colorText  = document.getElementById('ob-color-text');
    if (colorEl && colorText) {
      colorEl.addEventListener('input',   () => { colorText.value = colorEl.value; });
      colorText.addEventListener('input', () => {
        if (/^#[0-9A-Fa-f]{6}$/.test(colorText.value)) colorEl.value = colorText.value;
      });
    }

    // Hours mode toggle
    const modeAlways  = document.getElementById('ob-mode-always');
    const modeWeekly  = document.getElementById('ob-mode-weekly');
    const weeklyBox   = document.getElementById('ob-weekly-hours');
    const alwaysHint  = document.getElementById('ob-always-hint');
    function setMode(mode) {
      modeAlways.classList.toggle('selected', mode === 'always');
      modeWeekly.classList.toggle('selected', mode === 'weekly');
      weeklyBox.style.display  = mode === 'weekly' ? '' : 'none';
      alwaysHint.style.display = mode === 'always' ? '' : 'none';
    }
    modeAlways.addEventListener('click', () => setMode('always'));
    modeWeekly.addEventListener('click', () => setMode('weekly'));

    function render() {
      sections.forEach((s, i) => s.classList.toggle('active', i === currentIdx));
      backBtn.style.display = currentIdx === 0 ? 'none' : '';
      nextBtn.textContent   = currentIdx === STEPS.length - 1 ? 'Finish' : 'Continue →';
      hideError();
    }

    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
    function hideError()    { errorBox.style.display = 'none'; }

    function csrf() {
      return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    async function submitCurrent() {
      const step = STEPS[currentIdx];
      nextBtn.disabled = true;
      nextBtn.innerHTML = '<span class="ob-spin"></span>Saving…';

      try {
        let url, body;

        if (step === 'branding') {
          const fd = new FormData();
          fd.append('name',         document.getElementById('ob-name').value);
          fd.append('tagline',      document.getElementById('ob-tagline').value);
          fd.append('accent_color', document.getElementById('ob-color').value);
          url = @json(route('tenant.onboarding.branding'));
          body = fd;
        }
        else if (step === 'services') {
          const fd = new FormData();
          fd.append('tier_name',     document.getElementById('ob-tier').value || 'Standard');
          fd.append('category_name', document.getElementById('ob-category').value || 'Services');
          fd.append('item_name',     document.getElementById('ob-item').value);
          fd.append('price',         document.getElementById('ob-price').value || '0');
          if (!document.getElementById('ob-item').value.trim()) {
            throw new Error('Please name at least one service.');
          }
          url = @json(route('tenant.onboarding.services'));
          body = fd;
        }
        else if (step === 'hours') {
          const fd = new FormData();
          if (modeAlways.classList.contains('selected')) {
            fd.append('always_open', '1');
          } else {
            const rows = weeklyBox.querySelectorAll('.ob-hours-grid');
            let idx = 0, any = false;
            rows.forEach((row) => {
              const day   = row.querySelector('input[data-field=open]').dataset.day;
              const open  = row.querySelector('input[data-field=open]').value;
              const close = row.querySelector('input[data-field=close]').value;
              if (open && close) {
                fd.append(`hours[${idx}][day]`,        day);
                fd.append(`hours[${idx}][open_time]`,  open + ':00');
                fd.append(`hours[${idx}][close_time]`, close + ':00');
                idx++; any = true;
              }
            });
            if (!any) throw new Error('Set at least one day with open/close times, or choose "Always available."');
          }
          url = @json(route('tenant.onboarding.hours'));
          body = fd;
        }

        const res = await fetch(url, {
          method: 'POST',
          body: body,
          headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
          credentials: 'same-origin',
        });

        if (!res.ok) {
          const text = await res.text();
          throw new Error('Save failed (' + res.status + '). ' + text.substring(0, 140));
        }

        const json = await res.json();

        // Update progress bar
        bars.forEach((bar) => {
          const s = bar.dataset.step;
          if (json.progress[s]) {
            bar.classList.add('done'); bar.classList.remove('active');
          }
        });

        if (json.progress.all_done) {
          // Small celebratory pause, then reload into the real dashboard
          nextBtn.innerHTML = '🎉 All set!';
          await fetch(@json(route('tenant.onboarding.complete')), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            credentials: 'same-origin',
          });
          setTimeout(() => window.location.reload(), 600);
          return;
        }

        // Advance to next unfinished step
        let nextIdx = currentIdx + 1;
        while (nextIdx < STEPS.length && json.progress[STEPS[nextIdx]]) nextIdx++;
        currentIdx = Math.min(nextIdx, STEPS.length - 1);
        render();

      } catch (e) {
        showError(e.message || 'Something went wrong. Please try again.');
      } finally {
        nextBtn.disabled = false;
        nextBtn.innerHTML = currentIdx === STEPS.length - 1 ? 'Finish' : 'Continue →';
      }
    }

    nextBtn.addEventListener('click', submitCurrent);
    backBtn.addEventListener('click', () => {
      if (currentIdx > 0) { currentIdx--; render(); }
    });
    skipBtn.addEventListener('click', async () => {
      await fetch(@json(route('tenant.onboarding.dismiss')), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      document.getElementById('ob-backdrop').style.display = 'none';
    });

    render();
  })();
  </script>
</div>
@endif
