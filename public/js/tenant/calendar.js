/**
 * Calendar admin page — client-side interactivity.
 *
 * Vanilla JS, no framework. Reads window.IntakeAdmin for CSRF + tenant context.
 *
 * Responsibilities:
 *   - Position + tick the "now" line on today's view (60s interval)
 *   - Click existing appointment → navigate to detail page
 *   - Click empty grid cell → open quick-book modal pre-filled with resource + time
 *   - Quick-book: customer search/create, service pick, submit through BookingService
 */
(function () {
  'use strict';

  // ==========================================================================
  // Now-line positioning
  // ==========================================================================
  function initNowLine() {
    var shell = document.querySelector('.ia-cal-shell');
    if (!shell) return;
    var isToday = shell.getAttribute('data-cal-is-today') === '1';
    if (!isToday) return;

    var nowLine = document.getElementById('ia-cal-now-line');
    var nowLabel = document.getElementById('ia-cal-now-label');
    if (!nowLine || !nowLabel) return;

    var openMin  = parseInt(shell.getAttribute('data-cal-open-min'),  10);
    var closeMin = parseInt(shell.getAttribute('data-cal-close-min'), 10);
    var pxPerMin = parseFloat(shell.getAttribute('data-cal-px-per-min'));
    if (isNaN(openMin) || isNaN(closeMin) || isNaN(pxPerMin) || pxPerMin <= 0) return;

    function update() {
      var now = new Date();
      var nowMin = now.getHours() * 60 + now.getMinutes();
      if (nowMin < openMin || nowMin > closeMin) {
        nowLine.style.display = 'none';
        return;
      }
      var topPx = Math.round((nowMin - openMin) * pxPerMin);
      nowLine.style.display = 'block';
      nowLine.style.top = topPx + 'px';

      var h = now.getHours(), m = now.getMinutes();
      var ampm = h < 12 ? 'am' : 'pm';
      var h12  = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      nowLabel.textContent = h12 + ':' + (m < 10 ? '0' + m : m) + ampm;
    }
    update();
    setInterval(update, 60 * 1000);
  }

  // ==========================================================================
  // Calendar interactions: click appointment → navigate, click empty → modal
  // ==========================================================================
  function initCalendarClicks() {
    var shell = document.querySelector('.ia-cal-shell');
    if (!shell) return;

    // 1. Existing appointment click → navigate to detail page.
    document.querySelectorAll('.ia-cal-appt[data-appt-id]').forEach(function (el) {
      el.style.cursor = 'pointer';
      el.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = el.getAttribute('data-appt-id');
        window.location.href = '/admin/appointments/' + id;
      });
    });

    // 2. Empty grid cell click → open quick-book modal.
    var openMin  = parseInt(shell.getAttribute('data-cal-open-min'),  10);
    var pxPerMin = parseFloat(shell.getAttribute('data-cal-px-per-min'));
    var dateStr  = (function () {
      var u = new URL(window.location.href);
      return u.searchParams.get('date') || new Date().toISOString().slice(0, 10);
    })();

    document.querySelectorAll('.ia-cal-resource-col').forEach(function (col) {
      col.addEventListener('click', function (e) {
        // Only fire if the click landed on the column itself, not a child element.
        if (e.target !== col) return;

        var rect = col.getBoundingClientRect();
        var clickY = e.clientY - rect.top;
        var minutesFromOpen = Math.round(clickY / pxPerMin);
        var snappedMin = Math.round(minutesFromOpen / 30) * 30;
        var totalMin = openMin + snappedMin;
        var hh = Math.floor(totalMin / 60);
        var mm = totalMin % 60;
        var time = (hh < 10 ? '0' + hh : hh) + ':' + (mm < 10 ? '0' + mm : mm);
        var resourceId = col.getAttribute('data-resource-id');

        var resourceName = 'Resource';
        try {
          var headers = document.querySelectorAll('.ia-cal-resource-head');
          var cols = document.querySelectorAll('.ia-cal-resource-col');
          var idx = Array.prototype.indexOf.call(cols, col);
          if (headers[idx]) {
            var nameEl = headers[idx].querySelector('.ia-cal-resource-name');
            if (nameEl) resourceName = nameEl.textContent;
          }
        } catch (err) { /* fall back to default */ }

        // CALENDAR-FIRST-INTERCEPT v1: armed placement mode bypasses QuickBook.
        if (window.IntakePlacement && window.IntakePlacement.isArmed()) {
          var placed = window.IntakePlacement.resolveClick(col, e.clientY);
          window.IntakePlacement.disarm();
          if (window.ApptModal && typeof window.ApptModal.openPlaced === 'function') {
            window.ApptModal.openPlaced({
              date: dateStr,
              time: placed.time,
              resourceId: placed.resourceId,
              resourceName: placed.resourceName,
            });
          }
          return;
        }
        QuickBook.open({
          date: dateStr,
          time: time,
          resourceId: resourceId,
          resourceName: resourceName,
        });
      });
    });
  }

  // ==========================================================================
  // QuickBook modal — exposed as window.QuickBook
  // ==========================================================================
  var QuickBook = {
    state: { date: null, time: null, resourceId: null, customerId: null, services: [], customers: [], resources: [] },

    open: function (ctx) {
      ctx = ctx || {};
      // Defaults for any field not provided. Today, 09:00, no resource.
      var today = new Date();
      var defaultDate = today.getFullYear() + '-'
        + String(today.getMonth() + 1).padStart(2, '0') + '-'
        + String(today.getDate()).padStart(2, '0');

      this.state.date       = ctx.date       || defaultDate;
      this.state.time       = ctx.time       || '09:00';
      this.state.resourceId = ctx.resourceId || null;
      this.state.customerId = null;

      // Populate form fields with the defaults; user can change before submit.
      var dateEl = document.getElementById('qb-date');
      var timeEl = document.getElementById('qb-time');
      if (dateEl) dateEl.value = this.state.date;
      // Time input wants H:i (no seconds). Cell-click flow already passes H:i.
      if (timeEl) timeEl.value = this.state.time.split(':').slice(0, 2).join(':');

      document.getElementById('qb-error').style.display = 'none';
      ['qb-customer-search', 'qb-first-name', 'qb-last-name', 'qb-email', 'qb-phone']
        .forEach(function (id) { var e = document.getElementById(id); if (e) e.value = ''; });
      document.getElementById('qb-new-customer').style.display = 'block';
      document.getElementById('qb-customer-results').style.display = 'none';

      this.fetchPicker('');
      document.getElementById('qb-modal').style.display = 'flex';
      document.getElementById('qb-customer-search').focus();
    },

    close: function () {
      document.getElementById('qb-modal').style.display = 'none';
    },

    fetchPicker: function (search) {
      var url = '/admin/calendar/quick-book?customer_search=' + encodeURIComponent(search || '');
      fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          QuickBook.state.services  = data.services  || [];
          QuickBook.state.customers = data.customers || [];
          QuickBook.state.resources = data.resources || [];
          QuickBook.renderServices();
          QuickBook.renderCustomers();
          QuickBook.renderResources();
        });
    },

    renderResources: function () {
      var sel = document.getElementById('qb-resource');
      if (!sel) return;
      var preselect = this.state.resourceId;
      sel.innerHTML = '<option value="">Select a resource…</option>';
      this.state.resources.forEach(function (r) {
        var opt = document.createElement('option');
        opt.value = r.id;
        opt.textContent = r.name + (r.subtitle ? ' · ' + r.subtitle : '');
        if (preselect && r.id === preselect) opt.selected = true;
        sel.appendChild(opt);
      });
      // If we have no preselect, default to first resource.
      if (!preselect && this.state.resources.length > 0) {
        sel.value = this.state.resources[0].id;
      }
    },

    renderServices: function () {
      var sel = document.getElementById('qb-service');
      sel.innerHTML = '<option value="">Select a service…</option>';
      this.state.services.forEach(function (s) {
        var opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name + ' · ' + s.duration_minutes + ' min · $' + (s.price_cents / 100).toFixed(2);
        sel.appendChild(opt);
      });
    },

    renderCustomers: function () {
      var box = document.getElementById('qb-customer-results');
      var search = (document.getElementById('qb-customer-search').value || '').trim();
      if (!search || this.state.customers.length === 0) {
        box.style.display = 'none';
        return;
      }
      box.innerHTML = '';
      this.state.customers.forEach(function (c) {
        var row = document.createElement('div');
        row.className = 'qb-result-row';
        row.innerHTML = '<strong>' + (c.first_name || '') + ' ' + (c.last_name || '') + '</strong>'
                      + '<span class="qb-result-email">' + (c.email || '') + '</span>';
        row.addEventListener('click', function () {
          QuickBook.state.customerId = c.id;
          document.getElementById('qb-customer-search').value =
            (c.first_name || '') + ' ' + (c.last_name || '') + ' (' + (c.email || '') + ')';
          document.getElementById('qb-new-customer').style.display = 'none';
          box.style.display = 'none';
        });
        box.appendChild(row);
      });
      box.style.display = this.state.customers.length ? 'block' : 'none';
    },

    submit: function () {
      var btn = document.getElementById('qb-submit');
      btn.disabled = true; btn.textContent = 'Booking…';
      var err = document.getElementById('qb-error');
      err.style.display = 'none';

      var serviceId = document.getElementById('qb-service').value;
      if (!serviceId) {
        return this.showError('Pick a service.', btn);
      }

      // Read date / time / resource from the form fields. These are the
      // source of truth at submit time; state holds defaults only.
      var dateEl     = document.getElementById('qb-date');
      var timeEl     = document.getElementById('qb-time');
      var resourceEl = document.getElementById('qb-resource');

      var dateValue     = dateEl ? dateEl.value : this.state.date;
      var timeValue     = timeEl ? timeEl.value : this.state.time;
      var resourceValue = resourceEl ? resourceEl.value : this.state.resourceId;

      if (!dateValue)     return this.showError('Pick a date.', btn);
      if (!timeValue)     return this.showError('Pick a time.', btn);
      if (!resourceValue) return this.showError('Pick a resource.', btn);

      // Time input returns H:i; controller wants H:i:s.
      var apptTime = timeValue.split(':').slice(0, 2).join(':') + ':00';

      var body = {
        date: dateValue,
        appointment_time: apptTime,
        resource_id: resourceValue,
        service_item_id: serviceId,
      };

      if (this.state.customerId) {
        body.customer_id = this.state.customerId;
      } else {
        body.first_name = document.getElementById('qb-first-name').value.trim();
        body.last_name  = document.getElementById('qb-last-name').value.trim();
        body.email      = document.getElementById('qb-email').value.trim();
        body.phone      = document.getElementById('qb-phone').value.trim();
        if (!body.first_name || !body.last_name || !body.email) {
          return this.showError('First name, last name, and email are required for a new customer.', btn);
        }
      }

      fetch('/admin/calendar/quick-book', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': window.IntakeAdmin.csrfToken,
        },
        body: JSON.stringify(body),
      })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (resp) {
        if (resp.body && resp.body.success) {
          // Strip ?customer_id= so the post-reload page doesn't re-trigger
          // the prefill IIFE and reopen the modal.
          var u = new URL(window.location.href);
          if (u.searchParams.has('customer_id')) {
            u.searchParams.delete('customer_id');
            window.location.href = u.toString();
          } else {
            window.location.reload();
          }
        } else {
          QuickBook.showError(resp.body.message || 'Booking failed.', btn);
        }
      })
      .catch(function () {
        QuickBook.showError('Network error.', btn);
      });
    },

    showError: function (msg, btn) {
      var err = document.getElementById('qb-error');
      err.textContent = msg;
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Book appointment';
    },

    formatTime: function (t) {
      var parts = t.split(':');
      var h = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
      var ampm = h < 12 ? 'am' : 'pm';
      var h12  = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      return h12 + ':' + (m < 10 ? '0' + m : m) + ampm;
    },
  };
  window.QuickBook = QuickBook;

  function bindSearch() {
    var input = document.getElementById('qb-customer-search');
    if (!input) return;
    var debounce;
    input.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () {
        QuickBook.fetchPicker(input.value);
        QuickBook.state.customerId = null;
        document.getElementById('qb-new-customer').style.display = 'block';
      }, 200);
    });
  }

  // ==========================================================================
  // Resource filter chips
  // - Click toggles the chip on/off
  // - "All" chip resets to all-on
  // - URL drives state via ?resources=uuid1,uuid2 or ?resources=all
  // - Last selection persisted to localStorage (per-tenant key) and replayed
  //   on cold landing when no ?resources= param is in the URL
  // ==========================================================================
  var FILTER_STORAGE_KEY = 'ia.calendar.filter.resources';

  function tenantFilterKey() {
    // Scope the saved selection per-tenant so switching tenants doesn't bleed
    // resource IDs that don't exist in the current tenant.
    return FILTER_STORAGE_KEY + ':' + (window.location.host || 'default');
  }

  function readStoredFilter() {
    try { return localStorage.getItem(tenantFilterKey()); }
    catch (e) { return null; }
  }

  function writeStoredFilter(value) {
    try { localStorage.setItem(tenantFilterKey(), value); }
    catch (e) { /* localStorage disabled / private mode — no-op */ }
  }

  // Cold-landing restore: if URL has no ?resources= and we have a saved
  // selection, redirect once with the saved value applied. Runs synchronously
  // before any DOM handlers bind so it doesn't fight the filter UI.
  function restoreFilterFromStorageIfNeeded() {
    var u = new URL(window.location.href);
    if (u.searchParams.has('resources')) return; // URL wins, leave it
    var stored = readStoredFilter();
    if (!stored) return; // no prior session
    u.searchParams.set('resources', stored);
    window.location.replace(u.toString());
  }

  function initFilterChips() {
    var bar = document.getElementById('ia-cal-filter-bar');
    if (!bar) return;

    function getCurrentSelection() {
      // Read from the rendered chip states. is-on chips with data-resource-id
      // form the current filter set.
      var ids = [];
      bar.querySelectorAll('.ia-cal-fchip.is-on[data-resource-id]')
         .forEach(function (c) { ids.push(c.getAttribute('data-resource-id')); });
      return ids;
    }

    function navigate(resourceParam) {
      writeStoredFilter(resourceParam);
      var u = new URL(window.location.href);
      u.searchParams.set('resources', resourceParam);
      window.location.href = u.toString();
    }

    function navigateWithIds(ids) {
      var allCount = bar.querySelectorAll('.ia-cal-fchip[data-resource-id]').length;
      if (ids.length === 0 || ids.length === allCount) {
        navigate('all');
      } else {
        navigate(ids.join(','));
      }
    }

    // "All" chip — reset to all visible
    var allChip = bar.querySelector('[data-action="all"]');
    if (allChip) {
      allChip.addEventListener('click', function () {
        navigate('all');
      });
    }

    // Per-resource chips: click toggles. No double-click solo behavior —
    // users multi-select by clicking names; single-pick is achieved by
    // clicking one then clicking the rest off (or just having only the
    // ones they want on).
    bar.querySelectorAll('.ia-cal-fchip[data-resource-id]').forEach(function (chip) {
      var id = chip.getAttribute('data-resource-id');
      chip.addEventListener('click', function () {
        var current = getCurrentSelection();
        var idx = current.indexOf(id);
        if (idx >= 0) {
          current.splice(idx, 1);
        } else {
          current.push(id);
        }
        navigateWithIds(current);
      });
    });
  }

  // ==========================================================================
  // Mobile bottom-sheet filter — open/close + row interactions
  // Sheet uses the same URL-driven navigation as the desktop chips.
  // ==========================================================================
  var CalendarFilterSheet = {
    sheet: null,
    trigger: null,

    init: function () {
      this.sheet = document.getElementById('ia-cal-filter-sheet');
      this.trigger = document.getElementById('ia-cal-filter-trigger');
      if (!this.sheet || !this.trigger) return;

      var self = this;
      this.trigger.addEventListener('click', function () { self.open(); });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && self.sheet.classList.contains('is-open')) {
          self.close();
        }
      });

      this.sheet.querySelectorAll('.ia-cal-sheet-row').forEach(function (row) {
        var action = row.getAttribute('data-action');
        var resourceId = row.getAttribute('data-resource-id');
        row.addEventListener('click', function () {
          if (action === 'all') {
            self.navigate('all');
          } else if (resourceId) {
            self.toggle(resourceId);
          }
        });
      });
    },

    open: function () {
      if (!this.sheet) return;
      this.sheet.classList.add('is-open');
      this.sheet.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    },

    close: function () {
      if (!this.sheet) return;
      this.sheet.classList.remove('is-open');
      this.sheet.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    },

    navigate: function (resourceParam) {
      writeStoredFilter(resourceParam);
      var u = new URL(window.location.href);
      u.searchParams.set('resources', resourceParam);
      window.location.href = u.toString();
    },

    toggle: function (id) {
      var current = [];
      this.sheet.querySelectorAll('.ia-cal-sheet-row.is-on[data-resource-id]')
        .forEach(function (r) { current.push(r.getAttribute('data-resource-id')); });
      var idx = current.indexOf(id);
      if (idx >= 0) current.splice(idx, 1);
      else current.push(id);

      var allCount = this.sheet.querySelectorAll('.ia-cal-sheet-row[data-resource-id]').length;
      if (current.length === 0 || current.length === allCount) {
        this.navigate('all');
      } else {
        this.navigate(current.join(','));
      }
    },
  };
  window.CalendarFilterSheet = CalendarFilterSheet;

  function boot() {
    // First: restore filter from localStorage (may redirect — must run
    // before any DOM handlers wire up to avoid duplicate work)
    restoreFilterFromStorageIfNeeded();

    initNowLine();
    initCalendarClicks();
    bindSearch();
    initFilterChips();
    CalendarFilterSheet.init();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // ==========================================================================
  // Placement mode — calendar-first appointment placement
  // ==========================================================================
  // CALENDAR-FIRST-PLACEMENT-JS v1
  var Placement = {
    armed: false,
    slotMin: 30,
    serviceDurationMin: 30,    // mirrors slotMin until services chosen
    pxPerMin: 1.4,
    openMin: 0,
    closeMin: 1440,
    dateStr: null,
    ghost: null,
    ghostMeta: null,
    button: null,
    banner: null,
    cancelBtn: null,
    durationLabel: null,
    activeCol: null,
    preserved: null,           // stashed form state when "Change time" round-trips

    init: function () {
      var shell = document.querySelector('.ia-cal-shell');
      if (!shell) return;
      if (shell.getAttribute('data-view-mode') !== 'day') return;

      this.openMin  = parseInt(shell.getAttribute('data-cal-open-min'), 10) || 0;
      this.closeMin = parseInt(shell.getAttribute('data-cal-close-min'), 10) || 1440;
      this.pxPerMin = parseFloat(shell.getAttribute('data-cal-px-per-min')) || 1.4;
      this.slotMin  = parseInt(shell.getAttribute('data-cal-slot-min'), 10) || 30;
      this.serviceDurationMin = this.slotMin;

      var u = new URL(window.location.href);
      this.dateStr = u.searchParams.get('date') || new Date().toISOString().slice(0, 10);

      this.button         = document.getElementById('ia-cal-new-appt-btn');
      this.banner         = document.getElementById('ia-cal-placement-banner');
      this.cancelBtn      = document.getElementById('ia-cal-placement-cancel-btn');
      this.ghost          = document.getElementById('ia-cal-ghost-block');
      this.ghostMeta      = document.getElementById('ia-cal-ghost-meta');
      this.durationLabel  = document.getElementById('ia-cal-placement-duration');
      if (!this.button || !this.banner || !this.ghost) return;

      var self = this;
      this.button.addEventListener('click', function () {
        if (self.armed) self.disarm();
        else self.arm();
      });
      this.cancelBtn.addEventListener('click', function () { self.disarm(); });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && self.armed) {
          self.disarm();
          e.preventDefault();
        }
      });

      // Ghost tracking — bind mousemove on each resource column.
      document.querySelectorAll('.ia-cal-resource-col').forEach(function (col) {
        col.addEventListener('mousemove', function (e) {
          if (!self.armed) return;
          self.activeCol = col;
          self.positionGhost(col, e.clientY);
        });
        col.addEventListener('mouseleave', function () {
          if (!self.armed) return;
          self.ghost.hidden = true;
          self.activeCol = null;
        });
      });
    },

    arm: function () {
      this.armed = true;
      this.button.setAttribute('data-armed', '1');
      this.banner.hidden = false;
      document.body.classList.add('ia-cal-placement-armed');
      // (Ghost stays hidden until cursor enters a resource column.)
    },

    disarm: function () {
      this.armed = false;
      this.button.setAttribute('data-armed', '0');
      this.banner.hidden = true;
      this.ghost.hidden = true;
      document.body.classList.remove('ia-cal-placement-armed');
      this.activeCol = null;
    },

    isArmed: function () { return this.armed; },

    /** Compute snapped time + position for ghost given clientY in a column. */
    positionGhost: function (col, clientY) {
      var rect = col.getBoundingClientRect();
      var y = clientY - rect.top;
      var minutesFromOpen = Math.round(y / this.pxPerMin);
      var snap = this.slotMin;
      var snappedMin = Math.round(minutesFromOpen / snap) * snap;
      var totalMin = this.openMin + snappedMin;

      // Don't overflow past close time.
      if (totalMin + this.serviceDurationMin > this.closeMin) {
        totalMin = this.closeMin - this.serviceDurationMin;
        snappedMin = totalMin - this.openMin;
      }
      if (totalMin < this.openMin) {
        totalMin = this.openMin;
        snappedMin = 0;
      }

      var top    = Math.round(snappedMin * this.pxPerMin);
      var height = Math.round(this.serviceDurationMin * this.pxPerMin);

      // Anchor to column.
      this.ghost.style.left   = (rect.left + window.scrollX + 1) + 'px';
      this.ghost.style.top    = (rect.top  + window.scrollY + top) + 'px';
      this.ghost.style.width  = (rect.width - 2) + 'px';
      this.ghost.style.height = height + 'px';
      this.ghost.style.position = 'absolute';
      this.ghost.hidden = false;

      var endMin = totalMin + this.serviceDurationMin;
      this.ghostMeta.textContent = formatRange(totalMin, endMin);
    },

    /** Resolve current snapped time for a click event in a column. Returns {time, resourceId, resourceName}. */
    resolveClick: function (col, clientY) {
      var rect = col.getBoundingClientRect();
      var y = clientY - rect.top;
      var minutesFromOpen = Math.round(y / this.pxPerMin);
      var snap = this.slotMin;
      var snappedMin = Math.round(minutesFromOpen / snap) * snap;
      var totalMin = this.openMin + snappedMin;
      if (totalMin + this.serviceDurationMin > this.closeMin) {
        totalMin = this.closeMin - this.serviceDurationMin;
      }
      if (totalMin < this.openMin) totalMin = this.openMin;

      var hh = Math.floor(totalMin / 60);
      var mm = totalMin % 60;
      var time = (hh < 10 ? '0' + hh : hh) + ':' + (mm < 10 ? '0' + mm : mm);
      var resourceId = col.getAttribute('data-resource-id');
      var resourceName = 'Resource';
      try {
        var headers = document.querySelectorAll('.ia-cal-resource-head');
        var cols = document.querySelectorAll('.ia-cal-resource-col');
        var idx = Array.prototype.indexOf.call(cols, col);
        if (headers[idx]) {
          var nameEl = headers[idx].querySelector('.ia-cal-resource-name');
          if (nameEl) resourceName = nameEl.textContent.trim();
        }
      } catch (err) { /* fall back */ }
      return { time: time, resourceId: resourceId, resourceName: resourceName, totalMin: totalMin };
    },
  };
  window.IntakePlacement = Placement;

  function formatRange(startMin, endMin) {
    function fmt(m) {
      var h = Math.floor(m / 60), mm = m % 60;
      var ampm = h < 12 ? 'am' : 'pm';
      var h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      return h12 + (mm === 0 ? '' : ':' + (mm < 10 ? '0' + mm : mm)) + ampm;
    }
    return fmt(startMin) + '–' + fmt(endMin);
  }

  // Hook Placement init into existing boot flow.
  var _origBoot = boot;
  boot = function () {
    _origBoot();
    Placement.init();
  };
  if (document.readyState !== 'loading') Placement.init();

  console.log('[calendar] module loaded');
})();

