/**
 * Intake SaaS — Booking Form JS
 * 4-step flow: Services → Schedule → Details → Review + Payment
 */
(function () {
  'use strict';

  var d    = window.BkData || {};
  var csrf = d.csrf   || '';

  // =========================================================================
  // State
  // =========================================================================
  var state = {
    step:       1,
    // Services
    selections: {},   // { serviceId: {...} } — multi-asset: the ACTIVE bike's set
    assetSel: {},     // MARKER-PATCH-214b — multi-asset: { assetKey: { serviceId: {...} } }
    activeAsset: null,// MARKER-PATCH-214b — multi-asset: active bike clientKey
    // Schedule
    date:       null,
    appointmentTime: null,
    resourceId: null,
    receivingMethod: null,
    // Details
    firstName:  '',
    lastName:   '',
    email:      '',
    phone:      '',
    responses:  {},   // { fieldKey: value }
    responseLabels: {}, // { fieldKey: label }
    // Payment
    paymentMethod: d.stripeEnabled ? 'stripe' : (d.paypalEnabled ? 'paypal' : 'none'),
  };

  // Calendar state
  var calYear, calMonth, calAvailable = {}, calUnavailable = {}, calEarliest = null, calTimeSlots = {}, calSlotResources = {};
  var calPdWindows = {}; // MARKER-PATCH-512 — pickup & delivery route windows per date
  var calCapacity = {}, calView = 'month'; // MARKER-PATCH-518 — day/week/month
  var bookingMode = d.bookingMode || 'drop_off';
  var today = new Date();
  calYear  = today.getFullYear();
  calMonth = today.getMonth() + 1;

  // Stripe state
  var stripe, stripeElements, stripeCard;

  // =========================================================================
  // Boot
  // =========================================================================
  document.addEventListener('DOMContentLoaded', function () {
    bindAddButtons();
    bindServiceAddonCheckboxes();
    bindSearch();
    bindCatPills();
    bindCalNav();
    bindReceiving();
    initCalendar();
    if (d.multiAsset) window.__bkInitAssetServices = initAssetServices; // MARKER-PATCH-214c (run at pre-flow handoff, not boot)
    if (d.stripeEnabled && d.stripePk) initStripe();
    if (d.paypalEnabled && window.paypal) initPayPal();
  });

  // =========================================================================
  // Step navigation
  // =========================================================================
  window.goTo = function (step) {
    if (step === 2 && !canProceedStep1()) return;
    if (step === 3 && !canProceedStep2()) return;
    if (step === 4) return; // use goToReview()
    setStep(step);
  };

  window.goToReview = function () {
    if (!canProceedStep3()) return;
    collectDetails();
    renderReview();
    setStep(4);
  };

  function setStep(step) {
    state.step = step;

    // Sections
    document.querySelectorAll('.bk-section').forEach(function (s) {
      s.classList.remove('active');
    });
    var el = document.getElementById('bk-step-' + step);
    if (el) el.classList.add('active');

    if (step === 3) populateStep3Recap();

    // Progress dots
    document.querySelectorAll('.bk-step').forEach(function (dot) {
      var ds = parseInt(dot.getAttribute('data-step'), 10);
      dot.classList.remove('active', 'done');
      if (ds === step) dot.classList.add('active');
      if (ds < step)  dot.classList.add('done');
    });

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // =========================================================================
  // Step 1 — Services
  // =========================================================================
  function bindAddButtons() {
    document.querySelectorAll('.bk-service-add-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var serviceId = btn.getAttribute('data-service-id');
        if (!serviceId) return;
        if (state.selections[serviceId]) {
          deselectService(serviceId);
        } else {
          selectService(btn);
        }
      });
    });
  }

  function bindServiceAddonCheckboxes() {
    document.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var serviceId = cb.getAttribute('data-service-id');
        var addonId   = cb.getAttribute('data-addon-id');
        if (!serviceId || !addonId) return;

        if (cb.checked && !state.selections[serviceId]) {
          var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
          var btn = row ? row.querySelector('.bk-service-add-btn') : null;
          if (btn) selectService(btn);
        }

        var sel = state.selections[serviceId];
        if (!sel) return;

        if (cb.checked) {
          if (sel.addonIds.indexOf(addonId) === -1) sel.addonIds.push(addonId);
        } else {
          sel.addonIds = sel.addonIds.filter(function (id) { return id !== addonId; });
        }
        updateSidebar();
      });
    });
  }

  function selectService(btn) {
    var serviceId   = btn.getAttribute('data-service-id');
    var serviceName = btn.getAttribute('data-service-name');
    var priceCents  = parseInt(btn.getAttribute('data-service-price-cents'), 10) || 0;
    var row         = btn.closest('.bk-service-row');
    var duration    = row ? parseInt(row.getAttribute('data-service-duration'), 10) || 0 : 0;

    state.selections[serviceId] = {
      serviceId: serviceId, serviceName: serviceName,
      priceCents: priceCents, durationMinutes: duration, addonIds: [],
    };
    if (row) row.classList.add('is-selected');
    btn.textContent = '✓ Added';
    updateNext1();
    updateSidebar();
  }

  function deselectService(serviceId) {
    delete state.selections[serviceId];
    var row = document.querySelector('.bk-service-row[data-service-id="' + serviceId + '"]');
    if (row) {
      row.classList.remove('is-selected');
      var btn = row.querySelector('.bk-service-add-btn');
      if (btn) btn.textContent = 'Add to booking';
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) { cb.checked = false; });
    }
    updateNext1();
    updateSidebar();
  }

  // MARKER-PATCH-265 — category pills + search share one filter.
  var bkActiveCat = 'all';

  function applyCatalogFilter() {
    var input = document.getElementById('bk-search');
    var q = input ? input.value.toLowerCase().trim() : '';
    document.querySelectorAll('.bk-cat-group').forEach(function (group) {
      var gcat = group.getAttribute('data-cat') || '';
      if (bkActiveCat !== 'all' && gcat !== bkActiveCat) {
        group.style.display = 'none';
        return;
      }
      var anyVisible = false;
      group.querySelectorAll('.bk-service-row').forEach(function (row) {
        var name = (row.getAttribute('data-service-name') || '').toLowerCase();
        var show = (!q || name.includes(q));
        row.style.display = show ? '' : 'none';
        if (show) anyVisible = true;
      });
      group.style.display = anyVisible ? '' : 'none';
    });
  }

  function bindSearch() {
    var input = document.getElementById('bk-search');
    if (!input) return;
    input.addEventListener('input', applyCatalogFilter);
  }

  function bindCatPills() {
    var rail = document.getElementById('bk-cat-rail');
    if (!rail) return;
    rail.querySelectorAll('.bk-cat-pill').forEach(function (pill) {
      pill.addEventListener('click', function () {
        bkActiveCat = pill.getAttribute('data-cat') || 'all';
        rail.querySelectorAll('.bk-cat-pill').forEach(function (p) { p.classList.remove('is-active'); });
        pill.classList.add('is-active');
        applyCatalogFilter();
      });
    });
  }

  function canProceedStep1() {
    if (d.multiAsset) {
      if (Object.keys(state.selections).length) return true; // active bike's live picks (pre-sync)
      var any = false;
      Object.keys(state.assetSel).forEach(function (k) { if (Object.keys(state.assetSel[k]).length) any = true; });
      return any;
    }
    return Object.keys(state.selections).length > 0;
  }

  function updateNext1() {
    var btn = document.getElementById('bk-next-1');
    if (btn) btn.disabled = !canProceedStep1();
  }

  // =========================================================================
  // Step 2 — Calendar
  // =========================================================================
  function bindCalNav() {
    var prev = document.getElementById('cal-prev');
    var next = document.getElementById('cal-next');
    if (prev) prev.addEventListener('click', function () {
      calMonth--;
      if (calMonth < 1) { calMonth = 12; calYear--; }
      state.date = null;
      updateNext2();
      loadMonth();
    });
    if (next) next.addEventListener('click', function () {
      calMonth++;
      if (calMonth > 12) { calMonth = 1; calYear++; }
      state.date = null;
      updateNext2();
      loadMonth();
    });
  }

  function populateStep3Recap() {
    var card = document.getElementById('bk-step3-recap');
    var whenEl = document.getElementById('bk-step3-recap-when');
    var metaEl = document.getElementById('bk-step3-recap-meta');
    var changeBtn = document.getElementById('bk-step3-recap-change');
    if (!card || !whenEl || !metaEl) return;

    if (!state.date) {
      card.style.display = 'none';
      return;
    }

    // Format the primary line: 'Wednesday, April 30 at 9:00 AM' (time-slot)
    // or 'Wednesday, April 30' (drop-off without time).
    var dt = parseDateString(state.date);
    var dayLabel = dt.toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric'
    });
    var primary = dayLabel;
    if (state.appointmentTime) {
      primary += ' at ' + formatTime12h(state.appointmentTime);
    }
    whenEl.textContent = primary;

    // Meta line: receiving method (drop-off) and/or selected service summary.
    var metaParts = [];
    if (state.receivingMethod) metaParts.push(state.receivingMethod);
    if (d.multiAsset) {
      // MARKER-PATCH-214e — aggregate across all bikes, not just the active one
      var bikeCount = (window.BkAssets || []).length;
      var svcCount = 0;
      Object.keys(state.assetSel).forEach(function (k) { svcCount += Object.keys(state.assetSel[k]).length; });
      if (bikeCount) metaParts.push(bikeCount + ' bike' + (bikeCount > 1 ? 's' : ''));
      if (svcCount)  metaParts.push(svcCount + ' service' + (svcCount > 1 ? 's' : ''));
    } else {
      var sels = Object.values(state.selections || {});
      if (sels.length) {
        var firstName = sels[0].serviceName || '';
        if (firstName) {
          if (sels.length === 1) metaParts.push(firstName);
          else                    metaParts.push(firstName + ' + ' + (sels.length - 1) + ' more');
        }
      }
    }
    metaEl.textContent = metaParts.join(' · ') || ' ';

    card.style.display = '';

    // Wire Change button once. Goes back to step 2.
    if (changeBtn && !changeBtn.__bkBound) {
      changeBtn.__bkBound = true;
      changeBtn.addEventListener('click', function () {
        window.goTo(2);
      });
    }
  }

  function renderEarliestPill() {
    var pill = document.getElementById('bk-earliest');
    var text = document.getElementById('bk-earliest-text');
    var legend = document.getElementById('bk-cal-legend');
    if (!pill || !text) return;

    if (legend) legend.style.display = (calEarliest || Object.keys(calAvailable).length) ? '' : 'none';

    if (!calEarliest || state.date) {
      pill.style.display = 'none';
      return;
    }

    var dt = parseDateString(calEarliest.date);
    var dayLabel = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
    var label;
    if (calEarliest.time) {
      var timeLabel = formatTime12h(calEarliest.time);
      label = 'Earliest available: <strong>' + dayLabel + ' at ' + timeLabel + '</strong>';
    } else {
      label = 'Earliest available: <strong>' + dayLabel + '</strong>';
    }
    text.innerHTML = label;
    pill.style.display = '';

    if (!pill.__bkBound) {
      pill.__bkBound = true;
      pill.addEventListener('click', function () {
        if (!calEarliest) return;
        var targetDt = parseDateString(calEarliest.date);
        if (targetDt.getFullYear() !== calYear || (targetDt.getMonth() + 1) !== calMonth) {
          calYear = targetDt.getFullYear();
          calMonth = targetDt.getMonth() + 1;
          loadMonth();
          // Wait for loadMonth to finish before selecting + advancing.
          setTimeout(function () {
            selectDate(calEarliest.date);
            applyEarliestTime();
            tryAdvanceFromPill();
          }, 250);
          return;
        }
        selectDate(calEarliest.date);
        applyEarliestTime();
        // applyEarliestTime sets a 50ms timer for time-slot picking, so we
        // wait a bit longer here so the time has actually been applied
        // before we check whether Continue is unblocked.
        setTimeout(tryAdvanceFromPill, 100);
      });
    }
  }

  function tryAdvanceFromPill() {
    var nextBtn = document.getElementById('bk-next-2');
    if (nextBtn && !nextBtn.disabled) {
      nextBtn.click();
      return;
    }
    // Continue is blocked — most likely because a receiving method is
    // required and not yet picked. Scroll the dropdown into view and
    // pulse it so the customer sees what's blocking them.
    var receiving = document.getElementById('bk-receiving');
    if (receiving) {
      receiving.scrollIntoView({ behavior: 'smooth', block: 'center' });
      receiving.classList.add('bk-flash-attention');
      receiving.focus({ preventScroll: true });
      setTimeout(function () { receiving.classList.remove('bk-flash-attention'); }, 1800);

      // Show a brief inline note above the dropdown so the reason is
      // explicit, not just a flash. Replace any existing note first.
      var existingNote = document.getElementById('bk-earliest-blocker-note');
      if (existingNote) existingNote.remove();
      var note = document.createElement('div');
      note.id = 'bk-earliest-blocker-note';
      note.className = 'bk-earliest-blocker-note';
      note.textContent = 'Pick how you\'re dropping off to continue.';
      receiving.parentNode.insertBefore(note, receiving);
      setTimeout(function () {
        if (note && note.parentNode) note.parentNode.removeChild(note);
      }, 4000);
    }
  }

  function applyEarliestTime() {
    if (calEarliest && calEarliest.time && bookingMode === 'time_slots') {
      setTimeout(function () {
        var btn = document.querySelector('[data-bk-time="' + calEarliest.time + '"]');
        if (btn) btn.click();
      }, 50);
    }
  }

  function parseDateString(s) {
    var parts = s.split('-');
    return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
  }

  function formatTime12h(hhmm) {
    var parts = hhmm.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + m + ' ' + ampm;
  }

  function initCalendar() {
    loadMonth();
  }

  function loadMonth() {
    var label = document.getElementById('cal-month-label');
    var loading = document.getElementById('cal-loading');
    var grid    = document.getElementById('cal-grid');
    if (!label || !grid) return;

    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    label.textContent = months[calMonth - 1] + ' ' + calYear;

    if (loading) loading.style.display = 'block';

    // Clear day cells (keep day name headers)
    var headers = Array.from(grid.querySelectorAll('.bk-cal-day-name'));
    grid.innerHTML = '';
    headers.forEach(function (h) { grid.appendChild(h); });

    fetch(d.availUrl + '?year=' + calYear + '&month=' + calMonth, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (loading) loading.style.display = 'none';
      calAvailable = {};
      (resp.dates || []).forEach(function (dt) { calAvailable[dt] = true; });
      calUnavailable = {};
      (resp.unavailable_dates || []).forEach(function (dt) { calUnavailable[dt] = true; });
      calEarliest = resp.earliest || null;
      calTimeSlots = resp.slots || {};
      calPdWindows = resp.pd_windows || {}; // MARKER-PATCH-512
      calCapacity  = resp.capacity || {};   // MARKER-PATCH-518
      calSlotResources = resp.slot_resources || {};
      renderCalendar();
      renderEarliestPill();
    })
    .catch(function () {
      if (loading) loading.style.display = 'none';
      renderCalendar();
    });
  }

  // ======================================================================
  // MARKER-PATCH-518 — Day / Week / Month customer views
  // ======================================================================
  function capLabel(ds) {
    var c = calCapacity[ds];
    if (!c) return null;
    if (c.left === null || c.left === undefined) return 'open';
    return bookingMode === 'time_slots'
      ? c.left + (c.left === 1 ? ' time' : ' times')
      : c.left + ' left';
  }

  function ensureViewBar() {
    if (document.getElementById('bk-viewbar')) return;
    var grid = document.getElementById('cal-grid');
    if (!grid || !grid.parentElement) return;
    var bar = document.createElement('div');
    bar.id = 'bk-viewbar';
    bar.style.cssText = 'display:flex;gap:4px;margin:0 0 12px;background:rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:3px;width:fit-content';
    ['day', 'week', 'month'].forEach(function (v) {
      var b = document.createElement('button');
      b.type = 'button';
      b.dataset.view = v;
      b.textContent = v.charAt(0).toUpperCase() + v.slice(1);
      b.style.cssText = 'font-size:12px;font-weight:600;padding:6px 14px;border-radius:7px;border:0;cursor:pointer;background:transparent;color:var(--p-text);font-family:inherit;opacity:.65';
      b.addEventListener('click', function () { calView = v; paintViewBar(); renderCalendar(); });
      bar.appendChild(b);
    });
    grid.parentElement.insertBefore(bar, grid);
    paintViewBar();
  }

  function paintViewBar() {
    var bar = document.getElementById('bk-viewbar');
    if (!bar) return;
    bar.querySelectorAll('button').forEach(function (b) {
      var on = b.dataset.view === calView;
      b.style.background = on ? 'var(--p-accent)' : 'transparent';
      b.style.color      = on ? 'var(--p-accent-text)' : 'var(--p-text)';
      b.style.opacity    = on ? '1' : '.65';
    });
  }

  function altContainer() {
    var el = document.getElementById('bk-altview');
    if (!el) {
      el = document.createElement('div');
      el.id = 'bk-altview';
      var grid = document.getElementById('cal-grid');
      grid.parentElement.insertBefore(el, grid.nextSibling);
    }
    return el;
  }

  function fmtDayLabel(d) {
    return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
  }

  function renderWeekView() {
    var alt = altContainer();
    alt.innerHTML = '';
    var anchor = state.date ? new Date(state.date + 'T12:00:00') : new Date();
    if (anchor < today) anchor = new Date();
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:repeat(7,1fr);gap:7px';
    for (var i = 0; i < 7; i++) {
      var d = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() + i);
      var ds = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
      var open = !!calAvailable[ds];
      var c = calCapacity[ds];
      var col = document.createElement('div');
      var sel = ds === state.date;
      col.style.cssText = 'text-align:center;padding:10px 4px;border:1.5px solid ' + (sel ? 'var(--p-accent)' : 'rgba(0,0,0,.1)') + ';border-radius:10px;cursor:' + (open ? 'pointer' : 'default') + ';opacity:' + (open ? '1' : '.38') + (sel ? ';background:color-mix(in srgb, var(--p-accent) 12%, transparent)' : '');
      var pct = (c && c.max) ? Math.max(0, Math.min(1, ((c.max - c.left) / c.max))) : null;
      col.innerHTML =
        '<div style="font-size:10px;opacity:.6">' + fmtDayLabel(d) + '</div>' +
        '<div style="font-size:15px;font-weight:600;margin:1px 0 6px">' + d.getDate() + '</div>' +
        (open
          ? (pct !== null
              ? '<div style="height:5px;border-radius:99px;background:rgba(0,0,0,.1);overflow:hidden"><div style="height:100%;width:' + Math.round(pct * 100) + '%;background:var(--p-accent)"></div></div><div style="font-size:9.5px;margin-top:4px;opacity:.7">' + capLabel(ds) + '</div>'
              : '<div style="font-size:9.5px;opacity:.7">' + (capLabel(ds) || 'open') + '</div>')
          : '<div style="font-size:9.5px;opacity:.7">—</div>');
      if (open) (function (dstr) { col.addEventListener('click', function () { selectDate(dstr); renderCalendar(); }); })(ds);
      row.appendChild(col);
    }
    alt.appendChild(row);
  }

  function renderDayView() {
    var alt = altContainer();
    alt.innerHTML = '';
    var ds = state.date || (calEarliest && calEarliest.date);
    if (!ds) { alt.innerHTML = '<div style="font-size:13px;opacity:.6;padding:10px 0">Pick a date from the month view first.</div>'; return; }
    var d = new Date(ds + 'T12:00:00');
    var c = calCapacity[ds];
    var head = document.createElement('div');
    head.style.cssText = 'font-size:14px;font-weight:600;margin-bottom:10px';
    head.textContent = fmtDayLabel(d) + ', ' + d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    alt.appendChild(head);

    if (bookingMode === 'drop_off') {
      var card = document.createElement('div');
      card.style.cssText = 'border:1.5px solid var(--p-accent);border-radius:12px;padding:16px;background:color-mix(in srgb, var(--p-accent) 10%, transparent)';
      var leftTxt = c ? (c.left === null ? 'Open' : c.left + (c.max ? ' <span style="font-size:13px;opacity:.6;font-weight:500">of ' + c.max + '</span>' : '')) : '—';
      card.innerHTML =
        '<div style="font-size:11.5px;opacity:.7;margin-bottom:2px">' + ((calPdWindows[ds] || []).length ? 'Pickup spots left' : 'Drop-off spots left') + '</div>' +
        '<div style="font-size:26px;font-weight:700;letter-spacing:-.02em">' + leftTxt + '</div>';
      alt.appendChild(card);
      // window picker / receiving flow continues below via selectDate's DOM
    } else {
      renderTimeSlots(ds); // reuses the existing picker under the grid
    }
  }

  function renderCalendar() {
    var grid = document.getElementById('cal-grid');
    if (!grid) return;

    // Remove existing day cells
    Array.from(grid.querySelectorAll('.bk-cal-day')).forEach(function (d) { d.remove(); });

    var firstDay  = new Date(calYear, calMonth - 1, 1).getDay(); // 0=Sun
    var daysInMonth = new Date(calYear, calMonth, 0).getDate();
    var todayStr  = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

    // Empty cells for offset
    for (var i = 0; i < firstDay; i++) {
      var empty = document.createElement('div');
      empty.className = 'bk-cal-day';
      grid.appendChild(empty);
    }

    for (var day = 1; day <= daysInMonth; day++) {
      var dateStr = calYear + '-' + pad(calMonth) + '-' + pad(day);
      var cell    = document.createElement('div');
      cell.textContent = day;
      cell.className   = 'bk-cal-day';

      if (dateStr === todayStr) cell.classList.add('today');

      if (calAvailable[dateStr]) {
        cell.classList.add('available');
        if (dateStr === state.date) cell.classList.add('selected');
        // MARKER-PATCH-518 — capacity chip
        var capInfo = capLabel(dateStr);
        if (capInfo) {
          var chip = document.createElement('span');
          chip.textContent = capInfo;
          chip.style.cssText = 'display:block;font-size:8.5px;font-weight:600;line-height:1;margin-top:2px;opacity:.75';
          cell.appendChild(chip);
        }
        (function (ds) {
          cell.addEventListener('click', function () { selectDate(ds); });
        })(dateStr);
      } else if (calUnavailable[dateStr]) {
        cell.classList.add('unavailable');
      }

      grid.appendChild(cell);
    }

    // MARKER-PATCH-518 — view routing: month shows the grid, week/day swap it out
    ensureViewBar();
    var altEl = document.getElementById('bk-altview');
    if (calView === 'month') {
      grid.style.display = '';
      if (altEl) altEl.innerHTML = '';
    } else {
      grid.style.display = 'none';
      if (calView === 'week') renderWeekView(); else renderDayView();
    }
  }

  function selectDate(dateStr) {
    state.date = dateStr;
    state.appointmentTime = null;
    state.resourceId = null;
    var existingPicker = document.getElementById('bk-resource-picker');
    if (existingPicker) existingPicker.remove();
    document.querySelectorAll('.bk-cal-day').forEach(function (c) {
      c.classList.toggle('selected', c.textContent == parseInt(dateStr.split('-')[2], 10) && calAvailable[dateStr]);
    });
    renderCalendar();

    // Time slot mode — show time picker
    if (bookingMode === 'time_slots') {
      renderTimeSlots(dateStr);
    }

    // MARKER-PATCH-512 — pickup & delivery: window picker on drop_off dates
    state.pdWindowId = null;
    var pdExisting = document.getElementById('bk-pd-windows');
    if (pdExisting) pdExisting.remove();
    if (bookingMode === 'drop_off' && (calPdWindows[dateStr] || []).length) {
      renderPdWindows(dateStr);
    }

    renderEarliestPill();
    updateNext2();
  }

  // MARKER-PATCH-512 — pickup window picker (mirrors renderTimeSlots)
  function renderPdWindows(dateStr) {
    var windows = calPdWindows[dateStr] || [];
    var wrap = document.createElement('div');
    wrap.id = 'bk-pd-windows';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Pickup window — we come to you';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    windows.forEach(function (w) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = w.label + (w.full ? ' · full' : ' · ' + w.remaining + (w.remaining === 1 ? ' stop left' : ' stops left'));
      btn.disabled = !!w.full;
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text)' + (w.full ? ';opacity:.4;cursor:default;text-decoration:line-through' : '');
      btn.addEventListener('click', function () {
        if (w.full) return;
        state.pdWindowId = w.id;
        grid.querySelectorAll('button').forEach(function (b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background  = 'var(--p-accent)';
        btn.style.borderColor = 'var(--p-accent)';
        btn.style.color       = 'var(--p-accent-text)';
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var cal = document.getElementById('bk-calendar');
    if (cal && cal.parentElement) cal.parentElement.appendChild(wrap);
    updateNext2();
  }

  function renderTimeSlots(dateStr) {
    var existing = document.getElementById('bk-time-slots');
    if (existing) existing.remove();

    var slots = calTimeSlots[dateStr] || [];
    if (slots.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-time-slots';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Available times';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    slots.forEach(function(slot) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = formatTime(slot);
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text)';
      btn.addEventListener('click', function() {
        state.appointmentTime = slot;
        grid.querySelectorAll('button').forEach(function(b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background   = 'var(--p-accent)';
        btn.style.borderColor  = 'var(--p-accent)';
        btn.style.color        = 'var(--p-accent-text)';
        renderResourcePicker(dateStr, slot);
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    document.getElementById('bk-calendar').after(wrap);
  }

  function renderResourcePicker(dateStr, time) {
    var existing = document.getElementById('bk-resource-picker');
    if (existing) existing.remove();
    state.resourceId = null;

    var resources = (d.resources || []);
    if (resources.length < 2) return; // single-resource: auto-assign server-side

    var freeIds = ((calSlotResources[dateStr] || {})[time]) || [];
    var freeResources = resources.filter(function (r) { return freeIds.indexOf(r.id) !== -1; });
    if (freeResources.length === 0) return;

    var wrap = document.createElement('div');
    wrap.id = 'bk-resource-picker';
    wrap.style.cssText = 'margin-top:16px';

    var label = document.createElement('div');
    label.style.cssText = 'font-size:13px;font-weight:500;margin-bottom:10px';
    label.textContent = 'Choose who';
    wrap.appendChild(label);

    var grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';

    freeResources.forEach(function (res) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.resourceId = res.id;
      btn.textContent = res.name;
      btn.style.cssText = 'padding:8px 14px;border:1.5px solid rgba(0,0,0,.12);border-radius:var(--p-r);font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;background:transparent;color:var(--p-text);display:inline-flex;align-items:center;gap:8px';

      // Color swatch
      if (res.color_hex) {
        var swatch = document.createElement('span');
        swatch.style.cssText = 'width:10px;height:10px;border-radius:50%;background:' + res.color_hex;
        btn.prepend(swatch);
      }

      btn.addEventListener('click', function () {
        state.resourceId = res.id;
        grid.querySelectorAll('button').forEach(function (b) {
          b.style.background = 'transparent';
          b.style.borderColor = 'rgba(0,0,0,.12)';
          b.style.color = 'var(--p-text)';
        });
        btn.style.background  = 'var(--p-accent)';
        btn.style.borderColor = 'var(--p-accent)';
        btn.style.color       = 'var(--p-accent-text)';
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    var timeSlotsEl = document.getElementById('bk-time-slots');
    if (timeSlotsEl) {
      timeSlotsEl.after(wrap);
    } else {
      document.getElementById('bk-calendar').after(wrap);
    }
  }

  function formatTime(timeStr) {
    try {
      var parts = timeStr.split(':');
      var h = parseInt(parts[0], 10);
      var m = parts[1];
      var ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      return h + ':' + m + ' ' + ampm;
    } catch(e) { return timeStr; }
  }

  function bindReceiving() {
    var sel = document.getElementById('bk-receiving');
    if (!sel) return;
    sel.addEventListener('change', function () {
      state.receivingMethod = sel.value;
      updateNext2();
    });
  }

  function canProceedStep2() {
    if (!state.date) return false;
    if (bookingMode === 'time_slots' && !state.appointmentTime) return false;
    // MARKER-PATCH-512 — a date with route windows requires picking one
    if (bookingMode === 'drop_off' && (calPdWindows[state.date] || []).length && !state.pdWindowId) return false;
    if (bookingMode === 'time_slots' && (d.resources || []).length >= 2 && !state.resourceId) return false;
    if (d.hasReceiving) {
      var sel = document.getElementById('bk-receiving');
      if (sel && !sel.value) return false;
    }
    return true;
  }

  function updateNext2() {
    var btn = document.getElementById('bk-next-2');
    if (btn) btn.disabled = !canProceedStep2();
  }

  // =========================================================================
  // Step 3 — Details
  // =========================================================================
  function canProceedStep3() {
    var fn = document.getElementById('bk-first-name');
    var ln = document.getElementById('bk-last-name');
    var em = document.getElementById('bk-email');
    if (!fn || !fn.value.trim()) { fn && fn.focus(); return false; }
    if (!ln || !ln.value.trim()) { ln && ln.focus(); return false; }
    if (!em || !em.value.trim() || !em.value.includes('@')) { em && em.focus(); return false; }

    // Required custom fields
    var missing = false;
    document.querySelectorAll('.bk-custom-field[required]').forEach(function (f) {
      if (!f.value.trim()) { missing = true; f.focus(); }
    });
    return !missing;
  }

  function collectDetails() {
    state.firstName = document.getElementById('bk-first-name')?.value.trim() || '';
    state.lastName  = document.getElementById('bk-last-name')?.value.trim()  || '';
    state.email     = document.getElementById('bk-email')?.value.trim()      || '';
    state.phone     = document.getElementById('bk-phone')?.value.trim()      || '';
    state.receivingMethod = document.getElementById('bk-receiving')?.value   || '';

    state.responses      = {};
    state.responseLabels = {};
    document.querySelectorAll('.bk-custom-field').forEach(function (f) {
      var key   = f.getAttribute('data-field-key');
      var label = f.getAttribute('data-field-label');
      var val   = f.type === 'checkbox' ? (f.checked ? 'Yes' : '') : f.value;
      if (key) {
        state.responses[key]      = val;
        state.responseLabels[key] = label;
      }
    });
  }

  // =========================================================================
  // Sidebar
  // =========================================================================
  function updateSidebar() {
    if (d.multiAsset && state.activeAsset) { state.assetSel[state.activeAsset] = cloneSel(state.selections); renderAssetTabs(); } // MARKER-PATCH-214b/d
    var container = document.getElementById('bk-sidebar-items');
    if (!container) return;
    if (d.multiAsset) {
      // MARKER-PATCH-214g — numbered per-bike groups (treatment C), prominent grand total
      var mHtml = '', mTotal = 0, anySvc = false, bikeNum = 0;
      (window.BkAssets || []).forEach(function (a) {
        var sels = state.assetSel[a.clientKey] || {};
        var ks = Object.keys(sels);
        if (!ks.length) return;
        anySvc = true; bikeNum++;
        var bikeSub = 0, lines = '';
        ks.forEach(function (k) {
          var sel = sels[k];
          lines += '<div class="bk-cart-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          bikeSub += sel.priceCents;
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var ap = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            lines += '<div class="bk-cart-line bk-cart-line--addon"><span>+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(ap) + '</span></div>';
            bikeSub += ap;
          });
        });
        mTotal += bikeSub;
        mHtml += '<div class="bk-cart-bike">'
              +    '<div class="bk-cart-head"><span class="bk-cart-idx">' + bikeNum + '</span>'
              +      '<span class="bk-cart-name">' + esc(a.name) + '</span>'
              +      '<span class="bk-cart-sub">' + fmtMoney(bikeSub) + '</span></div>'
              +    lines
              +  '</div>';
      });
      if (!anySvc) { container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>'; return; }
      mHtml += '<div class="bk-cart-total"><span>Total</span><span>' + fmtMoney(mTotal) + '</span></div>';
      container.innerHTML = mHtml;
      return;
    }
    var services = Object.values(state.selections);
    if (services.length === 0) {
      container.innerHTML = '<p class="bk-sidebar-empty">No items selected yet.</p>';
      return;
    }
    var html = ''; var total = 0;
    services.forEach(function (sel) {
      html += '<div class="bk-sidebar-line"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
      total += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (!cb) return;
        var name  = cb.getAttribute('data-addon-name') || '';
        var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
        html += '<div class="bk-sidebar-line" style="padding-left:16px;opacity:.85"><span>+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
        total += price;
      });
    });
    html += '<div class="bk-sidebar-total"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
    container.innerHTML = html;
  }

  // =========================================================================
  // Review
  // =========================================================================
  function renderReview() {
    updateSidebar();

    // Services
    var svc = document.getElementById('bk-review-services');
    if (svc) {
      var html = '';
      if (d.multiAsset) {
        (window.BkAssets || []).forEach(function (a) {
          var sels = state.assetSel[a.clientKey] || {};
          var ks = Object.keys(sels);
          html += '<div class="bk-review-asset"><div class="bk-review-asset-name">' + esc(a.name) + '</div>';
          if (!ks.length) html += '<div class="bk-review-row" style="opacity:.45"><span>No services</span><span></span></div>';
          ks.forEach(function (k) {
            var sel = sels[k];
            html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
            sel.addonIds.forEach(function (addonId) {
              var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
              if (!cb) return;
              html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(cb.getAttribute('data-addon-name') || '') + '</span><span>' + fmtMoney(parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0) + '</span></div>';
            });
          });
          html += '</div>';
        });
      } else {
        Object.values(state.selections).forEach(function (sel) {
          html += '<div class="bk-review-row"><span>' + esc(sel.serviceName) + '</span><span>' + fmtMoney(sel.priceCents) + '</span></div>';
          sel.addonIds.forEach(function (addonId) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
            if (!cb) return;
            var name  = cb.getAttribute('data-addon-name') || '';
            var price = parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
            html += '<div class="bk-review-row"><span class="bk-review-row-label">+ ' + esc(name) + '</span><span>' + fmtMoney(price) + '</span></div>';
          });
        });
      }
      var total = calcTotal();
      html += '<div class="bk-review-row" style="font-weight:700;border-top:1px solid rgba(0,0,0,.08);margin-top:8px;padding-top:8px"><span>Total</span><span>' + fmtMoney(total) + '</span></div>';
      svc.innerHTML = html || '<p style="opacity:.4;font-size:13px">None selected.</p>';
    }

    // Details
    var det = document.getElementById('bk-review-details');
    if (det) {
      var rows = [
        ['Date',    formatDate(state.date)],
        ['Name',    state.firstName + ' ' + state.lastName],
        ['Email',   state.email],
      ];
      if (state.phone)           rows.push(['Phone', state.phone]);
      if (state.receivingMethod) rows.push(['Drop-off', state.receivingMethod]);
      Object.keys(state.responses).forEach(function (k) {
        if (state.responses[k]) rows.push([state.responseLabels[k] || k, state.responses[k]]);
      });
      det.innerHTML = rows.map(function (r) {
        return '<div class="bk-review-row"><span class="bk-review-row-label">' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
      }).join('');
    }
  }

  // =========================================================================
  // Payment
  // =========================================================================
  window.selectPayment = function (method) {
    state.paymentMethod = method;
    document.querySelectorAll('.bk-payment-btn').forEach(function (b) {
      b.classList.toggle('selected', b.id === 'pay-' + method);
    });
    var sw = document.getElementById('bk-stripe-wrap');
    var pw = document.getElementById('bk-paypal-wrap');
    if (sw) sw.style.display = method === 'stripe' ? '' : 'none';
    if (pw) pw.style.display = method === 'paypal' ? '' : 'none';
  };

  function initStripe() {
    if (!window.Stripe || !d.stripePk) return;
    stripe = Stripe(d.stripePk);
    stripeElements = stripe.elements();
    stripeCard     = stripeElements.create('card', {
      style: {
        base: {
          fontFamily:  '-apple-system, sans-serif',
          fontSize:    '15px',
          color:       (getComputedStyle(document.body).color || '#111111'),
          '::placeholder': { color: '#888888' },
        },
      },
    });
    var mountEl = document.getElementById('bk-stripe-elements');
    if (mountEl) {
      // Mount after a tick so the element is visible
      setTimeout(function () { stripeCard.mount('#bk-stripe-elements'); }, 100);
    }
  }

  function initPayPal() {
    if (!window.paypal) return;
    window.paypal.Buttons({
      createOrder: function (data, actions) {
        return submitBooking('paypal', true).then(function (resp) {
          if (!resp || !resp.success) throw new Error(resp?.message || 'Booking failed');
          // PayPal expects an order ID — we get an approve_url back
          // We redirect instead of using the embedded flow to handle server-side capture
          window.location.href = resp.approve_url;
          return resp.order_id;
        });
      },
      onError: function (err) {
        showError('PayPal error: ' + err);
      },
    }).render('#bk-paypal-button-container');
  }

  window.handlePayment = function () {
    if (state.paymentMethod === 'paypal') {
      // Handled by PayPal button
      return;
    }
    if (state.paymentMethod === 'stripe') {
      handleStripe();
      return;
    }
    submitBooking('none');
  };

  function handleStripe() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }

    submitBooking('stripe', false).then(function (resp) {
      if (!resp || !resp.success) {
        showError(resp?.message || 'Booking failed. Please try again.');
        resetSubmitBtn();
        return;
      }
      if (!resp.client_secret) {
        // Free booking
        window.location.href = resp.redirect;
        return;
      }
      stripe.confirmCardPayment(resp.client_secret, {
        payment_method: { card: stripeCard }
      }).then(function (result) {
        if (result.error) {
          showError(result.error.message);
          resetSubmitBtn();
        } else {
          // MARKER-PATCH-385 — card cleared; materialize the appointment server-side.
          fetch(d.finalizeUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ pending_token: resp.pending_token }),
          }).then(function (r) { return r.json(); }).then(function (fin) {
            if (fin && fin.success && fin.redirect) {
              window.location.href = fin.redirect;
            } else {
              showError((fin && fin.message) || 'Your payment went through, but we could not finish the booking. Please contact us.');
              resetSubmitBtn();
            }
          }).catch(function () {
            showError('Your payment went through, but we could not finish the booking. Please contact us.');
            resetSubmitBtn();
          });
        }
      });
    });
  }

  // =========================================================================
  // Submit
  // =========================================================================
  window.submitBooking = function (paymentMethod, returnPromise) {
    var body = buildPayload(paymentMethod || state.paymentMethod);
    var promise = fetch(d.submitUrl, {
      method:  'POST',
      headers: {
        'Content-Type':     'application/json',
        'X-CSRF-TOKEN':     csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); });

    if (returnPromise) return promise;

    promise.then(function (resp) {
      if (!resp.success) { showError(resp.message || 'Booking failed.'); resetSubmitBtn(); return; }
      if (resp.redirect) { window.location.href = resp.redirect; return; }
      if (resp.payment === 'paypal' && resp.approve_url) { window.location.href = resp.approve_url; return; }
    }).catch(function () {
      showError('Network error. Please try again.');
      resetSubmitBtn();
    });

    return promise;
  };

  // ===== MARKER-PATCH-214b — per-asset service machinery =====
  function cloneSel(map) {
    var o = {};
    Object.keys(map || {}).forEach(function (k) {
      var s = map[k];
      o[k] = { serviceId: s.serviceId, serviceName: s.serviceName, priceCents: s.priceCents, durationMinutes: s.durationMinutes, addonIds: (s.addonIds || []).slice() };
    });
    return o;
  }
  function syncRowsToSelections() {
    document.querySelectorAll('.bk-service-row').forEach(function (row) {
      var sid = row.getAttribute('data-service-id');
      var sel = state.selections[sid];
      var btn = row.querySelector('.bk-service-add-btn');
      if (sel) { row.classList.add('is-selected'); if (btn) btn.textContent = '\u2713 Added'; }
      else { row.classList.remove('is-selected'); if (btn) btn.textContent = 'Add to booking'; }
      row.querySelectorAll('.bk-service-addon-check').forEach(function (cb) {
        cb.checked = !!(sel && sel.addonIds.indexOf(cb.getAttribute('data-addon-id')) !== -1);
      });
    });
  }
  function assetSubtotal(key) {
    var m = state.assetSel[key] || {}, t = 0;
    Object.keys(m).forEach(function (k) {
      var sel = m[k]; t += sel.priceCents;
      sel.addonIds.forEach(function (id) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }
  function renderAssetTabs() {
    var strip = document.getElementById('bk-asset-tabs');
    if (!strip) return;
    var html = '';
    (window.BkAssets || []).forEach(function (a) {
      var n = Object.keys(state.assetSel[a.clientKey] || {}).length;
      var on = a.clientKey === state.activeAsset;
      html += '<button type="button" class="bk-asset-tab' + (on ? ' on' : '') + '" data-k="' + a.clientKey + '">'
            + '<span class="bk-asset-tab-n">' + esc(a.name) + '</span>'
            + '<span class="bk-asset-tab-m">' + (n ? (n + ' service' + (n > 1 ? 's' : '') + ' \u00b7 ' + fmtMoney(assetSubtotal(a.clientKey))) : 'No services yet') + '</span>'
            + '</button>';
    });
    strip.innerHTML = html;
    strip.querySelectorAll('.bk-asset-tab').forEach(function (b) {
      b.addEventListener('click', function () { switchAsset(b.getAttribute('data-k')); });
    });
    var active = (window.BkAssets || []).filter(function (a) { return a.clientKey === state.activeAsset; })[0];
    var lbl = document.getElementById('bk-asset-choosing');
    if (lbl && active) lbl.innerHTML = 'Choosing services for <strong>' + esc(active.name) + '</strong>';
  }
  function switchAsset(key) {
    if (key === state.activeAsset) return;
    if (state.activeAsset) state.assetSel[state.activeAsset] = cloneSel(state.selections);
    state.activeAsset = key;
    state.selections = cloneSel(state.assetSel[key] || {});
    syncRowsToSelections();
    renderAssetTabs();
    updateSidebar();
  }
  function initAssetServices() {
    var assets = window.BkAssets || [];
    if (!assets.length) return;
    assets.forEach(function (a) { if (!state.assetSel[a.clientKey]) state.assetSel[a.clientKey] = {}; });
    var live = {}; assets.forEach(function (a) { live[a.clientKey] = true; });
    Object.keys(state.assetSel).forEach(function (k) { if (!live[k]) delete state.assetSel[k]; }); // MARKER-PATCH-214c prune removed bikes
    if (!live[state.activeAsset]) state.activeAsset = assets[0].clientKey;
    state.activeAsset = state.activeAsset || assets[0].clientKey;
    state.selections = cloneSel(state.assetSel[state.activeAsset]);
    var step1 = document.getElementById('bk-step-1');
    if (step1 && !document.getElementById('bk-asset-tabs')) {
      var wrap = document.createElement('div');
      wrap.innerHTML = '<div class="bk-asset-tabs" id="bk-asset-tabs"></div><div class="bk-asset-choosing" id="bk-asset-choosing"></div>';
      var toolbar = step1.querySelector('.bk-toolbar');
      if (toolbar) step1.insertBefore(wrap, toolbar);
      else step1.insertBefore(wrap, step1.children[2] || null);
    }
    renderAssetTabs();
    syncRowsToSelections();
  }

  function buildPayload(paymentMethod) {
    collectDetails();
    var items, assetsPayload = null, bkCustomerId = null;
    if (d.multiAsset) {
      items = [];
      assetsPayload = [];
      (window.BkAssets || []).forEach(function (a) {
        assetsPayload.push({ client_key: a.clientKey, name_snapshot: a.name, customer_asset_id: a.customerAssetId || null });
        var sels = state.assetSel[a.clientKey] || {};
        Object.keys(sels).forEach(function (k) {
          var s = sels[k];
          items.push({ service_item_id: s.serviceId, addon_ids: s.addonIds.slice(), asset_client_key: a.clientKey });
        });
      });
      bkCustomerId = (window.BkCustomer && window.BkCustomer.id) || null;
    } else {
      items = Object.values(state.selections).map(function (s) {
        return { service_item_id: s.serviceId, addon_ids: s.addonIds.slice() };
      });
    }
    var payload = {
      first_name: state.firstName, last_name: state.lastName,
      email: state.email, phone: state.phone,
      date: state.date, appointment_time: state.appointmentTime || null,
      route_window_id: state.pdWindowId || null, // MARKER-PATCH-512
      resource_id: state.resourceId || null,
      receiving_method: state.receivingMethod,
      items: items,
      responses: state.responses, response_labels: state.responseLabels,
      payment_method: paymentMethod,
    };
    if (assetsPayload) payload.assets = assetsPayload;
    if (bkCustomerId) payload.customer_id = bkCustomerId;
    return payload;
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function calcTotal() {
    var t = 0;
    if (d.multiAsset) {
      Object.keys(state.assetSel).forEach(function (ak) {
        Object.keys(state.assetSel[ak]).forEach(function (k) {
          var sel = state.assetSel[ak][k]; t += sel.priceCents;
          sel.addonIds.forEach(function (id) {
            var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + id + '"]');
            if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
          });
        });
      });
      return t;
    }
    Object.values(state.selections).forEach(function (sel) {
      t += sel.priceCents;
      sel.addonIds.forEach(function (addonId) {
        var cb = document.querySelector('.bk-service-addon-check[data-service-id="' + sel.serviceId + '"][data-addon-id="' + addonId + '"]');
        if (cb) t += parseInt(cb.getAttribute('data-addon-price-cents'), 10) || 0;
      });
    });
    return t;
  }

  function fmtMoney(cents) {
    return d.currency + (cents / 100).toFixed(2);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function fmtDate(ds) {
    if (!ds) return '';
    var dt;
    if (ds instanceof Date) {
      dt = ds;
    } else {
      var parts = String(ds).split('-');
      dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }
    return dt.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function formatDate(ds) { return fmtDate(ds); }

  function showError(msg) {
    var el = document.getElementById('bk-form-error');
    if (el) { el.textContent = msg; el.style.display = ''; el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
  }

  function resetSubmitBtn() {
    var btn = document.getElementById('bk-submit-btn');
    if (btn) { btn.disabled = false; btn.textContent = state.paymentMethod === 'none' ? 'Confirm booking' : 'Pay & confirm'; }
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

}());


/* ===== MARKER-PATCH-214 — multi-asset pre-flow (You + Bikes) ===== */
(function () {
  var d = window.BkData || {};
  if (!d.multiAsset) return;
  var pre = document.getElementById('bk-preflow');
  if (!pre) return;

  var path = 'new', customerId = null, firstName = '', lastName = '', custEmail = '', custPhone = '';
  var assets = [];
  var kn = 0;
  function nk() { return 'a' + (++kn); }
  function el(id) { return document.getElementById(id); }
  function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

  var panelIntro = el('bk-pre-intro');
  var panelBikes = el('bk-pre-bikes');

  function showPanel(which) {
    panelIntro.classList.toggle('active', which === 'intro');
    panelBikes.classList.toggle('active', which === 'bikes');
    var youDot = document.querySelector('.bk-step--pre[data-pre="intro"]');
    var bikeDot = document.querySelector('.bk-step--pre[data-pre="bikes"]');
    if (youDot && bikeDot) {
      youDot.classList.toggle('active', which === 'intro');
      youDot.classList.toggle('done', which === 'bikes');
      bikeDot.classList.toggle('active', which === 'bikes');
      bikeDot.classList.remove('done');
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // intro toggle
  var toggle = el('bk-pre-toggle');
  toggle.querySelectorAll('button').forEach(function (b) {
    b.addEventListener('click', function () {
      path = b.getAttribute('data-path');
      toggle.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      toggle.setAttribute('data-pos', path === 'returning' ? 'right' : 'left');
      el('bk-pre-new').style.display = path === 'new' ? '' : 'none';
      el('bk-pre-returning').style.display = path === 'returning' ? '' : 'none';
    });
  });

  // new customer -> bikes (one empty card)
  el('bk-pre-new-continue').addEventListener('click', function () {
    if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false }];
    renderBikes(); showPanel('bikes');
  });

  // returning customer -> lookup
  el('bk-pre-lookup').addEventListener('click', function () {
    var email = (el('bk-pre-email').value || '').trim();
    var st = el('bk-pre-status');
    if (!email) { st.className = 'bk-pre-status show err'; st.textContent = 'Enter your email first.'; return; }
    st.className = 'bk-pre-status show'; st.textContent = 'Looking you up…';
    fetch(d.lookupUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': d.csrf },
      body: JSON.stringify({ email: email })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.found) {
          customerId = res.customer_id; firstName = res.first_name || ''; custEmail = email;
          lastName = res.last_name || ''; custPhone = res.phone || '';
          st.className = 'bk-pre-status show found';
          st.textContent = 'Welcome back' + (firstName ? (', ' + firstName) : '') + '! We pulled your ' + (d.assetPlural || 'items') + ' below.';
          assets = (res.assets || []).map(function (a) {
            return { clientKey: nk(), name: a.name, customerAssetId: a.id, fromAccount: true };
          });
          if (!assets.length) assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false }];
          el('bk-pre-bikes-sub').textContent = "We pulled these from your account — edit, remove, or add another.";
          setTimeout(function () { renderBikes(); showPanel('bikes'); }, 600);
        } else {
          custEmail = email;
          st.className = 'bk-pre-status show err';
          st.innerHTML = "We didn't find that email. <button type='button' id='bk-pre-asnew' style='text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;font:inherit;padding:0'>Continue as new →</button>";
          var asNew = el('bk-pre-asnew');
          if (asNew) asNew.addEventListener('click', function () {
            assets = [{ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false }];
            renderBikes(); showPanel('bikes');
          });
        }
      })
      .catch(function () { st.className = 'bk-pre-status show err'; st.textContent = 'Something went wrong — please try again.'; });
  });

  // bikes
  function findAsset(k) { for (var i = 0; i < assets.length; i++) if (assets[i].clientKey === k) return assets[i]; return null; }
  function namedCount() { return assets.filter(function (b) { return (b.name || '').trim() !== ''; }).length; }
  function updateContinue() { el('bk-pre-bikes-continue').disabled = namedCount() === 0; }

  function renderBikes() {
    var wrap = el('bk-pre-bike-list');
    var html = '';
    assets.forEach(function (b, i) {
      // MARKER-PATCH-214k — account assets are fixed (read-only); only new ones are editable
      html += '<div class="bk-pre-bike"><div class="bk-pre-bike-h"><span class="bk-pre-bike-idx">' + (i + 1) + '</span>';
      if (b.fromAccount) html += '<span class="bk-pre-bike-tag">From your account</span>';
      if (assets.length > 1) html += '<button type="button" class="bk-pre-bike-rm" data-k="' + b.clientKey + '">Remove</button>';
      html += '</div>';
      if (b.fromAccount) {
        html += '<div class="bk-pre-bike-fixed">' + escAttr(b.name) + '</div>';
      } else {
        html += '<input type="text" class="bk-input bk-pre-bike-name" data-k="' + b.clientKey + '" placeholder="Name this ' + escAttr(d.assetSingular || 'item') + '" value="' + escAttr(b.name) + '">';
      }
      html += '</div>';
    });
    wrap.innerHTML = html;
    wrap.querySelectorAll('.bk-pre-bike-name').forEach(function (inp) {
      inp.addEventListener('input', function () { var b = findAsset(inp.getAttribute('data-k')); if (b) b.name = inp.value; updateContinue(); });
    });
    wrap.querySelectorAll('.bk-pre-bike-rm').forEach(function (btn) {
      btn.addEventListener('click', function () { assets = assets.filter(function (x) { return x.clientKey !== btn.getAttribute('data-k'); }); renderBikes(); });
    });
    updateContinue();
  }

  el('bk-pre-add').addEventListener('click', function () {
    assets.push({ clientKey: nk(), name: '', customerAssetId: null, fromAccount: false }); renderBikes();
  });
  el('bk-pre-bikes-back').addEventListener('click', function () { showPanel('intro'); });

  el('bk-pre-bikes-continue').addEventListener('click', function () {
    var picked = assets
      .filter(function (b) { return (b.name || '').trim() !== ''; })
      .map(function (b) { return { clientKey: b.clientKey, name: b.name.trim(), customerAssetId: b.customerAssetId || null }; });
    if (!picked.length) return;

    // Hand off to the rest of booking.js (214b consumes these).
    window.BkAssets = picked;
    window.BkCustomer = { id: customerId, firstName: firstName, lastName: lastName, email: custEmail, phone: custPhone };
    if (customerId) {
      // MARKER-PATCH-214i — prefill + lock the Details fields for returning customers
      var lock = function (id, val) {
        var inp = document.getElementById(id);
        if (inp && val) { inp.value = val; inp.readOnly = true; inp.classList.add('bk-locked'); }
      };
      lock('bk-first-name', firstName);
      lock('bk-last-name', lastName);
      lock('bk-email', custEmail);
      lock('bk-phone', custPhone);
      var fn = document.getElementById('bk-first-name');
      if (fn && !document.getElementById('bk-returning-note')) {
        var note = document.createElement('div');
        note.id = 'bk-returning-note';
        note.className = 'bk-returning-note';
        note.innerHTML = '<strong>Welcome back, ' + escAttr(firstName) + '!</strong> Your contact details are filled in from your account.';
        var grid = fn.closest('.bk-field-grid-2'); // MARKER-PATCH-214j — note above the grid, not inside it
        if (grid && grid.parentElement) grid.parentElement.insertBefore(note, grid);
        else if (fn.parentElement && fn.parentElement.parentElement) fn.parentElement.parentElement.insertBefore(note, fn.parentElement);
      }
    }

    pre.classList.remove('active');
    document.querySelectorAll('.bk-step--pre').forEach(function (dot) { dot.classList.remove('active'); dot.classList.add('done'); });
    if (typeof window.goTo === 'function') window.goTo(1);
    if (typeof window.__bkInitAssetServices === 'function') window.__bkInitAssetServices(); // MARKER-PATCH-214c
  });
})();
