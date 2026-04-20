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
    selections: {},   // { serviceId: { serviceId, serviceName, priceCents, durationMinutes, addonIds: [] } }
    // Schedule
    date:       null,
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
  var calYear, calMonth, calAvailable = {}, calTimeSlots = {};
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
    bindCalNav();
    bindReceiving();
    initCalendar();
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

  function bindSearch() {
    var input = document.getElementById('bk-search');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase().trim();
      document.querySelectorAll('.bk-service-row').forEach(function (row) {
        var name = (row.getAttribute('data-service-name') || '').toLowerCase();
        row.style.display = (!q || name.includes(q)) ? '' : 'none';
      });
      document.querySelectorAll('.bk-cat-group').forEach(function (group) {
        var visible = Array.from(group.querySelectorAll('.bk-service-row'))
          .some(function (c) { return c.style.display !== 'none'; });
        group.style.display = visible ? '' : 'none';
      });
    });
  }

  function canProceedStep1() {
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
      renderCalendar();
    })
    .catch(function () {
      if (loading) loading.style.display = 'none';
      renderCalendar();
    });
  }

  function renderCalendar() {
    var grid = document.getElementById('cal-grid');
    if (!grid) return;

    // Remove existing day cells
    Array.from(grid.querySelectorAll('.bk-cal-day')).forEach(function (d) { d.remove(); });

    var firstDay  = new Date(calYear, calMonth - 1, 1).getDay(); // 0=Sun
    var daysInMonth = new Date(calYear, calMonth, 0).getDate();
    var todayStr  = fmtDate(today);

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
        (function (ds) {
          cell.addEventListener('click', function () { selectDate(ds); });
        })(dateStr);
      }

      grid.appendChild(cell);
    }
  }

  function selectDate(dateStr) {
    state.date = dateStr;
    state.appointmentTime = null;
    document.querySelectorAll('.bk-cal-day').forEach(function (c) {
      c.classList.toggle('selected', c.textContent == parseInt(dateStr.split('-')[2], 10) && calAvailable[dateStr]);
    });
    renderCalendar();

    // Time slot mode — show time picker
    if (bookingMode === 'time_slots') {
      renderTimeSlots(dateStr);
    }

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
        updateNext2();
      });
      grid.appendChild(btn);
    });

    wrap.appendChild(grid);
    document.getElementById('bk-calendar').after(wrap);
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
    var container = document.getElementById('bk-sidebar-items');
    if (!container) return;
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
          color:       '#111111',
          '::placeholder': { color: '#aaa' },
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
          window.location.href = '/confirm?ra=' + encodeURIComponent(resp.ra_number);
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

  function buildPayload(paymentMethod) {
    collectDetails();
    var items = Object.values(state.selections).map(function (s) {
      return { service_item_id: s.serviceId, addon_ids: s.addonIds.slice() };
    });
    return {
      first_name: state.firstName, last_name: state.lastName,
      email: state.email, phone: state.phone,
      date: state.date, appointment_time: state.appointmentTime || null,
      receiving_method: state.receivingMethod,
      items: items,
      responses: state.responses, response_labels: state.responseLabels,
      payment_method: paymentMethod,
    };
  }

  // =========================================================================
  // Helpers
  // =========================================================================
  function calcTotal() {
    var t = 0;
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
    var parts = ds.split('-');
    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
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