/**
 * S4 — View persistence
 *
 * The view-switcher (Day | Week | Month) is anchor-based, so navigation
 * works without JS. This block adds two niceties on top:
 *
 *   1. Remember the last-used view in localStorage. If a user lands on
 *      /admin/calendar with no ?view= param, redirect to their preferred
 *      view (only if different from current — avoids redirect loops).
 *
 *   2. Update the saved view every time they click a switcher button.
 *
 * Storage key: 'intake.calendar.view'. Values: 'day' | 'week' | 'month'.
 */
(function () {
  'use strict';

  var KEY = 'intake.calendar.view';
  var VALID = ['day', 'week', 'month'];

  function getCurrent() {
    var shell = document.querySelector('.ia-cal-shell');
    return shell ? (shell.getAttribute('data-view-mode') || 'day') : 'day';
  }

  function getStored() {
    try {
      var v = localStorage.getItem(KEY);
      return VALID.indexOf(v) >= 0 ? v : null;
    } catch (e) {
      return null;
    }
  }

  function setStored(v) {
    try {
      if (VALID.indexOf(v) >= 0) localStorage.setItem(KEY, v);
    } catch (e) { /* ignore */ }
  }

  // On load: if URL has no ?view= param and stored preference differs from
  // the current (server-default) view, redirect once to the preferred view.
  // Skip if the URL already has ?view= — the user explicitly asked for it.
  function maybeRedirect() {
    var url = new URL(window.location.href);
    if (url.searchParams.has('view')) return;

    var stored = getStored();
    if (!stored) return;

    var current = getCurrent();
    if (stored === current) return;

    // Preserve ?date= and ?resources= if present.
    url.searchParams.set('view', stored);
    window.location.replace(url.toString());
  }

  // Persist whatever view the user is on right now (covers the case where
  // they clicked a switcher button — the new page loads in the new view).
  function persistCurrent() {
    setStored(getCurrent());
  }

  document.addEventListener('DOMContentLoaded', function () {
    maybeRedirect();
    persistCurrent();
  });
})();

