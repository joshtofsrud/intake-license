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

  function boot() {
    initNowLine();
    initCalendarClicks();
    bindSearch();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  console.log('[calendar] module loaded');
})();
