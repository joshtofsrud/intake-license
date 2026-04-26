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
    state: { date: null, time: null, resourceId: null, customerId: null, services: [], customers: [] },

    open: function (ctx) {
      this.state.date       = ctx.date;
      this.state.time       = ctx.time;
      this.state.resourceId = ctx.resourceId;
      this.state.customerId = null;

      document.getElementById('qb-context').textContent =
        ctx.date + ' at ' + this.formatTime(ctx.time) + ' · ' + ctx.resourceName;
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
          QuickBook.state.services = data.services || [];
          QuickBook.state.customers = data.customers || [];
          QuickBook.renderServices();
          QuickBook.renderCustomers();
        });
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

      var body = {
        date: this.state.date,
        appointment_time: this.state.time + ':00',
        resource_id: this.state.resourceId,
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
          window.location.reload();
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
  // - Single click toggles the chip on/off
  // - Double-click solos that resource (deselects all others)
  // - Solo button (hover icon) does the same as double-click
  // - "All" chip resets to all-on
  // - URL drives state via ?resources=uuid1,uuid2 or ?resources=all
  // ==========================================================================
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

    // Per-resource chips: single click toggles, double click solos
    bar.querySelectorAll('.ia-cal-fchip[data-resource-id]').forEach(function (chip) {
      var clickTimer = null;
      var id = chip.getAttribute('data-resource-id');

      chip.addEventListener('click', function (e) {
        // Differentiate single vs double click via timer.
        // A real dblclick event would also fire — we cancel single behavior on dbl.
        if (clickTimer) return;
        clickTimer = setTimeout(function () {
          clickTimer = null;
          var current = getCurrentSelection();
          var idx = current.indexOf(id);
          if (idx >= 0) {
            current.splice(idx, 1);
          } else {
            current.push(id);
          }
          navigateWithIds(current);
        }, 220);
      });

      chip.addEventListener('dblclick', function (e) {
        // Solo this resource (cancel pending single-click)
        if (clickTimer) { clearTimeout(clickTimer); clickTimer = null; }
        navigate(id);
      });
    });

    // Solo button (bullseye icon) → solo this resource
    bar.querySelectorAll('.ia-cal-fchip-solo').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var id = btn.getAttribute('data-resource-id');
        navigate(id);
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
        row.addEventListener('click', function (e) {
          if (e.target.closest('.ia-cal-sheet-row-solo')) return;
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

    solo: function (id) {
      this.navigate(id);
    },
  };
  window.CalendarFilterSheet = CalendarFilterSheet;

  function boot() {
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

    var firstResource = document.querySelector('[data-resource-id]');
    var resourceId    = firstResource ? firstResource.getAttribute('data-resource-id') : null;
    var resourceName  = '';
    if (firstResource) {
      var nameEl = firstResource.querySelector('.ia-cal-resource-name');
      if (nameEl) resourceName = nameEl.textContent.trim();
    }

    var shell = document.querySelector('.ia-cal-shell');
    var openMin = shell ? parseInt(shell.getAttribute('data-cal-open-min') || '540', 10) : 540;
    var hh = String(Math.floor(openMin / 60)).padStart(2, '0');
    var mm = String(openMin % 60).padStart(2, '0');
    var time = hh + ':' + mm + ':00';
    var dateStr = (new Date()).toISOString().slice(0, 10);

    if (resourceId) {
      window.QuickBook.open({
        date: dateStr,
        time: time,
        resourceId: resourceId,
        resourceName: resourceName || 'Resource'
      });

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
    }
  });
})();