/**
 * S4.1 — Legend panel toggle
 *
 * Click "?" / Legend in the toolbar to expand the explanation panel.
 * State persists in localStorage so it stays open across navigations
 * for shop owners who keep it open while learning, and stays closed
 * for everyone else.
 *
 * ESC closes. Click-outside does NOT close — the panel is reference
 * material, not a modal. If users want it gone they hit the toggle.
 */
(function () {
  'use strict';

  var KEY = 'intake.calendar.legend.open';
  var trigger;
  var panel;

  function isOpenStored() {
    try { return localStorage.getItem(KEY) === '1'; }
    catch (e) { return false; }
  }

  function setStored(open) {
    try { localStorage.setItem(KEY, open ? '1' : '0'); }
    catch (e) { /* ignore */ }
  }

  function open() {
    if (!panel || !trigger) return;
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    setStored(true);
  }

  function close() {
    if (!panel || !trigger) return;
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    setStored(false);
  }

  function toggle() {
    if (panel.hidden) open(); else close();
  }

  document.addEventListener('DOMContentLoaded', function () {
    trigger = document.getElementById('ia-cal-legend-trigger');
    panel   = document.getElementById('ia-cal-legend');
    if (!trigger || !panel) return;

    if (isOpenStored()) open(); else close();

    trigger.addEventListener('click', toggle);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) close();
    });
  });
})();



