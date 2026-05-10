{{--
  New Appointment modal — availability-first design.

  Sections:
    1. Customer (search-or-create)
    2. Services (multi-select with in-line price override)
    3. When (NEW: next-available suggestion + alternatives + manual override)
    4. Notes

  Key differences from prior version:
    - "When" is the system's job, not the user's. Once services are picked, the
      modal asks pickerData?service_ids[]=... and surfaces the earliest slot.
    - "Pick another time" expands a manual override (date + time + resource).
    - Adding/removing services refires availability lookup (300ms debounce).
--}}
<div id="new-appt-modal" style="display:none">
  <style>
    #new-appt-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.6);
      backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 20px; overflow-y: auto;
      animation: appt-fade .2s ease-out;
    }
    @keyframes appt-fade { from { opacity: 0; } to { opacity: 1; } }
    #new-appt-card {
      background: var(--ia-surface, #1a1a1a);
      color: var(--ia-text, #f0f0f0);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-lg, 16px);
      width: 100%; max-width: 580px;
      animation: appt-pop .25s cubic-bezier(.2,1.1,.3,1);
    }
    @keyframes appt-pop { from { transform: scale(.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    .appt-head { padding: 22px 26px 0; display: flex; justify-content: space-between; align-items: center; }
    .appt-title { font-size: 20px; font-weight: 700; }
    .appt-close { background: none; border: none; color: inherit; font-size: 24px; cursor: pointer; opacity: .5; padding: 4px 8px; line-height: 1; }
    .appt-close:hover { opacity: 1; }

    .appt-body { padding: 18px 26px; }
    .appt-section { margin-bottom: 22px; }
    .appt-section-h { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; opacity: .55; margin-bottom: 10px; }

    .appt-field { margin-bottom: 12px; }
    .appt-label { display: block; font-size: 12px; opacity: .7; margin-bottom: 5px; }
    .appt-input {
      width: 100%; padding: 9px 12px;
      background: rgba(255,255,255,.04);
      border: 0.5px solid var(--ia-border, rgba(255,255,255,.1));
      border-radius: var(--ia-r-md, 8px);
      color: var(--ia-text, #f0f0f0); font-size: 14px; font-family: inherit;
      transition: border-color .12s; box-sizing: border-box;
    }
    .appt-input:focus { outline: none; border-color: var(--ia-accent, #BEF264); }
    .appt-textarea { resize: vertical; min-height: 60px; }
    .appt-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .appt-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

    /* Customer search */
    .appt-cust-results { background: var(--ia-surface-2, #222); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; margin-top: 4px; max-height: 180px; overflow-y: auto; }
    .appt-cust-row { padding: 8px 12px; cursor: pointer; font-size: 13px; }
    .appt-cust-row:hover { background: rgba(255,255,255,.06); }
    .appt-cust-row .meta { font-size: 11px; opacity: .55; }
    .appt-cust-attached { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
    .appt-cust-attached .clear { font-size: 11px; opacity: .55; cursor: pointer; }
    .appt-cust-attached .clear:hover { opacity: 1; color: #f39999; }

    /* Service picker */
    .appt-svc-list { display: flex; flex-direction: column; gap: 6px; }
    .appt-svc-row { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 8px 10px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 13px; }
    .appt-svc-row .name { font-weight: 500; }
    .appt-svc-row .meta { font-size: 11px; opacity: .55; }
    .appt-svc-price-edit { width: 88px; padding: 5px 8px; background: rgba(255,255,255,.04); border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 6px; color: inherit; font-size: 13px; text-align: right; }
    .appt-svc-price-edit.overridden { border-color: var(--ia-accent, #BEF264); color: var(--ia-accent, #BEF264); }
    .appt-svc-remove { font-size: 14px; opacity: .55; cursor: pointer; padding: 4px 8px; }
    .appt-svc-remove:hover { opacity: 1; color: #f39999; }
    .appt-svc-totals { margin-top: 8px; padding-top: 8px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: space-between; font-size: 12px; opacity: .8; }
    .appt-svc-totals strong { font-weight: 600; opacity: 1; }
    .appt-svc-add-btn { margin-top: 8px; width: 100%; padding: 8px; background: transparent; border: 0.5px dashed var(--ia-border, rgba(255,255,255,.2)); border-radius: 8px; color: inherit; opacity: .65; font-size: 12px; font-family: inherit; cursor: pointer; }
    .appt-svc-add-btn:hover { opacity: 1; border-color: var(--ia-accent, #BEF264); }
    .appt-svc-picker { background: var(--ia-surface-2, #222); border-radius: 8px; padding: 8px; max-height: 200px; overflow-y: auto; margin-top: 6px; }
    .appt-svc-picker-row { padding: 6px 10px; cursor: pointer; font-size: 13px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
    .appt-svc-picker-row:hover { background: rgba(255,255,255,.06); }

    /* Day strip picker */
    .appt-strip-wrap { display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    .appt-strip-arrow { font-size: 18px; opacity: .5; cursor: pointer; padding: 4px 8px; user-select: none; }
    .appt-strip-arrow:hover { opacity: 1; }
    .appt-strip-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; flex: 1; }
    .appt-strip-day {
      text-align: center;
      padding: 8px 4px;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-strip-day:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-strip-day.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
    }
    .appt-strip-day.disabled { opacity: .35; cursor: not-allowed; }
    .appt-strip-day.disabled:hover { border-color: transparent; }
    .appt-strip-dow { font-size: 10px; text-transform: uppercase; opacity: .55; letter-spacing: .04em; }
    .appt-strip-num { font-size: 14px; font-weight: 500; margin: 1px 0; }
    .appt-strip-meta { font-size: 9px; opacity: .55; }
    .appt-strip-day.selected .appt-strip-dow,
    .appt-strip-day.selected .appt-strip-meta { color: var(--ia-accent, #BEF264); opacity: 1; }
    .appt-strip-day.selected .appt-strip-num { color: var(--ia-accent, #BEF264); }

    .appt-times-label { font-size: 11px; opacity: .55; margin-bottom: 6px; }
    .appt-times-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
    .appt-time-btn {
      padding: 8px 4px;
      text-align: center;
      background: var(--ia-surface-2, #222);
      border-radius: 6px;
      font-size: 13px;
      cursor: pointer;
      border: 0.5px solid transparent;
      transition: border-color .12s;
    }
    .appt-time-btn:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.2)); }
    .appt-time-btn.selected {
      background: rgba(190, 242, 100, 0.08);
      border-color: var(--ia-accent, #BEF264);
      color: var(--ia-accent, #BEF264);
      font-weight: 500;
    }
    .appt-times-empty { font-size: 12px; opacity: .55; padding: 12px; text-align: center; background: var(--ia-surface-2, #222); border-radius: 6px; }
    .appt-resolved-resource { font-size: 11px; opacity: .65; margin-top: 10px; }
    .appt-resolved-resource a { color: var(--ia-accent, #BEF264); cursor: pointer; }

    /* Availability section */
    .appt-when-empty { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .55; text-align: center; }
    .appt-when-loading { padding: 14px; background: var(--ia-surface-2, #222); border-radius: 8px; font-size: 12px; opacity: .65; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .appt-when-card {
      padding: 14px;
      background: rgba(190, 242, 100, 0.08);
      border: 0.5px solid var(--ia-accent, #BEF264);
      border-radius: 8px;
      margin-bottom: 8px;
    }
    .appt-when-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
    .appt-when-card-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--ia-accent, #BEF264); }
    .appt-when-card-pick { font-size: 11px; color: var(--ia-accent, #BEF264); cursor: pointer; opacity: .85; }
    .appt-when-card-pick:hover { opacity: 1; }
    .appt-when-card-time { font-size: 15px; font-weight: 500; color: var(--ia-text, #f0f0f0); }
    .appt-when-none { padding: 14px; background: rgba(226,75,74,.10); border: 0.5px solid rgba(226,75,74,.25); border-radius: 8px; font-size: 13px; color: #f39999; }
    .appt-when-alts { margin-top: 10px; }
    .appt-when-alts-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
    .appt-when-alts-label { font-size: 11px; opacity: .55; }
    .appt-when-alts-nav { display: flex; gap: 6px; }
    .appt-when-alts-arrow { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border-radius: 4px; background: rgba(255,255,255,.04); cursor: pointer; font-size: 14px; opacity: .65; user-select: none; }
    .appt-when-alts-arrow:hover { opacity: 1; background: rgba(255,255,255,.08); }
    .appt-when-alts-arrow.disabled { opacity: .2; cursor: not-allowed; }
    .appt-when-alts-track { display: flex; gap: 6px; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; -ms-overflow-style: none; scroll-behavior: smooth; }
    .appt-when-alts-track::-webkit-scrollbar { display: none; }
    .appt-when-alt-row { flex: 0 0 calc((100% - 12px) / 3); scroll-snap-align: start; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding: 10px 12px; border: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); border-radius: 8px; cursor: pointer; font-size: 13px; min-height: 52px; box-sizing: border-box; }
    .appt-when-alt-row:hover { border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-row.selected { background: rgba(190, 242, 100, 0.08); border-color: var(--ia-accent, #BEF264); }
    .appt-when-alt-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-when-alt-time { font-size: 11px; opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .appt-when-manual-toggle { font-size: 11px; color: var(--ia-text-muted, #999); cursor: pointer; margin-top: 10px; display: inline-block; }
    .appt-when-manual-toggle:hover { color: var(--ia-text, #f0f0f0); }
    .appt-when-manual { margin-top: 10px; padding-top: 10px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); }

    .appt-foot { padding: 16px 26px 22px; border-top: 0.5px solid var(--ia-border, rgba(255,255,255,.1)); display: flex; justify-content: flex-end; gap: 10px; }
    .appt-btn { padding: 10px 18px; border-radius: var(--ia-r-md, 8px); font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; border: none; transition: filter .12s; }
    .appt-btn--cancel { background: rgba(255,255,255,.06); color: var(--ia-text, #f0f0f0); }
    .appt-btn--create { background: var(--ia-accent, #BEF264); color: #000; }
    .appt-btn:hover { filter: brightness(.92); }
    .appt-btn:disabled { opacity: .5; cursor: not-allowed; }
    .appt-err { background: rgba(226,75,74,.12); color: #f39999; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px; display: none; }
    .appt-spin { display: inline-block; width: 12px; height: 12px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: appt-spin .6s linear infinite; vertical-align: -2px; margin-right: 6px; }
    @keyframes appt-spin { to { transform: rotate(360deg); } }
  
    /* CALENDAR-FIRST-LOCKED-TIME v1 */
    .appt-when-locked {
      display: flex; align-items: center; justify-content: space-between;
      padding: 12px 14px;
      background: rgba(190,242,100,0.08);
      border: 1px solid rgba(190,242,100,0.25);
      border-radius: 8px;
      gap: 12px;
    }
    .appt-when-locked-left { display: flex; flex-direction: column; gap: 2px; }
    .appt-when-locked-time { font-size: 14px; font-weight: 600; color: var(--ia-text, #f0f0f0); }
    .appt-when-locked-resource { font-size: 12px; opacity: .65; }
    .appt-when-locked-change {
      font-size: 12px;
      color: var(--ia-accent, #BEF264);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      white-space: nowrap;
    }
    .appt-when-locked-change:hover { background: rgba(190,242,100,0.12); }
  </style>

  <div id="new-appt-backdrop">
    <div id="new-appt-card">
      <div class="appt-head">
        <span class="appt-title">New Appointment</span>
        <button type="button" class="appt-close" onclick="ApptModal.close()">&times;</button>
      </div>

      <div class="appt-body">
        <div id="appt-error" class="appt-err"></div>

        {{-- Customer --}}
        <div class="appt-section">
          <div class="appt-section-h">Customer</div>
          <div id="appt-cust-search-wrap">
            <input type="search" id="appt-cust-search" class="appt-input" placeholder="Search by name, email, or phone…" autocomplete="off">
            <div id="appt-cust-results" class="appt-cust-results" style="display:none"></div>
            <div id="appt-cust-new-fields" style="display:none; margin-top:10px">
              <div class="appt-row">
                <input type="text" id="appt-first" class="appt-input" placeholder="First name *">
                <input type="text" id="appt-last"  class="appt-input" placeholder="Last name *">
              </div>
              <div class="appt-row" style="margin-top:8px">
                <input type="email" id="appt-email" class="appt-input" placeholder="Email *">
                <input type="tel"   id="appt-phone" class="appt-input" placeholder="Phone">
              </div>
              <div style="font-size:11px;opacity:.55;margin-top:6px">No match — a new customer will be created.</div>
            </div>
          </div>
          <div id="appt-cust-attached" class="appt-cust-attached" style="display:none">
            <div>
              <div id="appt-cust-attached-name" style="font-weight:500"></div>
              <div id="appt-cust-attached-meta" style="font-size:11px;opacity:.55"></div>
            </div>
            <span class="clear" onclick="ApptModal.clearCustomer()">Remove</span>
          </div>
        </div>

        {{-- Services --}}
        <div class="appt-section">
          <div class="appt-section-h">Services</div>
          <div id="appt-svc-list" class="appt-svc-list"></div>
          <button type="button" id="appt-svc-add-btn" class="appt-svc-add-btn" onclick="ApptModal.toggleServicePicker()">+ Add a service</button>
          <div id="appt-svc-picker" class="appt-svc-picker" style="display:none"></div>
          <div id="appt-svc-totals" class="appt-svc-totals" style="display:none">
            <span><span id="appt-svc-count">0 services</span> · <span id="appt-svc-duration">0 min</span></span>
            <strong id="appt-svc-total">$0.00</strong>
          </div>
        </div>

        {{-- When (calendar-first locked-time pill, hidden by default) --}}
        <div class="appt-section" id="appt-when-locked-section" style="display:none">
          <div class="appt-section-h">When</div>
          <div class="appt-when-locked">
            <div class="appt-when-locked-left">
              <div class="appt-when-locked-time" id="appt-when-locked-time">—</div>
              <div class="appt-when-locked-resource" id="appt-when-locked-resource">—</div>
            </div>
            <span class="appt-when-locked-change" id="appt-when-change-time">Change time</span>
          </div>
        </div>

        {{-- When (availability-first; hidden in calendar-first flow) --}}
        <div class="appt-section" id="appt-when-availability-section">
          <div class="appt-section-h">When</div>
          <div id="appt-when-content">
            <div class="appt-when-empty">Add a service to see available times.</div>
          </div>
        </div>

        {{-- Notes --}}
        <div class="appt-section">
          <div class="appt-section-h">Staff Notes (optional)</div>
          <textarea id="appt-notes" class="appt-input appt-textarea" placeholder="Internal notes about this appointment…"></textarea>
        </div>
      </div>

      <div class="appt-foot">
        <button type="button" class="appt-btn appt-btn--cancel" onclick="ApptModal.close()">Cancel</button>
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Create Appointment</button>
      </div>
    </div>
  </div>
</div>

<script>
window.ApptModal = (function () {
  var state = {
    services: [],
    resources: [],
    cart: [],
    customerId: null,
    pickerOpen: false,
    // Availability state
    availability: null,
    availLoading: false,
    selectedSlot: null,        // {date, time, resource_id}
    manualOverride: false,
    // CALENDAR-FIRST-PREFILL v1: when set, skip availability UI, use these values
    lockedPrefill: null,       // {date, time, resourceId, resourceName}
    preservedForm: null,       // {customerId, cart, notes, custFields} stashed on Change-time
    // Manual override fields (read at submit if manualOverride is true)
  };

  var routes = {
    pickerData: "{{ route('tenant.appointments.picker-data') }}",
    store:      "{{ route('tenant.appointments.store') }}",
    dayStrip:   "{{ route('tenant.appointments.day-strip') }}",
    dayTimes:   "{{ route('tenant.appointments.day-times') }}",
    resolveResource: "{{ route('tenant.appointments.resolve-resource') }}",
  };

  var custSearchTimer = null;
  var availTimer = null;

  function fmt(cents) { return '$' + (cents / 100).toFixed(2); }
  function el(id) { return document.getElementById(id); }

  function showError(msg) { var e = el('appt-error'); e.textContent = msg; e.style.display = 'block'; }
  function clearError() { el('appt-error').style.display = 'none'; }

  function loadInitialData() {
    fetch(routes.pickerData, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.services  = data.services  || [];
        state.resources = data.resources || [];
      })
      .catch(function () { showError('Could not load services. Try again.'); });
  }

  function open() {
    clearError();
    state.cart = [];
    state.customerId = null;
    state.availability = null;
    state.selectedSlot = null;
    state.manualOverride = false;
    stripState.lockedResourceId = null;
    stripState.lockedResourceName = '';
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-new-fields').style.display = 'none';
    ['appt-first','appt-last','appt-email','appt-phone','appt-notes'].forEach(function (id) { el(id).value = ''; });
    renderCart();
    renderAvailability();
    el('appt-svc-picker').style.display = 'none';
    state.pickerOpen = false;
    // CALENDAR-FIRST: reset locked-time UI when re-opening from list page.
    state.lockedPrefill = null;
    var lockedSec = el('appt-when-locked-section');
    var availSec = el('appt-when-availability-section');
    if (lockedSec) lockedSec.style.display = 'none';
    if (availSec)  availSec.style.display  = 'block';
    el('new-appt-modal').style.display = 'block';
    if (state.services.length === 0) loadInitialData();
    el('appt-cust-search').focus();
  }

  function close() { el('new-appt-modal').style.display = 'none'; }

  // CALENDAR-FIRST-OPENPLACED v1
  // Opens the modal with a pre-locked time (set from the calendar ghost-block
  // click). Hides the availability section and shows the locked-time pill.
  // If `state.preservedForm` is set (from a prior "Change time" round-trip),
  // re-hydrate customer + cart + notes silently.
  function openPlaced(prefill) {
    open();
    state.lockedPrefill = prefill;
    state.selectedSlot = {
      date: prefill.date,
      time: prefill.time,
      resource_id: prefill.resourceId ? Number(prefill.resourceId) : null,
    };
    // Hide availability UI; show locked-time pill.
    el('appt-when-availability-section').style.display = 'none';
    el('appt-when-locked-section').style.display = 'block';
    el('appt-when-locked-time').textContent = formatLockedTime(prefill.date, prefill.time);
    el('appt-when-locked-resource').textContent = prefill.resourceName
      ? 'with ' + prefill.resourceName : '';

    // If we're round-tripping back from "Change time", re-hydrate.
    if (state.preservedForm) {
      var pf = state.preservedForm;
      if (pf.customer) {
        attachCustomer(pf.customer);
      } else if (pf.custFields) {
        el('appt-cust-search').value = pf.custFields.search || '';
        el('appt-cust-new-fields').style.display = 'block';
        el('appt-first').value = pf.custFields.first || '';
        el('appt-last').value  = pf.custFields.last  || '';
        el('appt-email').value = pf.custFields.email || '';
        el('appt-phone').value = pf.custFields.phone || '';
      }
      if (Array.isArray(pf.cart)) {
        state.cart = pf.cart;
        renderCart();
      }
      el('appt-notes').value = pf.notes || '';
      state.preservedForm = null;
    }
  }

  function formatLockedTime(dateStr, timeStr) {
    try {
      // dateStr: YYYY-MM-DD ; timeStr: HH:MM
      var d = new Date(dateStr + 'T' + timeStr + ':00');
      var dayName = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
      var monthName = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
      var hh = d.getHours(), mm = d.getMinutes();
      var ampm = hh < 12 ? 'AM' : 'PM';
      var h12 = hh === 0 ? 12 : (hh > 12 ? hh - 12 : hh);
      var t = h12 + ':' + (mm < 10 ? '0' + mm : mm) + ' ' + ampm;
      return dayName + ' ' + monthName + ' ' + d.getDate() + ' · ' + t;
    } catch (e) {
      return dateStr + ' ' + timeStr;
    }
  }

  // CALENDAR-FIRST-CHANGE-TIME v1
  // "Change time" link: stash form state, close modal, re-arm placement so user
  // can click a different slot. The slot-click handler will call openPlaced again,
  // which re-hydrates from state.preservedForm.
  function changeTime() {
    state.preservedForm = {
      customer: state.customerId ? { id: state.customerId,
        first_name: el('appt-cust-name') ? el('appt-cust-name').textContent.split(' ')[0] : '',
        last_name:  el('appt-cust-name') ? el('appt-cust-name').textContent.split(' ').slice(1).join(' ') : '',
      } : null,
      custFields: state.customerId ? null : {
        search: el('appt-cust-search').value,
        first:  el('appt-first').value,
        last:   el('appt-last').value,
        email:  el('appt-email').value,
        phone:  el('appt-phone').value,
      },
      cart: state.cart.slice(),
      notes: el('appt-notes').value,
    };
    close();
    // Re-arm calendar placement mode.
    if (window.IntakePlacement && typeof window.IntakePlacement.arm === 'function') {
      window.IntakePlacement.arm();
    }
  }

  // ── Customer search ──
  el('appt-cust-search').addEventListener('input', function () {
    clearTimeout(custSearchTimer);
    var q = this.value.trim();
    if (q.length < 2) {
      el('appt-cust-results').style.display = 'none';
      el('appt-cust-new-fields').style.display = 'none';
      return;
    }
    custSearchTimer = setTimeout(function () {
      fetch(routes.pickerData + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { renderCustomerResults(data.customers || [], q); });
    }, 250);
  });

  function renderCustomerResults(customers, query) {
    var box = el('appt-cust-results');
    if (customers.length === 0) {
      box.style.display = 'none';
      el('appt-cust-new-fields').style.display = 'block';
      var parts = query.split(/\s+/);
      if (parts.length >= 2 && !query.includes('@') && !/\d/.test(query)) {
        el('appt-first').value = parts[0];
        el('appt-last').value = parts.slice(1).join(' ');
      }
      return;
    }
    box.innerHTML = '';
    customers.forEach(function (c) {
      var row = document.createElement('div');
      row.className = 'appt-cust-row';
      row.innerHTML = '<div>' + escapeHtml(c.first_name + ' ' + c.last_name) + '</div>'
        + '<div class="meta">' + escapeHtml(c.email || c.phone || '') + '</div>';
      row.addEventListener('click', function () { attachCustomer(c); });
      box.appendChild(row);
    });
    box.style.display = 'block';
    el('appt-cust-new-fields').style.display = 'none';
  }

  function attachCustomer(c) {
    state.customerId = c.id;
    el('appt-cust-attached-name').textContent = (c.first_name + ' ' + c.last_name).trim();
    el('appt-cust-attached-meta').textContent = c.email || c.phone || '';
    el('appt-cust-attached').style.display = 'flex';
    el('appt-cust-search-wrap').style.display = 'none';
  }

  function clearCustomer() {
    state.customerId = null;
    el('appt-cust-attached').style.display = 'none';
    el('appt-cust-search-wrap').style.display = 'block';
    el('appt-cust-search').value = '';
    el('appt-cust-search').focus();
  }

  // ── Service picker ──
  function toggleServicePicker() {
    state.pickerOpen = !state.pickerOpen;
    if (state.pickerOpen) { renderServicePicker(); el('appt-svc-picker').style.display = 'block'; }
    else { el('appt-svc-picker').style.display = 'none'; }
  }

  function renderServicePicker() {
    var box = el('appt-svc-picker');
    if (state.services.length === 0) {
      box.innerHTML = '<div style="padding:8px;font-size:12px;opacity:.55">No services available.</div>';
      return;
    }
    box.innerHTML = '';
    state.services.forEach(function (s) {
      var row = document.createElement('div');
      row.className = 'appt-svc-picker-row';
      row.innerHTML = '<span>' + escapeHtml(s.name) + '</span>'
        + '<span style="opacity:.6;font-size:11px">' + s.duration_minutes + ' min · ' + fmt(s.price_cents) + '</span>';
      row.addEventListener('click', function () { addServiceToCart(s); });
      box.appendChild(row);
    });
  }

  function addServiceToCart(s) {
    state.cart.push({ service_item_id: s.id, name: s.name, duration: s.duration_minutes, price: s.price_cents, override: null });
    state.pickerOpen = false;
    el('appt-svc-picker').style.display = 'none';
    renderCart();
    scheduleAvailabilityFetch();
  }

  function removeFromCart(idx) {
    state.cart.splice(idx, 1);
    renderCart();
    scheduleAvailabilityFetch();
  }

  function setOverride(idx, dollarStr) {
    var clean = dollarStr.replace(/[^\d.]/g, '');
    if (clean === '') { state.cart[idx].override = null; }
    else {
      var cents = Math.round(parseFloat(clean) * 100);
      if (isNaN(cents)) cents = null;
      state.cart[idx].override = cents;
    }
    renderTotals();
  }

  function renderCart() {
    var list = el('appt-svc-list');
    if (state.cart.length === 0) {
      list.innerHTML = '<div style="font-size:12px;opacity:.5;padding:6px 0">No services selected.</div>';
      el('appt-svc-totals').style.display = 'none';
      return;
    }
    list.innerHTML = '';
    state.cart.forEach(function (line, idx) {
      var effective = line.override !== null ? line.override : line.price;
      var displayValue = (effective / 100).toFixed(2);
      var overridden = line.override !== null && line.override !== line.price;
      var row = document.createElement('div');
      row.className = 'appt-svc-row';
      row.innerHTML = '<div>'
        + '<div class="name">' + escapeHtml(line.name) + '</div>'
        + '<div class="meta">' + line.duration + ' min · catalog ' + fmt(line.price) + (overridden ? ' · <span style="color:#BEF264">overridden</span>' : '') + '</div>'
        + '</div>'
        + '<input type="text" class="appt-svc-price-edit ' + (overridden ? 'overridden' : '') + '" value="' + displayValue + '" data-idx="' + idx + '">'
        + '<span class="appt-svc-remove" data-idx="' + idx + '">&times;</span>';
      list.appendChild(row);
    });
    list.querySelectorAll('.appt-svc-price-edit').forEach(function (input) {
      input.addEventListener('change', function () { setOverride(parseInt(this.dataset.idx, 10), this.value); });
      input.addEventListener('blur',   function () { renderCart(); });
    });
    list.querySelectorAll('.appt-svc-remove').forEach(function (x) {
      x.addEventListener('click', function () { removeFromCart(parseInt(this.dataset.idx, 10)); });
    });
    renderTotals();
  }

  function renderTotals() {
    var total = 0, dur = 0;
    state.cart.forEach(function (line) {
      total += (line.override !== null ? line.override : line.price);
      dur   += line.duration;
    });
    el('appt-svc-count').textContent = state.cart.length + ' service' + (state.cart.length === 1 ? '' : 's');
    el('appt-svc-duration').textContent = dur + ' min';
    el('appt-svc-total').textContent = fmt(total);
    el('appt-svc-totals').style.display = 'flex';
  }

  // ── Availability ──
  function scheduleAvailabilityFetch() {
    clearTimeout(availTimer);
    if (state.cart.length === 0) {
      state.availability = null;
      state.selectedSlot = null;
      state.manualOverride = false;
      renderAvailability();
      return;
    }
    state.availLoading = true;
    state.manualOverride = false;
    renderAvailability();
    availTimer = setTimeout(fetchAvailability, 300);
  }

  function fetchAvailability() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&');
    fetch(routes.pickerData + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.availability = data.availability || null;
        state.availLoading = false;
        // Default-pick the suggested earliest slot.
        if (state.availability && state.availability.earliest) {
          state.selectedSlot = state.availability.earliest;
        } else {
          state.selectedSlot = null;
        }
        renderAvailability();
      })
      .catch(function () {
        state.availLoading = false;
        showError('Could not load availability.');
      });
  }

  function renderAvailability() {
    var box = el('appt-when-content');

    if (state.cart.length === 0) {
      box.innerHTML = '<div class="appt-when-empty">Add a service to see available times.</div>';
      return;
    }
    if (state.availLoading) {
      box.innerHTML = '<div class="appt-when-loading"><span class="appt-spin"></span>Finding the next available slot…</div>';
      return;
    }
    if (!state.availability || !state.availability.earliest) {
      box.innerHTML = '<div class="appt-when-none">No availability found in the next 60 days. Pick a custom time below.</div>'
        + renderManualBlock(true);
      wireManualHandlers();
      return;
    }

    var earliest = state.availability.earliest;
    var per = state.availability.per_resource || [];
    var resourceMap = {};
    state.resources.forEach(function (r) { resourceMap[r.id] = r; });

    // Resolve resource name for the earliest slot
    var earliestResourceName = earliest.resource_id && resourceMap[earliest.resource_id]
      ? resourceMap[earliest.resource_id].name
      : 'Any resource';

    // Find which resource id will actually serve this any-resource slot.
    // The earliest is computed with resourceId=null, so we need to resolve
    // which specific resource has that exact slot — pick the first per_resource
    // entry that matches the earliest date+time.
    var resolvedResourceId = earliest.resource_id;
    var resolvedResourceName = earliestResourceName;
    if (!resolvedResourceId) {
      var match = per.find(function (p) { return p.date === earliest.date && p.time === earliest.time; });
      if (match) {
        resolvedResourceId = match.resource_id;
        resolvedResourceName = match.name;
      } else if (per.length > 0) {
        // Fallback: nobody matches exactly, but resources are listed — take the soonest
        resolvedResourceId = per[0].resource_id;
        resolvedResourceName = per[0].name;
      }
    }

    var html = '<div class="appt-when-card" id="appt-when-suggested">'
      + '<div class="appt-when-card-head">'
      +   '<span class="appt-when-card-label">Next available</span>'
      +   '<span class="appt-when-card-pick" id="appt-when-pick-other">Pick another time →</span>'
      + '</div>'
      + '<div class="appt-when-card-time">' + formatSlotLabel(earliest.date, earliest.time) + ' · ' + escapeHtml(resolvedResourceName) + '</div>'
      + '</div>';

    // Show alternatives if any are sooner than the earliest, OR top 3 anyway
    var alts = per.filter(function (p) {
      return !(p.date === earliest.date && p.time === earliest.time && p.resource_id === resolvedResourceId);
    });
    if (alts.length > 0) {
      html += '<div class="appt-when-alts">';
      html += '<div class="appt-when-alts-label">Or with a different resource:</div>';
      alts.slice(0, 3).forEach(function (a) {
        html += '<div class="appt-when-alt-row" data-resource="' + escapeHtml(a.resource_id)
          + '" data-date="' + escapeHtml(a.date) + '" data-time="' + escapeHtml(a.time) + '">'
          + '<span>' + escapeHtml(a.name) + '</span>'
          + '<span class="appt-when-alt-time">' + formatSlotLabel(a.date, a.time) + '</span>'
          + '</div>';
      });
      html += '</div>';
    }

    if (state.manualOverride) {
      html += renderManualBlock(false);
    }

    box.innerHTML = html;

    // Save the resolved slot so submit knows which resource to send.
    state.selectedSlot = {
      date: earliest.date,
      time: earliest.time,
      resource_id: resolvedResourceId,
    };

    // Wire arrow nav for alts carousel
    var altsTrack = document.getElementById('appt-alts-track');
    if (altsTrack) {
      var altsArrows = box.querySelectorAll('.appt-when-alts-arrow');
      function updateArrowState() {
        if (altsArrows.length === 0) return;
        var atStart = altsTrack.scrollLeft <= 1;
        var atEnd = altsTrack.scrollLeft + altsTrack.clientWidth >= altsTrack.scrollWidth - 1;
        altsArrows.forEach(function (arr) {
          var dir = arr.dataset.dir;
          if (dir === 'back') arr.classList.toggle('disabled', atStart);
          if (dir === 'fwd')  arr.classList.toggle('disabled', atEnd);
        });
      }
      altsArrows.forEach(function (arr) {
        arr.addEventListener('click', function () {
          if (arr.classList.contains('disabled')) return;
          var page = altsTrack.clientWidth;
          altsTrack.scrollBy({ left: arr.dataset.dir === 'fwd' ? page : -page, behavior: 'smooth' });
        });
      });
      altsTrack.addEventListener('scroll', updateArrowState);
      setTimeout(updateArrowState, 0);
    }

    // Wire alt rows. Click = pick that resource AND lock the strip to it.
    box.querySelectorAll('.appt-when-alt-row').forEach(function (row) {
      row.addEventListener('click', function () {
        state.selectedSlot = {
          date: row.dataset.date,
          time: row.dataset.time,
          resource_id: row.dataset.resource,
        };
        // Lock the strip to this resource so manual override scopes correctly.
        stripState.lockedResourceId = row.dataset.resource;
        var nameMatch = state.resources.find(function (r) { return r.id === row.dataset.resource; });
        stripState.lockedResourceName = nameMatch ? nameMatch.name : '';
        box.querySelectorAll('.appt-when-alt-row').forEach(function (r) { r.classList.remove('selected'); });
        row.classList.add('selected');
        el('appt-when-suggested').style.opacity = '.65';
        // refetch strip with new lock if manual override is already open
        if (state.manualOverride) {
          stripState.selectedTime = null;
          stripState.times = [];
          fetchDayStrip();
        }
      });
    });

    // Wire "Pick another time"
    var pickOther = el('appt-when-pick-other');
    if (pickOther) {
      pickOther.addEventListener('click', function () {
        state.manualOverride = true;
        renderAvailability();
      });
    }

    if (state.manualOverride) wireManualHandlers();
  }

  // Day-strip state, lives inside ApptModal closure scope
  var stripState = {
    startDate: null,
    days: [],
    selectedDate: null,
    times: [],
    selectedTime: null,
    resolvedResourceId: null,
    resolvedResourceName: '',
    lockedResourceId: null,
    lockedResourceName: '',
  };

  function renderManualBlock(isOnlyOption) {
    return '<div class="appt-when-manual">'
      + (isOnlyOption ? '' : '<div style="font-size:11px;opacity:.55;margin-bottom:8px">Pick any time:</div>')
      + '<div id="appt-strip-container"><div class="appt-when-loading"><span class="appt-spin"></span>Loading availability…</div></div>'
      + '</div>';
  }

  function wireManualHandlers() {
    var startFrom = (state.selectedSlot && state.selectedSlot.date) || new Date().toISOString().split('T')[0];
    stripState.startDate = startFrom;
    stripState.selectedDate = state.selectedSlot ? state.selectedSlot.date : null;
    stripState.selectedTime = state.selectedSlot ? state.selectedSlot.time : null;
    stripState.resolvedResourceId = state.selectedSlot ? state.selectedSlot.resource_id : null;
    fetchDayStrip();
  }

  function fetchDayStrip() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&start_date=' + encodeURIComponent(stripState.startDate)
      + '&days=7';
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.dayStrip + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.days = data.days || [];
        var inWindow = stripState.selectedDate && stripState.days.some(function (d) { return d.date === stripState.selectedDate; });
        if (!inWindow) {
          var firstOpen = stripState.days.find(function (d) { return d.status === 'open' && d.count > 0; });
          stripState.selectedDate = firstOpen ? firstOpen.date : null;
          stripState.selectedTime = null;
          stripState.times = [];
        } else {
          // Date still in window, but times for that date may have changed
          // (e.g. resource switch). Clear stale time selection and refetch.
          stripState.selectedTime = null;
          stripState.times = [];
        }
        renderStripContainer();
        if (stripState.selectedDate) fetchDayTimes();
      })
      .catch(function () { showError('Could not load availability strip.'); });
  }

  function fetchDayTimes() {
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate);
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.dayTimes + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.times = data.times || [];
        renderStripContainer();
      })
      .catch(function () { showError('Could not load times for that day.'); });
  }

  function fetchResolvedResource() {
    if (!stripState.selectedDate || !stripState.selectedTime) return;
    var qs = state.cart.map(function (l) { return 'service_ids[]=' + encodeURIComponent(l.service_item_id); }).join('&')
      + '&date=' + encodeURIComponent(stripState.selectedDate)
      + '&time=' + encodeURIComponent(stripState.selectedTime);
    if (stripState.lockedResourceId) qs += '&resource_id=' + encodeURIComponent(stripState.lockedResourceId);
    fetch(routes.resolveResource + '?' + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        stripState.resolvedResourceId = data.resource_id;
        var match = state.resources.find(function (r) { return r.id === data.resource_id; });
        stripState.resolvedResourceName = match ? match.name : '';
        state.selectedSlot = {
          date: stripState.selectedDate,
          time: stripState.selectedTime,
          resource_id: data.resource_id,
        };
        renderStripContainer();
      });
  }

  function renderStripContainer() {
    var box = document.getElementById('appt-strip-container');
    if (!box) return;

    var html = '';
    if (stripState.lockedResourceId) {
      html += '<div style="font-size:11px;opacity:.85;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center">'
        + '<span>Showing availability for <strong>' + escapeHtml(stripState.lockedResourceName) + '</strong></span>'
        + '<span id="appt-strip-clear-lock" style="color:var(--ia-accent,#BEF264);cursor:pointer">Show all resources</span>'
        + '</div>';
    }
    html += '<div class="appt-strip-wrap">';
    html += '<span class="appt-strip-arrow' + (canStripGoBack() ? '' : ' disabled') + '" data-dir="back">‹</span>';
    html += '<div class="appt-strip">';
    stripState.days.forEach(function (day) {
      var d = new Date(day.date + 'T00:00:00');
      var dow = d.toLocaleDateString(undefined, { weekday: 'short' });
      var num = d.getDate();
      var disabled = (day.status === 'past' || day.status === 'closed' || day.status === 'beyond_window' || day.count === 0);
      var selected = day.date === stripState.selectedDate;
      var meta;
      if (day.status === 'closed') meta = 'closed';
      else if (day.status === 'past') meta = 'past';
      else if (day.status === 'beyond_window') meta = '—';
      else if (day.status === 'full' || day.count === 0) meta = 'full';
      else meta = day.count + ' open';

      html += '<div class="appt-strip-day' + (disabled ? ' disabled' : '') + (selected ? ' selected' : '') + '" data-date="' + escapeHtml(day.date) + '" data-disabled="' + (disabled ? '1' : '0') + '">';
      html += '<div class="appt-strip-dow">' + escapeHtml(dow) + '</div>';
      html += '<div class="appt-strip-num">' + num + '</div>';
      html += '<div class="appt-strip-meta">' + escapeHtml(meta) + '</div>';
      html += '</div>';
    });
    html += '</div>';
    html += '<span class="appt-strip-arrow" data-dir="fwd">›</span>';
    html += '</div>';

    if (stripState.selectedDate) {
      var dStr = new Date(stripState.selectedDate + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
      html += '<div class="appt-times-label">Available times on ' + escapeHtml(dStr) + '</div>';

      if (stripState.times.length === 0) {
        html += '<div class="appt-times-empty">No times available for that day.</div>';
      } else {
        html += '<div class="appt-times-grid">';
        stripState.times.forEach(function (t) {
          var label = formatTimeLabel(t);
          var isSelected = t === stripState.selectedTime;
          html += '<div class="appt-time-btn' + (isSelected ? ' selected' : '') + '" data-time="' + escapeHtml(t) + '">' + escapeHtml(label) + '</div>';
        });
        html += '</div>';
      }
    }

    if (stripState.selectedDate && stripState.selectedTime && stripState.resolvedResourceId) {
      html += '<div class="appt-resolved-resource">Will book with <strong>' + escapeHtml(stripState.resolvedResourceName) + '</strong></div>';
    }

    box.innerHTML = html;

    var clearLock = document.getElementById('appt-strip-clear-lock');
    if (clearLock) {
      clearLock.addEventListener('click', function () {
        stripState.lockedResourceId = null;
        stripState.lockedResourceName = '';
        stripState.selectedTime = null;
        stripState.times = [];
        fetchDayStrip();
      });
    }

    box.querySelectorAll('.appt-strip-arrow').forEach(function (a) {
      a.addEventListener('click', function () {
        if (a.classList.contains('disabled')) return;
        var dir = a.dataset.dir;
        var newStart = new Date(stripState.startDate + 'T00:00:00');
        newStart.setDate(newStart.getDate() + (dir === 'fwd' ? 7 : -7));
        stripState.startDate = newStart.toISOString().split('T')[0];
        fetchDayStrip();
      });
    });

    box.querySelectorAll('.appt-strip-day').forEach(function (d) {
      d.addEventListener('click', function () {
        if (d.dataset.disabled === '1') return;
        stripState.selectedDate = d.dataset.date;
        stripState.selectedTime = null;
        stripState.times = [];
        renderStripContainer();
        fetchDayTimes();
      });
    });

    box.querySelectorAll('.appt-time-btn').forEach(function (t) {
      t.addEventListener('click', function () {
        stripState.selectedTime = t.dataset.time;
        renderStripContainer();
        fetchResolvedResource();
      });
    });
  }

  function canStripGoBack() {
    if (!stripState.startDate) return false;
    var today = new Date().toISOString().split('T')[0];
    return stripState.startDate > today;
  }

  function formatTimeLabel(t) {
    var parts = t.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12 === 0 ? 12 : h % 12;
    return h12 + ':' + m + ' ' + ampm;
  }

  function formatSlotLabel(date, time) {
    // date: YYYY-MM-DD, time: HH:MM
    var d = new Date(date + 'T' + time);
    var dateStr = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    var timeStr = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    return dateStr + ' at ' + timeStr;
  }

  // ── Submit ──
  function submit() {
    clearError();
    if (state.cart.length === 0) return showError('Add at least one service.');
    if (!state.selectedSlot || !state.selectedSlot.date) return showError('Pick a time.');

    var btn = el('appt-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="appt-spin"></span>Creating…';

    var payload = {
      customer_id: state.customerId,
      appointment_date: state.selectedSlot.date,
      appointment_time: state.selectedSlot.time || null,
      resource_id: state.selectedSlot.resource_id || null,
      staff_notes: el('appt-notes').value || null,
      items: state.cart.map(function (l) {
        return {
          service_item_id: l.service_item_id,
          price_override_cents: l.override !== null && l.override !== l.price ? l.override : null,
        };
      }),
    };
    if (!state.customerId) {
      payload.customer_first_name = el('appt-first').value.trim();
      payload.customer_last_name  = el('appt-last').value.trim();
      payload.customer_email      = el('appt-email').value.trim();
      payload.customer_phone      = el('appt-phone').value.trim();
      if (!payload.customer_first_name || !payload.customer_last_name || !payload.customer_email) {
        showError('First name, last name, and email are required for a new customer.');
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    fetch(routes.store, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : '' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
    .then(function (res) {
      if (res.ok && res.body.ok) {
        if (res.body.redirect) window.location.href = res.body.redirect;
        else window.location.reload();
        return;
      }
      // If the slot got taken between fetch and submit, refresh availability.
      if (res.body && res.body.code === 'lock_timeout') {
        showError('That slot was just taken. Recomputing…');
        scheduleAvailabilityFetch();
        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
      var msg = (res.body && (res.body.message || (res.body.errors && Object.values(res.body.errors).flat().join(' ')))) || 'Server error.';
      showError(msg);
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    })
    .catch(function () {
      showError('Network error.');
      btn.disabled = false; btn.innerHTML = 'Create Appointment';
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Wire Change-time click (idempotent).
  document.addEventListener('DOMContentLoaded', function () {
    var ct = el('appt-when-change-time');
    if (ct && !ct.dataset.wired) { ct.addEventListener('click', changeTime); ct.dataset.wired = '1'; }
  });
  // Already-loaded fallback.
  (function () {
    var ct = el('appt-when-change-time');
    if (ct && !ct.dataset.wired) { ct.addEventListener('click', changeTime); ct.dataset.wired = '1'; }
  })();

  return {
    open: open, close: close, clearCustomer: clearCustomer,
    toggleServicePicker: toggleServicePicker, submit: submit,
    openPlaced: openPlaced, changeTime: changeTime,
  };
})();

window.openApptModal  = function () { ApptModal.open(); };
window.closeApptModal = function () { ApptModal.close(); };
</script>