(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var prefill = window.IntakeCalendarPrefill;
    if (!prefill || !window.QuickBook) return;

    // Open the modal with default date/time/resource. User picks them.
    window.QuickBook.open({});

    // After fetchPicker completes, mark the prefilled customer as selected.
    // Poll briefly since fetchPicker is async.
    var attempts = 0;
    var poll = setInterval(function () {
      attempts++;
      if (window.QuickBook.state && window.QuickBook.state.services.length > 0) {
        clearInterval(poll);
        window.QuickBook.state.customerId = prefill.id;
        var label = (prefill.first_name || '') + ' ' + (prefill.last_name || '');
        if (prefill.email) label += ' (' + prefill.email + ')';
        var search = document.getElementById('qb-customer-search');
        if (search) search.value = label;
        var newBlock = document.getElementById('qb-new-customer');
        if (newBlock) newBlock.style.display = 'none';
        var results = document.getElementById('qb-customer-results');
        if (results) results.style.display = 'none';
      }
      if (attempts > 30) clearInterval(poll);
    }, 100);
  });
})();

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('qb-modal');
    if (!modal) return;

    var tabs = modal.querySelectorAll('[data-qb-tab]');
    var panes = modal.querySelectorAll('[data-qb-pane]');
    var modeBtns = modal.querySelectorAll('[data-qb-mode]');
    var modePanes = modal.querySelectorAll('[data-qb-mode-pane]');
    var chips = modal.querySelectorAll('[data-qb-duration]');
    var customInput = document.getElementById('qb-duration-custom');
    var labelInput = document.getElementById('qb-break-label');
    var dateEl = document.getElementById('qb-date');
    var submitBtn = document.getElementById('qb-submit');
    var restHelper = document.getElementById('qb-rest-helper');

    var currentTab = 'appointment';
    var currentMode = 'duration';
    var currentDuration = 30;

    function setTab(tab) {
      currentTab = tab;
      tabs.forEach(function (t) {
        t.classList.toggle('is-active', t.getAttribute('data-qb-tab') === tab);
      });
      panes.forEach(function (p) {
        p.style.display = p.getAttribute('data-qb-pane') === tab ? '' : 'none';
      });
      if (submitBtn) {
        submitBtn.textContent = tab === 'break' ? 'Save break' : 'Book appointment';
      }
      // Clear any error visible from the other tab
      var err = document.getElementById('qb-error');
      if (err) err.style.display = 'none';

      // Refresh rest-of-day helper if Break tab opened
      if (tab === 'break') updateRestHelper();
    }

    function setMode(mode) {
      currentMode = mode;
      modeBtns.forEach(function (b) {
        var on = b.getAttribute('data-qb-mode') === mode;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-checked', on ? 'true' : 'false');
      });
      modePanes.forEach(function (p) {
        p.style.display = p.getAttribute('data-qb-mode-pane') === mode ? '' : 'none';
      });
      if (mode === 'rest_of_day') updateRestHelper();
    }

    function setDuration(minutes) {
      currentDuration = minutes;
      chips.forEach(function (c) {
        c.classList.toggle('is-active', parseInt(c.getAttribute('data-qb-duration'), 10) === minutes);
      });
      if (customInput) customInput.value = '';
    }

    /**
     * Update the "Rest of day" helper text based on the currently-selected
     * date. Reads business_hours from QuickBook.state.businessHours, which
     * is loaded by fetchPicker.
     *
     * If the selected day has no business hours, disable the rest_of_day
     * mode and show an explanation. Force back to duration mode.
     */
    function updateRestHelper() {
      if (!restHelper || !dateEl || !window.QuickBook) return;
      var hours = window.QuickBook.state.businessHours;
      if (!hours) {
        restHelper.textContent = 'Loading business hours...';
        return;
      }

      var dateValue = dateEl.value;
      if (!dateValue) {
        restHelper.textContent = 'Pick a date.';
        return;
      }

      // dateValue is YYYY-MM-DD. JS Date parses it as UTC midnight, which
      // can flip the day-of-week in negative timezones. Parse manually.
      var parts = dateValue.split('-');
      var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      var dow = d.getDay(); // 0=Sun..6=Sat

      var dayHours = hours[dow] || hours[String(dow)];
      var restModeBtn = modal.querySelector('[data-qb-mode="rest_of_day"]');

      if (!dayHours) {
        restHelper.textContent = 'No business hours set for this day.';
        restHelper.classList.add('is-unavailable');
        if (restModeBtn) {
          restModeBtn.classList.add('is-disabled');
          restModeBtn.setAttribute('disabled', 'disabled');
        }
        // Force duration mode if rest_of_day was active
        if (currentMode === 'rest_of_day') setMode('duration');
        return;
      }

      restHelper.classList.remove('is-unavailable');
      if (restModeBtn) {
        restModeBtn.classList.remove('is-disabled');
        restModeBtn.removeAttribute('disabled');
      }
      restHelper.innerHTML = 'Blocks from start time until <strong>' + formatTime(dayHours.close) + '</strong>.';
    }

    /** Convert "HH:MM" 24h to "h:MM AM/PM". */
    function formatTime(hm) {
      var p = hm.split(':');
      var h = parseInt(p[0], 10);
      var m = parseInt(p[1], 10);
      var ampm = h < 12 ? 'AM' : 'PM';
      var h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      return h12 + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
    }

    // Wire up tab clicks
    tabs.forEach(function (t) {
      t.addEventListener('click', function () { setTab(t.getAttribute('data-qb-tab')); });
    });

    // Wire up mode toggle clicks
    modeBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        if (b.classList.contains('is-disabled')) return;
        setMode(b.getAttribute('data-qb-mode'));
      });
    });

    // Wire up duration chips
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        setDuration(parseInt(c.getAttribute('data-qb-duration'), 10));
      });
    });

    // Custom duration input — clears chip selection on focus, and updates
    // currentDuration if a valid number is entered.
    if (customInput) {
      customInput.addEventListener('input', function () {
        var v = parseInt(customInput.value, 10);
        if (!isNaN(v) && v > 0) {
          currentDuration = v;
          chips.forEach(function (c) { c.classList.remove('is-active'); });
        }
      });
    }

    // Update rest-of-day helper whenever the date changes
    if (dateEl) {
      dateEl.addEventListener('change', function () {
        if (currentTab === 'break' && currentMode === 'rest_of_day') updateRestHelper();
        else if (currentTab === 'break') updateRestHelper();
      });
    }

    /**
     * Override QuickBook.submit when the Break tab is active.
     * The original submit() handles appointments. We branch here.
     */
    var originalSubmit = window.QuickBook ? window.QuickBook.submit : null;
    if (window.QuickBook && originalSubmit) {
      window.QuickBook.submit = function () {
        if (currentTab !== 'break') {
          return originalSubmit.call(window.QuickBook);
        }
        return submitBreak();
      };
    }

    function submitBreak() {
      var btn = submitBtn;
      btn.disabled = true;
      btn.textContent = 'Saving...';

      var err = document.getElementById('qb-error');
      err.style.display = 'none';

      var dateValue = dateEl.value;
      var timeEl = document.getElementById('qb-time');
      var resourceEl = document.getElementById('qb-resource');
      var labelValue = (labelInput && labelInput.value.trim()) || 'Out for the day';

      var timeValue = timeEl ? timeEl.value : '';
      var resourceValue = resourceEl ? resourceEl.value : '';

      if (!dateValue)     return showBreakError('Pick a date.', btn);
      if (!timeValue)     return showBreakError('Pick a start time.', btn);
      if (!resourceValue) return showBreakError('Pick a resource.', btn);

      var startTime = timeValue.split(':').slice(0, 2).join(':');

      var body = {
        date: dateValue,
        start_time: startTime,
        mode: currentMode,
        resource_id: resourceValue,
        label: labelValue,
      };

      if (currentMode === 'duration') {
        // Use custom input if it has a valid value; otherwise the chip selection.
        var customMin = customInput && parseInt(customInput.value, 10);
        var minutes = (customMin && customMin > 0) ? customMin : currentDuration;
        if (!minutes || minutes < 5) {
          return showBreakError('Pick a duration.', btn);
        }
        body.duration_minutes = minutes;
      }

      fetch('/admin/calendar/breaks', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': window.IntakeAdmin.csrfToken,
        },
        body: JSON.stringify(body),
      })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (resp) {
          if (resp.body && resp.body.success) {
            // Strip ?customer_id= so post-reload doesn't reopen the modal,
            // matching the appointment submit pattern.
            var u = new URL(window.location.href);
            if (u.searchParams.has('customer_id')) {
              u.searchParams.delete('customer_id');
              window.location.href = u.toString();
            } else {
              window.location.reload();
            }
          } else {
            showBreakError(resp.body.message || 'Could not save break.', btn);
          }
        })
        .catch(function () { showBreakError('Network error.', btn); });
    }

    function showBreakError(msg, btn) {
      var err = document.getElementById('qb-error');
      err.textContent = msg;
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Save break';
    }
  });

  /**
   * Patch fetchPicker to also persist business_hours so the Break tab
   * can resolve "rest of day" close times without an extra request.
   */
  if (window.QuickBook && window.QuickBook.fetchPicker) {
    var originalFetch = window.QuickBook.fetchPicker;
    window.QuickBook.fetchPicker = function (search) {
      var url = '/admin/calendar/quick-book?customer_search=' + encodeURIComponent(search || '');
      return fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          window.QuickBook.state.services       = data.services       || [];
          window.QuickBook.state.customers      = data.customers      || [];
          window.QuickBook.state.resources      = data.resources      || [];
          window.QuickBook.state.businessHours  = data.business_hours || null;
          window.QuickBook.renderServices();
          window.QuickBook.renderCustomers();
          if (window.QuickBook.renderResources) window.QuickBook.renderResources();
        });
    };
  }
})();

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    bindAll();
  });

  function bindAll() {
    document.querySelectorAll('.ia-cal-break').forEach(bindBreak);
    document.querySelectorAll('.ia-cal-hold').forEach(bindHold);
  }

  function getCsrf() {
    return (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || '';
  }

  function reloadClean() {
    var u = new URL(window.location.href);
    if (u.searchParams.has('customer_id')) {
      u.searchParams.delete('customer_id');
      window.location.href = u.toString();
    } else {
      window.location.reload();
    }
  }

  function bindBreak(el) {
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      var id        = el.getAttribute('data-break-id');
      var recurring = el.getAttribute('data-break-recurring') === '1';
      var label     = el.getAttribute('data-break-label') || 'this break';

      if (!id) return;

      if (recurring) {
        window.IntakeConfirm.show({
          title:       'Recurring break',
          message:     'This break repeats. To change or remove a recurring break, edit it from Capacity admin.',
          confirmText: 'Got it',
          cancelText:  'Close'
        });
        return;
      }

      window.IntakeConfirm.show({
        title:       'Remove this break?',
        message:     'This will remove "' + label + '" from the calendar. Customers will be able to book this slot again.',
        confirmText: 'Remove break',
        cancelText:  'Keep it',
        danger:      true
      }).then(function (ok) {
        if (!ok) return;
        deleteBreak(id);
      });
    });
  }

  function bindHold(el) {
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      var id        = el.getAttribute('data-hold-id');
      var recurring = el.getAttribute('data-hold-recurring') === '1';

      if (!id) return;

      if (recurring) {
        window.IntakeConfirm.show({
          title:       'Recurring hold',
          message:     'This walk-in hold repeats. To change or remove a recurring hold, edit it from Capacity admin.',
          confirmText: 'Got it',
          cancelText:  'Close'
        });
        return;
      }

      window.IntakeConfirm.show({
        title:       'Remove this walk-in hold?',
        message:     'This will release the held slot. Customers will be able to book it again.',
        confirmText: 'Remove hold',
        cancelText:  'Keep it',
        danger:      true
      }).then(function (ok) {
        if (!ok) return;
        deleteHold(id);
      });
    });
  }

  function deleteBreak(id) {
    fetch('/admin/calendar/breaks/' + encodeURIComponent(id), {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': getCsrf(),
        'Accept': 'application/json',
      },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (resp) {
        if (resp.body && resp.body.success) {
          if (window.IntakeToast) window.IntakeToast.success('Break removed.');
          reloadClean();
        } else {
          var msg = (resp.body && resp.body.message) || 'Could not remove break.';
          if (window.IntakeToast) window.IntakeToast.error(msg);
        }
      })
      .catch(function () {
        if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
      });
  }

  function deleteHold(id) {
    fetch('/admin/calendar/holds/' + encodeURIComponent(id), {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': getCsrf(),
        'Accept': 'application/json',
      },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (resp) {
        if (resp.body && resp.body.success) {
          if (window.IntakeToast) window.IntakeToast.success('Walk-in hold removed.');
          reloadClean();
        } else {
          var msg = (resp.body && resp.body.message) || 'Could not remove hold.';
          if (window.IntakeToast) window.IntakeToast.error(msg);
        }
      })
      .catch(function () {
        if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
      });
  }
})();

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var shell = document.querySelector('.ia-cal-shell');
    if (!shell) return;
    if (shell.getAttribute('data-view-mode') !== 'day') return;

    var openMin   = parseInt(shell.getAttribute('data-cal-open-min')   || '540', 10);
    var pxPerMin  = parseFloat(shell.getAttribute('data-cal-px-per-min') || '1.4');

    var snapMin   = 15;
    var dragThreshold = 5;

    var ghost      = document.getElementById('ia-cal-drag-ghost');
    var ghostName  = document.getElementById('ia-cal-drag-ghost-name');
    var ghostTime  = document.getElementById('ia-cal-drag-ghost-time');
    var timeLabel  = document.getElementById('ia-cal-drag-time-label');

    if (!ghost || !timeLabel) return;

    var state = null;

    function getCsrf() {
      return (window.IntakeAdmin && window.IntakeAdmin.csrfToken) || '';
    }

    function formatTime(min) {
      var h = Math.floor(min / 60);
      var m = min % 60;
      var ampm = h < 12 ? 'AM' : 'PM';
      var h12  = h === 0 ? 12 : (h > 12 ? h - 12 : h);
      return h12 + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function timeToHHMMSS(min) {
      var h = Math.floor(min / 60);
      var m = min % 60;
      return pad(h) + ':' + pad(m) + ':00';
    }

    function timeStrToMin(t) {
      var p = t.split(':');
      return (parseInt(p[0], 10) * 60) + parseInt(p[1], 10);
    }

    function snapToInterval(min) {
      return Math.round(min / snapMin) * snapMin;
    }

    function findColumnAtPoint(x, y) {
      var cols = document.querySelectorAll('.ia-cal-resource-col');
      for (var i = 0; i < cols.length; i++) {
        var r = cols[i].getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
          return cols[i];
        }
      }
      return null;
    }

    function onMouseDown(e) {
      if (e.button !== 0) return;
      var block = e.currentTarget;
      var apptId       = block.getAttribute('data-appt-id');
      var apptTime     = block.getAttribute('data-appt-time');
      var apptDuration = parseInt(block.getAttribute('data-appt-duration') || '0', 10);
      var apptResource = block.getAttribute('data-appt-resource-id');

      if (!apptId || !apptTime) return;

      state = {
        block: block,
        apptId: apptId,
        startTime: apptTime,
        startMin: timeStrToMin(apptTime),
        durationMin: apptDuration,
        startResource: apptResource,
        startX: e.clientX,
        startY: e.clientY,
        dragging: false,
        currentMin: timeStrToMin(apptTime),
        currentResource: apptResource,
        currentColumn: null
      };

      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
      e.preventDefault();
    }

    function onMouseMove(e) {
      if (!state) return;

      var dx = e.clientX - state.startX;
      var dy = e.clientY - state.startY;

      if (!state.dragging) {
        if (Math.abs(dx) < dragThreshold && Math.abs(dy) < dragThreshold) return;
        state.dragging = true;
        state.block.classList.add('ia-cal-appt-dragging');
        ghost.hidden = false;
        timeLabel.hidden = false;
        var nameEl = state.block.querySelector('.ia-cal-appt-name');
        ghostName.textContent = nameEl ? nameEl.textContent : 'Appointment';
        ghost.style.height = (state.durationMin * pxPerMin) + 'px';
      }

      var col = findColumnAtPoint(e.clientX, e.clientY);
      if (col) {
        var colRect = col.getBoundingClientRect();
        var yInCol = e.clientY - colRect.top;
        var minutesFromOpen = yInCol / pxPerMin;
        var rawMin = openMin + minutesFromOpen;
        var snappedMin = snapToInterval(rawMin);

        state.currentMin = snappedMin;
        state.currentResource = col.getAttribute('data-resource-id');
        state.currentColumn = col;

        var topPx = (snappedMin - openMin) * pxPerMin;
        ghost.style.left = colRect.left + 'px';
        ghost.style.top = (colRect.top + topPx) + 'px';
        ghost.style.width = colRect.width + 'px';
        ghost.style.display = 'block';
        ghostTime.textContent = formatTime(snappedMin) + ' – ' + formatTime(snappedMin + state.durationMin);
      } else {
        ghost.style.display = 'none';
      }

      timeLabel.style.left = (e.clientX + 14) + 'px';
      timeLabel.style.top  = (e.clientY + 14) + 'px';
      timeLabel.textContent = col ? formatTime(state.currentMin) : 'Outside grid';
      timeLabel.classList.toggle('is-invalid', !col);
    }

    function onMouseUp(e) {
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);

      if (!state || !state.dragging) {
        state = null;
        return;
      }

      var unchanged = (state.currentMin === state.startMin) &&
                      (state.currentResource === state.startResource);

      cleanupGhost();

      if (unchanged || !state.currentColumn) {
        state.block.classList.remove('ia-cal-appt-dragging');
        if (!state.currentColumn && state.dragging) {
          if (window.IntakeToast) window.IntakeToast.info('Drop outside the grid — kept where it was.');
        }
        state = null;
        return;
      }

      submitReschedule(state, false);
    }

    function cleanupGhost() {
      ghost.hidden = true;
      ghost.style.display = '';
      timeLabel.hidden = true;
      timeLabel.classList.remove('is-invalid');
    }

    function submitReschedule(s, force) {
      var apptId = s.apptId;
      var newTime = timeToHHMMSS(s.currentMin);
      var newResource = s.currentResource;

      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', getCsrf());
      fd.append('op', 'reschedule');
      fd.append('appointment_time', newTime);
      fd.append('resource_id', newResource);
      if (force) fd.append('force', '1');

      fetch('/admin/appointments/' + encodeURIComponent(apptId), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': getCsrf(), 'Accept': 'application/json' },
        body: fd,
        credentials: 'same-origin'
      })
        .then(function (res) {
          return res.json().then(function (body) {
            return { status: res.status, body: body };
          });
        })
        .then(function (r) {
          if (r.status === 200 && r.body.ok) {
            if (window.IntakeToast) {
              window.IntakeToast.success(force ? 'Rescheduled (override).' : 'Rescheduled.');
            }
            window.location.reload();
            return;
          }

          if (r.status === 409 && r.body.conflict) {
            handleConflict(s, r.body);
            return;
          }

          var msg = (r.body && r.body.message) || 'Could not reschedule.';
          if (window.IntakeToast) window.IntakeToast.error(msg);
          state.block.classList.remove('ia-cal-appt-dragging');
          state = null;
        })
        .catch(function () {
          if (window.IntakeToast) window.IntakeToast.error('Network error. Try again.');
          state.block.classList.remove('ia-cal-appt-dragging');
          state = null;
        });
    }

    function handleConflict(s, body) {
      var c = body.conflict || {};
      var oldName = body.old_name || 'current resource';
      var newName = body.new_name || 'new resource';
      var msg;

      if (c.kind === 'appointment') {
        var who = c.customer_name || ('appointment ' + (c.ra_number || ''));
        msg = newName + ' already has ' + who +
              ' booked from ' + c.starts_at + ' to ' + c.ends_at + '. ' +
              'Move anyway? Creates a double-booking.';
      } else if (c.kind === 'break') {
        msg = newName + ' has "' + (c.label || 'a break') +
              '" from ' + c.starts_at + ' to ' + c.ends_at + '. Move anyway?';
      } else if (c.kind === 'hold') {
        msg = newName + ' has a walk-in hold from ' + c.starts_at +
              ' to ' + c.ends_at + '. Move anyway?';
      } else {
        msg = 'That slot is busy. Move anyway?';
      }

      window.IntakeConfirm.show({
        title:       'Slot is busy',
        message:     msg,
        confirmText: 'Move anyway',
        cancelText:  'Snap back',
        danger:      true
      }).then(function (ok) {
        if (!ok) {
          state.block.classList.remove('ia-cal-appt-dragging');
          state = null;
          return;
        }
        submitReschedule(s, true);
      });
    }

    // Bind to all appointment blocks on the day view
    document.querySelectorAll('.ia-cal-appt').forEach(function (block) {
      block.addEventListener('mousedown', onMouseDown);
      block.style.cursor = 'grab';
    });

    // Click suppression: after a drag, the browser fires a click on whatever
    // element is under the cursor at mouseup — which is often the resource
    // column (NOT the appointment block we started on). The empty-cell click
    // handler on .ia-cal-resource-col would then open the new-appointment
    // modal. We catch the click at the document level in capture phase and
    // swallow it if a drag just completed.
    var suppressNextClickUntil = 0;

    document.addEventListener('mouseup', function () {
      if (state && state.dragging) {
        suppressNextClickUntil = Date.now() + 300;
      }
    }, true);

    document.addEventListener('click', function (e) {
      if (Date.now() < suppressNextClickUntil) {
        // Only swallow clicks inside the calendar grid — don't break clicks
        // elsewhere on the page that happen to fire in the suppression window.
        if (e.target && e.target.closest('.ia-cal-grid, .ia-cal-resource-col, .ia-cal-appt')) {
          e.stopPropagation();
          e.preventDefault();
        }
      }
    }, true);
  });
})();
