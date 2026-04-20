/**
 * Tenant Services Admin — Services + Add-ons library manager
 * Backend: ServiceController (services ops) + AddonController (library ops)
 */
(function () {
  'use strict';

  var D    = window.SvData || {};
  var csrf = D.csrf || '';

  var state = {
    tab: 'services',
    view: 'list',
    mode: D.mode || 'drop_off',
    search: '',
    filterCategory: '',
    filterActive: '',
    expanded: null,
    pickerForServiceId: null,
    categories: normalizeCategories(D.categories || []),
    library: normalizeLibrary(D.library || []),
  };

  var persistedView = readPersistedView();
  if (persistedView) state.view = persistedView;

  function normalizeCategories(cats) {
    return cats.map(function (cat) {
      return {
        id: cat.id, name: cat.name, slug: cat.slug,
        is_active: !!cat.is_active, sort_order: cat.sort_order|0,
        services: (cat.services || []).map(normalizeService),
      };
    });
  }

  function normalizeService(s) {
    return {
      id: s.id, category_id: s.category_id,
      name: s.name, slug: s.slug,
      description: s.description || '',
      image_url: s.image_url || null,
      price_cents: s.price_cents|0,
      prep_before_minutes: s.prep_before_minutes|0,
      duration_minutes: s.duration_minutes|0,
      cleanup_after_minutes: s.cleanup_after_minutes|0,
      slot_weight: s.slot_weight|0 || 1,
      is_active: !!s.is_active,
      sort_order: s.sort_order|0,
      addons: (s.addons || []).map(normalizeAttachedAddon),
    };
  }

  function normalizeAttachedAddon(a) {
    return {
      attachment_id: a.attachment_id,
      addon_id: a.addon_id,
      name: a.name || '',
      description: a.description || '',
      override_duration_minutes: a.override_duration_minutes,
      override_price_cents: a.override_price_cents,
      default_duration_minutes: a.default_duration_minutes|0,
      default_price_cents: a.default_price_cents|0,
      effective_duration_minutes: a.effective_duration_minutes|0,
      effective_price_cents: a.effective_price_cents|0,
      sort_order: a.sort_order|0,
    };
  }

  function normalizeLibrary(lib) {
    return lib.map(function (a) {
      return {
        id: a.id, name: a.name, description: a.description || '',
        price_cents: a.price_cents|0,
        default_duration_minutes: a.default_duration_minutes|0,
        is_active: !!a.is_active,
        sort_order: a.sort_order|0,
        usage_count: a.usage_count|0,
      };
    });
  }

  function readPersistedView() {
    try { return localStorage.getItem('sv.view'); } catch (e) { return null; }
  }

  function persistView(v) {
    try { localStorage.setItem('sv.view', v); } catch (e) {}
  }

  function flatServices() {
    var out = [];
    state.categories.forEach(function (cat) {
      cat.services.forEach(function (s) {
        out.push(Object.assign({}, s, { _categoryName: cat.name }));
      });
    });
    return out;
  }

  function findService(serviceId) {
    for (var i = 0; i < state.categories.length; i++) {
      var cat = state.categories[i];
      for (var j = 0; j < cat.services.length; j++) {
        if (cat.services[j].id === serviceId) return cat.services[j];
      }
    }
    return null;
  }

  function findCategoryByServiceId(serviceId) {
    for (var i = 0; i < state.categories.length; i++) {
      var cat = state.categories[i];
      for (var j = 0; j < cat.services.length; j++) {
        if (cat.services[j].id === serviceId) return cat;
      }
    }
    return null;
  }

  function filteredServices() {
    var q = state.search.toLowerCase();
    return flatServices().filter(function (s) {
      if (q && s.name.toLowerCase().indexOf(q) === -1) return false;
      if (state.filterCategory && s.category_id !== state.filterCategory) return false;
      if (state.filterActive === 'true' && !s.is_active) return false;
      if (state.filterActive === 'false' && s.is_active) return false;
      return true;
    });
  }

  function fmtMoney(cents) {
    return (D.currency || '$') + ((cents|0) / 100).toFixed(2);
  }

  function fmtDuration(mins) {
    var m = mins|0;
    if (m === 0) return '—';
    if (m < 60) return m + ' min';
    if (m % 60 === 0) return (m / 60) + ' hr' + ((m/60) > 1 ? 's' : '');
    return Math.floor(m/60) + 'h ' + (m%60) + 'm';
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function serviceWallClock(s) {
    var total = (s.prep_before_minutes|0) + (s.duration_minutes|0) + (s.cleanup_after_minutes|0);
    s.addons.forEach(function (a) { total += a.effective_duration_minutes|0; });
    return total;
  }

  function serviceCustomerTime(s) {
    var total = s.duration_minutes|0;
    s.addons.forEach(function (a) { total += a.effective_duration_minutes|0; });
    return total;
  }

  function addonUsageCount(addonId) {
    var n = 0;
    state.categories.forEach(function (cat) {
      cat.services.forEach(function (s) {
        if (s.addons.some(function (a) { return a.addon_id === addonId; })) n++;
      });
    });
    return n;
  }

  function renderAll() {
    document.getElementById('sv-count-services').textContent = flatServices().length;
    document.getElementById('sv-count-addons').textContent = state.library.length;
    renderFilterCategories();
    renderModeBanner();
    if (state.tab === 'services') {
      if (state.view === 'list') renderList();
      else renderTable();
    } else {
      renderAddonLib();
    }
    updateClearFilters();
  }

  function renderFilterCategories() {
    var sel = document.getElementById('sv-filter-category');
    if (!sel) return;
    var current = state.filterCategory;
    var html = '<option value="">All categories</option>';
    state.categories.forEach(function (cat) {
      html += '<option value="' + esc(cat.id) + '"' + (cat.id === current ? ' selected' : '') + '>' + esc(cat.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  function renderModeBanner() {
    var label = document.getElementById('sv-mode-banner-label');
    if (!label) return;
    if (state.mode === 'time_slots') {
      label.innerHTML = '<b>Time slot mode</b><span class="muted">· showing duration</span>';
    } else {
      label.innerHTML = '<b>Drop-off mode</b><span class="muted">· showing slot weight</span>';
    }
    var head = document.getElementById('sv-list-dur-head');
    if (head) head.textContent = state.mode === 'time_slots' ? 'Total time' : 'Slot weight';
    var tblHead = document.getElementById('sv-tbl-dur-head');
    if (tblHead) tblHead.textContent = state.mode === 'time_slots' ? 'Duration' : 'Slot weight';
  }

  function renderList() {
    var body = document.getElementById('sv-list-body');
    if (!body) return;
    var services = filteredServices();
    if (services.length === 0) {
      body.innerHTML = '<div class="sv-empty">' + (flatServices().length === 0 ? 'No services yet. Click "+ Add service" to create your first one.' : 'No services match your filters.') + '</div>';
      return;
    }

    body.innerHTML = services.map(function (s) {
      var isExpanded = state.expanded === s.id;
      var timeCell;
      if (state.mode === 'time_slots') {
        var wall = serviceWallClock(s);
        var hasBookend = s.prep_before_minutes > 0 || s.cleanup_after_minutes > 0 || s.addons.length > 0;
        if (hasBookend) {
          var breakdown = (s.prep_before_minutes > 0 ? s.prep_before_minutes + ' + ' : '')
            + s.duration_minutes
            + (s.cleanup_after_minutes > 0 ? ' + ' + s.cleanup_after_minutes : '')
            + (s.addons.length > 0 ? ' + ' + s.addons.reduce(function (sum, a) { return sum + (a.effective_duration_minutes|0); }, 0) + ' addons' : '')
            + ' min';
          timeCell = '<div class="sv-time-stack">'
            + '<span class="sv-time-main">' + fmtDuration(wall) + '</span>'
            + '<span class="sv-time-breakdown">' + breakdown + '</span></div>';
        } else {
          timeCell = '<span class="sv-cell-editable" data-field="duration_minutes" data-service="' + s.id + '">' + fmtDuration(s.duration_minutes) + '</span>';
        }
      } else {
        timeCell = '<span class="sv-cell-editable" data-field="slot_weight" data-service="' + s.id + '">' + s.slot_weight + ' slot' + (s.slot_weight !== 1 ? 's' : '') + '</span>';
      }

      return '<div class="sv-list-row' + (!s.is_active ? ' is-inactive' : '') + (isExpanded ? ' is-expanded' : '') + '" data-service="' + s.id + '">'
        + '<div class="sv-drag" title="Drag to reorder">⋮⋮</div>'
        + '<div><span class="sv-cell-editable" data-field="name" data-service="' + s.id + '">' + esc(s.name) + '</span></div>'
        + '<div class="sv-cat">' + esc(s._categoryName) + '</div>'
        + '<div class="sv-num"><span class="sv-cell-editable" data-field="price_cents" data-service="' + s.id + '">' + fmtMoney(s.price_cents) + '</span></div>'
        + '<div class="sv-num">' + timeCell + '</div>'
        + '<div style="text-align:center"><button type="button" class="sv-toggle' + (s.is_active ? ' is-on' : '') + '" data-toggle-service="' + s.id + '"></button></div>'
        + '<div><button type="button" class="sv-expand-btn" data-expand="' + s.id + '"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 6l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>'
        + '</div>'
        + (isExpanded ? renderDrawer(s) : '');
    }).join('');
  }

  function renderDrawer(s) {
    var cat = findCategoryByServiceId(s.id);
    var catOpts = state.categories.map(function (c) {
      return '<option value="' + esc(c.id) + '"' + (cat && c.id === cat.id ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    }).join('');

    var addonRows;
    if (s.addons.length === 0) {
      addonRows = '<div style="font-size:12px;color:var(--ia-text-muted);padding:8px 11px">No add-ons attached yet.</div>';
    } else {
      addonRows = s.addons.map(function (att) {
        var timeOverride = att.override_duration_minutes !== null && att.override_duration_minutes !== undefined;
        var priceOverride = att.override_price_cents !== null && att.override_price_cents !== undefined;
        var anyOverride = timeOverride || priceOverride;

        return '<div class="sv-attached-addon">'
          + '<div>'
            + '<b>' + esc(att.name) + '</b>'
            + (att.description ? '<span class="sv-attached-addon-default">' + esc(att.description) + '</span>' : '')
            + (anyOverride ? '<span class="sv-attached-addon-override">override</span>' : '')
          + '</div>'
          + '<div class="sv-attached-addon-cell">'
            + '<span class="sv-cell-editable" data-addon-service="' + esc(s.id) + '" data-addon-id="' + esc(att.addon_id) + '" data-addon-override="duration">' + (att.effective_duration_minutes|0) + ' min</span>'
            + (timeOverride ? '<span class="sv-attached-addon-cell-default">default ' + (att.default_duration_minutes|0) + ' min</span>' : '')
          + '</div>'
          + '<div class="sv-attached-addon-cell">'
            + '<span class="sv-cell-editable" data-addon-service="' + esc(s.id) + '" data-addon-id="' + esc(att.addon_id) + '" data-addon-override="price">' + fmtMoney(att.effective_price_cents) + '</span>'
            + (priceOverride ? '<span class="sv-attached-addon-cell-default">default ' + fmtMoney(att.default_price_cents) + '</span>' : '')
          + '</div>'
          + '<button type="button" class="sv-attached-addon-remove" data-detach-addon="' + esc(att.addon_id) + '" data-service="' + esc(s.id) + '" title="Remove from this service">×</button>'
          + '</div>';
      }).join('');
    }

    var wall = serviceWallClock(s);
    var customer = serviceCustomerTime(s);
    var timePreviewRows = '';
    if (s.prep_before_minutes > 0) {
      timePreviewRows += '<div class="sv-time-preview-row"><span class="sv-time-preview-label">Prep (staff only)</span><span class="sv-time-preview-value">' + s.prep_before_minutes + ' min</span></div>';
    }
    timePreviewRows += '<div class="sv-time-preview-row"><span class="sv-time-preview-label">Base service</span><span class="sv-time-preview-value">' + s.duration_minutes + ' min</span></div>';
    s.addons.forEach(function (att) {
      timePreviewRows += '<div class="sv-time-preview-row"><span class="sv-time-preview-label">+ ' + esc(att.name) + '</span><span class="sv-time-preview-value">' + (att.effective_duration_minutes|0) + ' min</span></div>';
    });
    if (s.cleanup_after_minutes > 0) {
      timePreviewRows += '<div class="sv-time-preview-row"><span class="sv-time-preview-label">Cleanup (staff only)</span><span class="sv-time-preview-value">' + s.cleanup_after_minutes + ' min</span></div>';
    }
    timePreviewRows += '<div class="sv-time-preview-row total"><span class="sv-time-preview-label">Wall-clock (calendar blocks this)</span><span class="sv-time-preview-value">' + fmtDuration(wall) + '</span></div>';
    timePreviewRows += '<div class="sv-time-preview-row customer"><span class="sv-time-preview-label">Customer sees</span><span class="sv-time-preview-value">' + fmtDuration(customer) + '</span></div>';

    return '<div class="sv-drawer" data-drawer-for="' + esc(s.id) + '">'
      + '<div class="sv-drawer-field">'
        + '<label class="sv-drawer-label">Description</label>'
        + '<textarea class="sv-drawer-textarea" data-drawer-field="description" data-service="' + esc(s.id) + '">' + esc(s.description || '') + '</textarea>'
      + '</div>'
      + '<div class="sv-drawer-field-row">'
        + '<div class="sv-drawer-field">'
          + '<label class="sv-drawer-label">Category</label>'
          + '<select class="sv-drawer-select" data-drawer-field="category_id" data-service="' + esc(s.id) + '">' + catOpts + '</select>'
        + '</div>'
        + '<div class="sv-drawer-field">'
          + '<label class="sv-drawer-label">Price</label>'
          + '<input class="sv-drawer-input" type="number" step="0.01" min="0" data-drawer-field="price_cents" data-drawer-money="1" data-service="' + esc(s.id) + '" value="' + (s.price_cents / 100).toFixed(2) + '">'
        + '</div>'
      + '</div>'
      + '<div class="sv-drawer-field-triple">'
        + '<div class="sv-drawer-field">'
          + '<label class="sv-drawer-label">Prep before (min)</label>'
          + '<input class="sv-drawer-input" type="number" step="5" min="0" max="240" data-drawer-field="prep_before_minutes" data-service="' + esc(s.id) + '" value="' + s.prep_before_minutes + '">'
          + '<div class="sv-time-hint">Buffer for setup. Not shown to customer.</div>'
        + '</div>'
        + '<div class="sv-drawer-field">'
          + '<label class="sv-drawer-label">Service duration (min)</label>'
          + '<input class="sv-drawer-input" type="number" step="5" min="1" max="480" data-drawer-field="duration_minutes" data-service="' + esc(s.id) + '" value="' + s.duration_minutes + '">'
          + '<div class="sv-time-hint">Customer-facing base time.</div>'
        + '</div>'
        + '<div class="sv-drawer-field">'
          + '<label class="sv-drawer-label">Cleanup after (min)</label>'
          + '<input class="sv-drawer-input" type="number" step="5" min="0" max="240" data-drawer-field="cleanup_after_minutes" data-service="' + esc(s.id) + '" value="' + s.cleanup_after_minutes + '">'
          + '<div class="sv-time-hint">Buffer for cleanup. Not shown to customer.</div>'
        + '</div>'
      + '</div>'
      + '<div class="sv-drawer-field">'
        + '<label class="sv-drawer-label">Add-ons attached <span style="color:var(--ia-text-dim);margin-left:6px;text-transform:none;letter-spacing:normal">(' + s.addons.length + ')</span></label>'
        + '<div class="sv-attached-addons">' + addonRows + '</div>'
        + '<button type="button" class="sv-attach-btn" data-attach-addon="' + esc(s.id) + '">+ Attach from library…</button>'
      + '</div>'
      + '<div class="sv-drawer-field">'
        + '<label class="sv-drawer-label">Time summary</label>'
        + '<div class="sv-time-preview">' + timePreviewRows + '</div>'
      + '</div>'
      + '<div class="sv-drawer-actions">'
        + '<button type="button" class="ia-btn ia-btn--sm" style="color:var(--ia-red,#EF4444);border-color:rgba(239,68,68,.3)" data-delete-service="' + esc(s.id) + '">Delete service</button>'
        + '<div class="sv-drawer-actions-right">'
          + '<button type="button" class="ia-btn ia-btn--sm" data-drawer-close="' + esc(s.id) + '">Close</button>'
          + '<button type="button" class="ia-btn ia-btn--sm" data-duplicate-service="' + esc(s.id) + '">Duplicate</button>'
        + '</div>'
      + '</div>'
      + '</div>';
  }

  // ====================================================================
  // H4: Drawer field bindings (blur-to-save for inputs, change for selects/textarea)
  // ====================================================================

  function bindDrawerFields() {
    document.querySelectorAll('[data-drawer-field]').forEach(function (el) {
      if (el.__svBound) return;
      el.__svBound = true;
      var evt = (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') ? 'change' : 'blur';
      el.addEventListener(evt, function () {
        var field = el.getAttribute('data-drawer-field');
        var serviceId = el.getAttribute('data-service');
        var isMoney = el.getAttribute('data-drawer-money') === '1';
        var value = el.value;
        if (isMoney) {
          var parsed = parseFloat(value);
          if (isNaN(parsed)) return;
          value = Math.round(parsed * 100);
        } else if (el.type === 'number') {
          var n = parseInt(value, 10);
          if (isNaN(n)) return;
          value = n;
        }
        ajax(D.urls.servicesBase + '/' + encodeURIComponent(serviceId), 'PATCH', {
          op: 'update_field', field: field, value: value,
        }).then(function (r) {
          if (!serviceResponseOk(r)) {
            el.style.borderColor = 'var(--ia-red,#EF4444)';
            setTimeout(function () { el.style.borderColor = ''; }, 800);
            console.error('drawer update_field failed:', serviceErrorMessage(r));
            return;
          }
          var s = findService(serviceId);
          if (s) s[field] = r.json.data.value;
          el.style.borderColor = 'var(--ia-accent)';
          setTimeout(function () { el.style.borderColor = ''; }, 650);
          if (field === 'category_id') {
            moveServiceToCategory(serviceId, r.json.data.value);
          }
          renderAll();
        });
      });
    });
  }

  function moveServiceToCategory(serviceId, newCategoryId) {
    var s = findService(serviceId);
    if (!s) return;
    var oldCat = findCategoryByServiceId(serviceId);
    var newCat = state.categories.find(function (c) { return c.id === newCategoryId; });
    if (!oldCat || !newCat || oldCat.id === newCat.id) return;
    oldCat.services = oldCat.services.filter(function (x) { return x.id !== serviceId; });
    s.category_id = newCategoryId;
    newCat.services.push(s);
  }

  // ====================================================================
  // H4: Attach / detach / duplicate / delete / close
  // ====================================================================

  function bindDrawerActions() {
    document.addEventListener('click', function (e) {
      var attach = e.target.closest('[data-attach-addon]');
      if (attach && !attach.__svHandled) {
        attach.__svHandled = true;
        setTimeout(function () { attach.__svHandled = false; }, 50);
        openAddonPicker(attach.getAttribute('data-attach-addon'));
        return;
      }
      var detach = e.target.closest('[data-detach-addon]');
      if (detach && !detach.__svHandled) {
        detach.__svHandled = true;
        setTimeout(function () { detach.__svHandled = false; }, 50);
        var serviceId = detach.getAttribute('data-service');
        var addonId = detach.getAttribute('data-detach-addon');
        ajax(D.urls.servicesBase + '/' + encodeURIComponent(serviceId), 'PATCH', {
          op: 'detach_addon', addon_id: addonId,
        }).then(function (r) {
          if (!serviceResponseOk(r)) { alert('Detach failed: ' + serviceErrorMessage(r)); return; }
          var s = findService(serviceId);
          if (s) s.addons = s.addons.filter(function (a) { return a.addon_id !== addonId; });
          renderAll();
        });
        return;
      }
      var dup = e.target.closest('[data-duplicate-service]');
      if (dup && !dup.__svHandled) {
        dup.__svHandled = true;
        setTimeout(function () { dup.__svHandled = false; }, 50);
        var sid = dup.getAttribute('data-duplicate-service');
        ajax(D.urls.servicesBase + '/' + encodeURIComponent(sid), 'PATCH', {
          op: 'duplicate_service',
        }).then(function (r) {
          if (!serviceResponseOk(r)) { alert('Duplicate failed: ' + serviceErrorMessage(r)); return; }
          window.location.reload();
        });
        return;
      }
      var del = e.target.closest('[data-delete-service]');
      if (del && !del.__svHandled) {
        del.__svHandled = true;
        setTimeout(function () { del.__svHandled = false; }, 50);
        if (!confirm('Delete this service? This cannot be undone.')) return;
        var sid2 = del.getAttribute('data-delete-service');
        ajax(D.urls.servicesBase + '/' + encodeURIComponent(sid2), 'DELETE', { op: 'delete_service' }).then(function (r) {
          if (!serviceResponseOk(r)) { alert('Delete failed: ' + serviceErrorMessage(r)); return; }
          var cat = findCategoryByServiceId(sid2);
          if (cat) cat.services = cat.services.filter(function (x) { return x.id !== sid2; });
          state.expanded = null;
          renderAll();
        });
        return;
      }
      var close = e.target.closest('[data-drawer-close]');
      if (close) {
        state.expanded = null;
        renderList();
        return;
      }
      var modalClose = e.target.closest('[data-modal-close]');
      if (modalClose) { closeAddonPicker(); return; }
      if (e.target.classList && e.target.classList.contains('sv-modal-overlay')) {
        closeAddonPicker();
      }
    });
  }

  // ====================================================================
  // H4: Addon picker modal
  // ====================================================================

  function openAddonPicker(serviceId) {
    state.pickerForServiceId = serviceId;
    var modal = document.getElementById('sv-addon-picker-modal');
    if (!modal) return;
    modal.classList.add('is-visible');
    var search = document.getElementById('sv-picker-search');
    if (search) { search.value = ''; setTimeout(function () { search.focus(); }, 50); }
    renderAddonPicker();
  }

  function closeAddonPicker() {
    var modal = document.getElementById('sv-addon-picker-modal');
    if (modal) modal.classList.remove('is-visible');
    state.pickerForServiceId = null;
  }

  function renderAddonPicker() {
    var body = document.getElementById('sv-picker-body');
    if (!body) return;
    var service = findService(state.pickerForServiceId);
    if (!service) return;
    var search = document.getElementById('sv-picker-search');
    var q = (search && search.value || '').toLowerCase();
    var attachedIds = new Set(service.addons.map(function (a) { return a.addon_id; }));
    var candidates = state.library.filter(function (a) {
      if (!a.is_active) return false;
      if (q && a.name.toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    if (candidates.length === 0) {
      body.innerHTML = '<div style="padding:30px 20px;text-align:center;color:var(--ia-text-muted);font-size:13px">No add-ons found. Create a new one below.</div>';
      return;
    }
    body.innerHTML = candidates.map(function (a) {
      var isAttached = attachedIds.has(a.id);
      return '<div class="sv-addon-lib-item' + (isAttached ? ' is-attached' : '') + '" data-addon-pick="' + esc(a.id) + '">'
        + '<div>'
          + '<div><b>' + esc(a.name) + '</b>' + (isAttached ? '<span class="sv-addon-lib-attached-badge">attached</span>' : '') + '</div>'
          + (a.description ? '<div class="sv-addon-lib-desc">' + esc(a.description) + '</div>' : '')
        + '</div>'
        + '<div class="sv-addon-lib-time">' + (a.default_duration_minutes|0) + ' min</div>'
        + '<div class="sv-addon-lib-price">' + fmtMoney(a.price_cents) + '</div>'
        + '</div>';
    }).join('');
  }

  function bindAddonPicker() {
    var search = document.getElementById('sv-picker-search');
    if (search && !search.__svBound) {
      search.__svBound = true;
      search.addEventListener('input', renderAddonPicker);
    }
    var createBtn = document.getElementById('sv-picker-create');
    if (createBtn && !createBtn.__svBound) {
      createBtn.__svBound = true;
      createBtn.addEventListener('click', function () {
        var targetServiceId = state.pickerForServiceId;
        closeAddonPicker();
        var name = prompt('New add-on name:');
        if (!name || !name.trim()) return;
        ajax(D.urls.addonsBase, 'POST', {
          op: 'save_addon', name: name.trim(),
          price_cents: 0, default_duration_minutes: 0,
        }).then(function (r) {
          if (!(r.ok && r.json && r.json.success)) { alert('Add-on create failed'); return; }
          var a = r.json.addon;
          state.library.push({
            id: a.id, name: a.name, description: a.description || '',
            price_cents: a.price_cents|0, default_duration_minutes: a.default_duration_minutes|0,
            is_active: !!a.is_active, sort_order: a.sort_order|0, usage_count: 0,
          });
          if (targetServiceId) attachAddonToService(targetServiceId, a.id);
          else renderAll();
        });
      });
    }
    document.addEventListener('click', function (e) {
      var pick = e.target.closest('[data-addon-pick]');
      if (!pick || pick.classList.contains('is-attached')) return;
      if (pick.__svHandled) return;
      pick.__svHandled = true;
      setTimeout(function () { pick.__svHandled = false; }, 50);
      var serviceId = state.pickerForServiceId;
      var addonId = pick.getAttribute('data-addon-pick');
      attachAddonToService(serviceId, addonId);
    });
  }

  function attachAddonToService(serviceId, addonId) {
    ajax(D.urls.servicesBase + '/' + encodeURIComponent(serviceId), 'PATCH', {
      op: 'attach_addon', addon_id: addonId,
    }).then(function (r) {
      if (!serviceResponseOk(r)) { alert('Attach failed: ' + serviceErrorMessage(r)); return; }
      var s = findService(serviceId);
      if (s) s.addons.push(normalizeAttachedAddon(r.json.data));
      closeAddonPicker();
      renderAll();
    });
  }

  function renderTable() {
    var body = document.getElementById('sv-tbl-body');
    if (!body) return;
    var services = filteredServices();
    if (services.length === 0) {
      body.innerHTML = '<tr><td colspan="9" class="sv-empty">' + (flatServices().length === 0 ? 'No services yet. Click &ldquo;+ Add service&rdquo; to create your first one.' : 'No services match your filters.') + '</td></tr>';
      return;
    }

    body.innerHTML = services.map(function (s) {
      var durCell;
      if (state.mode === 'time_slots') {
        durCell = '<span class="sv-cell-editable" data-field="duration_minutes" data-service="' + esc(s.id) + '">' + s.duration_minutes + ' min</span>';
      } else {
        durCell = '<span class="sv-cell-editable" data-field="slot_weight" data-service="' + esc(s.id) + '">' + s.slot_weight + ' slot' + (s.slot_weight !== 1 ? 's' : '') + '</span>';
      }
      var addonBadge = s.addons.length
        ? '<span class="sv-addons-count has-items">' + s.addons.length + ' attached</span>'
        : '<span class="sv-addons-count">none</span>';

      return '<tr class="' + (!s.is_active ? 'is-inactive' : '') + '" data-service="' + esc(s.id) + '">'
        + '<td><span class="sv-cell-editable" data-field="name" data-service="' + esc(s.id) + '">' + esc(s.name) + '</span></td>'
        + '<td class="sv-cat">' + esc(s._categoryName) + '</td>'
        + '<td class="num"><span class="sv-cell-editable" data-field="price_cents" data-service="' + esc(s.id) + '">' + fmtMoney(s.price_cents) + '</span></td>'
        + '<td class="num"><span class="sv-cell-editable" data-field="prep_before_minutes" data-service="' + esc(s.id) + '">' + (s.prep_before_minutes ? s.prep_before_minutes + ' min' : '—') + '</span></td>'
        + '<td class="num">' + durCell + '</td>'
        + '<td class="num"><span class="sv-cell-editable" data-field="cleanup_after_minutes" data-service="' + esc(s.id) + '">' + (s.cleanup_after_minutes ? s.cleanup_after_minutes + ' min' : '—') + '</span></td>'
        + '<td class="ctr">' + addonBadge + '</td>'
        + '<td class="ctr"><button type="button" class="sv-toggle' + (s.is_active ? ' is-on' : '') + '" data-toggle-service="' + esc(s.id) + '"></button></td>'
        + '<td><button type="button" class="sv-tbl-row-menu" data-tbl-expand="' + esc(s.id) + '" title="Open details">⋮</button></td>'
        + '</tr>';
    }).join('');
  }

  function renderAddonLib() {
    var body = document.getElementById('sv-addon-lib-body');
    if (!body) return;
    var q = '';
    var search = document.getElementById('sv-addon-search');
    if (search) q = search.value.toLowerCase();

    var filtered = state.library.filter(function (a) {
      if (!q) return true;
      if (a.name.toLowerCase().indexOf(q) !== -1) return true;
      if (a.description && a.description.toLowerCase().indexOf(q) !== -1) return true;
      return false;
    });

    if (filtered.length === 0) {
      body.innerHTML = '<div class="sv-empty">' + (state.library.length === 0 ? 'No add-ons yet. Click &ldquo;+ Add add-on&rdquo; to create one.' : 'No add-ons match your search.') + '</div>';
      return;
    }

    body.innerHTML = filtered.map(function (a) {
      var usage = addonUsageCount(a.id);
      var usageCell = usage > 0
        ? '<b>' + usage + '</b> service' + (usage !== 1 ? 's' : '')
        : '<span style="opacity:.5">Unused</span>';

      return '<div class="sv-addon-row' + (!a.is_active ? ' is-inactive' : '') + '" data-addon="' + esc(a.id) + '">'
        + '<div><span class="sv-cell-editable" data-addonlib="' + esc(a.id) + '" data-field="name"><b>' + esc(a.name) + '</b></span></div>'
        + '<div class="sv-addon-row-desc"><span class="sv-cell-editable" data-addonlib="' + esc(a.id) + '" data-field="description">' + (a.description ? esc(a.description) : '<span style="opacity:.4">—</span>') + '</span></div>'
        + '<div class="sv-addon-row-time"><span class="sv-cell-editable" data-addonlib="' + esc(a.id) + '" data-field="default_duration_minutes">' + (a.default_duration_minutes|0) + ' min</span></div>'
        + '<div class="sv-addon-row-price"><span class="sv-cell-editable" data-addonlib="' + esc(a.id) + '" data-field="price_cents">' + fmtMoney(a.price_cents) + '</span></div>'
        + '<div class="sv-addon-row-usage">' + usageCell + '</div>'
        + '<div style="text-align:center"><button type="button" class="sv-toggle' + (a.is_active ? ' is-on' : '') + '" data-toggle-addon="' + esc(a.id) + '"></button></div>'
        + '<div><button type="button" class="sv-expand-btn" data-delete-addon="' + esc(a.id) + '" data-addon-name="' + esc(a.name) + '" data-addon-usage="' + usage + '" title="Delete add-on">×</button></div>'
        + '</div>';
    }).join('');
  }

  // ====================================================================
  // H6: Addon library event bindings
  // ====================================================================

  function bindAddonLibEvents() {
    var search = document.getElementById('sv-addon-search');
    if (search && !search.__svBound) {
      search.__svBound = true;
      search.addEventListener('input', renderAddonLib);
    }
    document.querySelectorAll('[data-toggle-addon]').forEach(function (btn) {
      if (btn.__svBound) return;
      btn.__svBound = true;
      btn.addEventListener('click', function () {
        var aid = btn.getAttribute('data-toggle-addon');
        var a = findLibraryAddon(aid);
        if (!a) return;
        var newVal = !a.is_active;
        btn.classList.toggle('is-on', newVal);
        ajax(D.urls.addonsBase + '/' + encodeURIComponent(aid), 'PATCH', {
          op: 'update_field', field: 'is_active', value: newVal ? 1 : 0,
        }).then(function (r) {
          if (!(r.ok && r.json && r.json.success)) {
            btn.classList.toggle('is-on', a.is_active);
            console.error('addon toggle failed');
            return;
          }
          a.is_active = !!r.json.value;
        });
      });
    });
    document.querySelectorAll('[data-delete-addon]').forEach(function (btn) {
      if (btn.__svBound) return;
      btn.__svBound = true;
      btn.addEventListener('click', function () {
        var aid = btn.getAttribute('data-delete-addon');
        var name = btn.getAttribute('data-addon-name') || 'this add-on';
        var usage = parseInt(btn.getAttribute('data-addon-usage'), 10) || 0;
        var msg = usage > 0
          ? 'Delete "' + name + '"? It is attached to ' + usage + ' service' + (usage !== 1 ? 's' : '') + ', and will be removed from ' + (usage !== 1 ? 'them' : 'it') + '. This cannot be undone.'
          : 'Delete "' + name + '"? This cannot be undone.';
        if (!confirm(msg)) return;
        ajax(D.urls.addonsBase + '/' + encodeURIComponent(aid), 'DELETE', null).then(function (r) {
          if (!(r.ok && r.json && r.json.success)) { alert('Delete failed'); return; }
          state.library = state.library.filter(function (x) { return x.id !== aid; });
          state.categories.forEach(function (cat) {
            cat.services.forEach(function (s) {
              s.addons = s.addons.filter(function (att) { return att.addon_id !== aid; });
            });
          });
          renderAll();
        });
      });
    });
  }

  function updateClearFilters() {
    var count = [state.search, state.filterCategory, state.filterActive].filter(Boolean).length;
    var btn = document.getElementById('sv-clear-filters');
    if (btn) btn.style.display = count > 0 ? '' : 'none';
  }

  function bindEvents() {
    var subnav = document.getElementById('sv-subnav');
    if (subnav) subnav.addEventListener('click', function (e) {
      var tab = e.target.closest('[data-tab]');
      if (!tab) return;
      state.tab = tab.getAttribute('data-tab');
      document.querySelectorAll('.sv-subnav-tab').forEach(function (t) {
        t.classList.toggle('is-active', t.getAttribute('data-tab') === state.tab);
      });
      document.getElementById('sv-tab-services').style.display = state.tab === 'services' ? '' : 'none';
      document.getElementById('sv-tab-addons').style.display = state.tab === 'addons' ? '' : 'none';
      document.getElementById('sv-view-toggle').style.display = state.tab === 'services' ? '' : 'none';
      document.getElementById('sv-add-btn').textContent = state.tab === 'addons' ? '+ Add add-on' : '+ Add service';
      renderAll();
    });

    var viewToggle = document.getElementById('sv-view-toggle');
    if (viewToggle) viewToggle.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-view]');
      if (!btn) return;
      state.view = btn.getAttribute('data-view');
      persistView(state.view);
      document.querySelectorAll('.sv-view-toggle-btn').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-view') === state.view);
      });
      document.querySelectorAll('.sv-view').forEach(function (v) { v.classList.remove('is-active'); });
      document.getElementById(state.view === 'list' ? 'sv-view-list' : 'sv-view-table').classList.add('is-active');
      renderAll();
    });
    if (state.view === 'table') {
      document.querySelectorAll('.sv-view-toggle-btn').forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-view') === state.view);
      });
      document.querySelectorAll('.sv-view').forEach(function (v) { v.classList.remove('is-active'); });
      var tv = document.getElementById('sv-view-table');
      if (tv) tv.classList.add('is-active');
    }

    var search = document.getElementById('sv-search');
    if (search) search.addEventListener('input', function () { state.search = search.value; renderAll(); });

    var filterCat = document.getElementById('sv-filter-category');
    if (filterCat) filterCat.addEventListener('change', function () { state.filterCategory = filterCat.value; renderAll(); });

    var filterAct = document.getElementById('sv-filter-active');
    if (filterAct) filterAct.addEventListener('change', function () { state.filterActive = filterAct.value; renderAll(); });

    var clearBtn = document.getElementById('sv-clear-filters');
    if (clearBtn) clearBtn.addEventListener('click', function () {
      state.search = ''; state.filterCategory = ''; state.filterActive = '';
      if (search) search.value = '';
      if (filterCat) filterCat.value = '';
      if (filterAct) filterAct.value = '';
      renderAll();
    });

    document.addEventListener('click', function (e) {
      var ex = e.target.closest('[data-expand]');
      if (ex) {
        var sid = ex.getAttribute('data-expand');
        state.expanded = state.expanded === sid ? null : sid;
        renderList();
        return;
      }
      var tblEx = e.target.closest('[data-tbl-expand]');
      if (tblEx) {
        var sid2 = tblEx.getAttribute('data-tbl-expand');
        state.view = 'list';
        state.expanded = sid2;
        persistView('list');
        document.querySelectorAll('.sv-view-toggle-btn').forEach(function (b) {
          b.classList.toggle('is-active', b.getAttribute('data-view') === 'list');
        });
        document.querySelectorAll('.sv-view').forEach(function (v) { v.classList.remove('is-active'); });
        var lv = document.getElementById('sv-view-list');
        if (lv) lv.classList.add('is-active');
        renderAll();
        setTimeout(function () {
          var row = document.querySelector('.sv-list-row[data-service="' + sid2 + '"]');
          if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 50);
        return;
      }
    });
  }

  // ====================================================================
  // H3: Inline editing + field saves
  // ====================================================================

  function ajax(url, method, body) {
    return fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body ? JSON.stringify(body) : null,
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json().then(function (json) {
        return { ok: r.ok, status: r.status, json: json };
      }).catch(function () {
        return { ok: false, status: r.status, json: null };
      });
    });
  }

  function serviceResponseOk(r) {
    // ServiceController returns {ok: true, data: ...} or {ok: false, error: ...}
    return r.ok && r.json && r.json.ok === true;
  }

  function serviceErrorMessage(r) {
    if (r.json && r.json.error) return r.json.error;
    return 'Request failed (' + (r.status || 'unknown') + ')';
  }

  function flash(cell, kind) {
    if (!cell) return;
    var cls = kind === 'saved' ? 'just-saved' : 'just-errored';
    cell.classList.add(cls);
    setTimeout(function () { cell.classList.remove(cls); }, 700);
  }

  function bindInlineEdits() {
    document.querySelectorAll('.sv-cell-editable').forEach(function (cell) {
      if (cell.__svBound) return;
      cell.__svBound = true;
      cell.addEventListener('click', onCellClick);
    });
  }

  function onCellClick(e) {
    var cell = e.currentTarget;
    if (cell.classList.contains('is-editing')) return;

    var field = cell.getAttribute('data-field');
    var serviceId = cell.getAttribute('data-service');
    var addonLibId = cell.getAttribute('data-addonlib');
    var addonAttachmentServiceId = cell.getAttribute('data-addon-service');
    var addonAttachmentAddonId = cell.getAttribute('data-addon-id');
    var addonOverrideField = cell.getAttribute('data-addon-override');

    var currentValue, isNumber, isMoney, min, max, step;

    if (serviceId) {
      var s = findService(serviceId);
      if (!s) return;
      isNumber = true;
      if (field === 'name') { isNumber = false; currentValue = s.name; }
      else if (field === 'description') { isNumber = false; currentValue = s.description; }
      else if (field === 'price_cents') { currentValue = (s.price_cents / 100).toFixed(2); isMoney = true; min = 0; step = 0.01; }
      else if (field === 'prep_before_minutes') { currentValue = s.prep_before_minutes; min = 0; max = 240; step = 5; }
      else if (field === 'duration_minutes') { currentValue = s.duration_minutes; min = 1; max = 480; step = 5; }
      else if (field === 'cleanup_after_minutes') { currentValue = s.cleanup_after_minutes; min = 0; max = 240; step = 5; }
      else if (field === 'slot_weight') { currentValue = s.slot_weight; min = 1; max = 4; step = 1; }
      else return;
    } else if (addonLibId) {
      var a = findLibraryAddon(addonLibId);
      if (!a) return;
      if (field === 'name') { isNumber = false; currentValue = a.name; }
      else if (field === 'description') { isNumber = false; currentValue = a.description; }
      else if (field === 'price_cents') { isNumber = true; isMoney = true; currentValue = (a.price_cents / 100).toFixed(2); min = 0; step = 0.01; }
      else if (field === 'default_duration_minutes') { isNumber = true; currentValue = a.default_duration_minutes; min = 0; max = 240; step = 5; }
      else return;
    } else if (addonAttachmentServiceId) {
      var svc = findService(addonAttachmentServiceId);
      if (!svc) return;
      var att = svc.addons.find(function (x) { return x.addon_id === addonAttachmentAddonId; });
      if (!att) return;
      isNumber = true;
      if (addonOverrideField === 'duration') {
        currentValue = att.effective_duration_minutes; min = 0; max = 240; step = 5;
      } else if (addonOverrideField === 'price') {
        currentValue = (att.effective_price_cents / 100).toFixed(2); isMoney = true; min = 0; step = 0.01;
      } else return;
    } else {
      return;
    }

    openInlineEditor(cell, {
      currentValue: currentValue,
      isNumber: isNumber,
      isMoney: isMoney,
      min: min, max: max, step: step,
      commit: function (newValueRaw) {
        commitCellEdit(cell, {
          serviceId: serviceId, addonLibId: addonLibId,
          addonAttachmentServiceId: addonAttachmentServiceId,
          addonAttachmentAddonId: addonAttachmentAddonId,
          addonOverrideField: addonOverrideField,
          field: field, newValueRaw: newValueRaw,
          isNumber: isNumber, isMoney: isMoney,
        });
      },
    });
  }

  function openInlineEditor(cell, opts) {
    cell.classList.add('is-editing');
    var originalText = cell.textContent;
    var input = document.createElement('input');
    input.className = 'sv-cell-input';
    input.type = opts.isNumber ? 'number' : 'text';
    if (opts.min !== undefined) input.min = opts.min;
    if (opts.max !== undefined) input.max = opts.max;
    if (opts.step !== undefined) input.step = opts.step;
    input.value = opts.currentValue == null ? '' : opts.currentValue;
    cell.textContent = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    var committed = false;
    function finish(commit) {
      if (committed) return;
      committed = true;
      cell.classList.remove('is-editing');
      if (commit) {
        opts.commit(input.value);
      } else {
        cell.textContent = originalText;
      }
    }

    input.addEventListener('blur', function () { finish(true); });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
      if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
    });
  }

  function commitCellEdit(cell, ctx) {
    var value = ctx.newValueRaw;
    if (ctx.isMoney) {
      var parsed = parseFloat(value);
      if (isNaN(parsed)) { flash(cell, 'errored'); renderAll(); return; }
      value = Math.round(parsed * 100);
    } else if (ctx.isNumber) {
      var n = parseInt(value, 10);
      if (isNaN(n)) { flash(cell, 'errored'); renderAll(); return; }
      value = n;
    } else {
      value = String(value);
      if (ctx.field === 'name' && !value.trim()) { flash(cell, 'errored'); renderAll(); return; }
    }

    if (ctx.serviceId) {
      var url = D.urls.servicesBase + '/' + encodeURIComponent(ctx.serviceId);
      ajax(url, 'PATCH', { op: 'update_field', field: ctx.field, value: value }).then(function (r) {
        if (!serviceResponseOk(r)) { flash(cell, 'errored'); console.error('update_field failed:', serviceErrorMessage(r)); renderAll(); return; }
        var s = findService(ctx.serviceId);
        if (s) s[ctx.field] = r.json.data.value;
        flash(cell, 'saved');
        renderAll();
      });
      return;
    }

    if (ctx.addonLibId) {
      var url2 = D.urls.addonsBase + '/' + encodeURIComponent(ctx.addonLibId);
      ajax(url2, 'PATCH', { op: 'update_field', field: ctx.field, value: value }).then(function (r) {
        // AddonController uses {success: true, ...}
        if (!(r.ok && r.json && r.json.success)) { flash(cell, 'errored'); console.error('addon update_field failed'); renderAll(); return; }
        var a = findLibraryAddon(ctx.addonLibId);
        if (a) a[ctx.field] = r.json.value;
        flash(cell, 'saved');
        renderAll();
      });
      return;
    }

    if (ctx.addonAttachmentServiceId) {
      var url3 = D.urls.servicesBase + '/' + encodeURIComponent(ctx.addonAttachmentServiceId);
      ajax(url3, 'PATCH', {
        op: 'update_addon_override',
        addon_id: ctx.addonAttachmentAddonId,
        field: ctx.addonOverrideField,
        value: value,
      }).then(function (r) {
        if (!serviceResponseOk(r)) { flash(cell, 'errored'); console.error('update_addon_override failed:', serviceErrorMessage(r)); renderAll(); return; }
        mergeUpdatedAttachment(ctx.addonAttachmentServiceId, r.json.data);
        flash(cell, 'saved');
        renderAll();
      });
    }
  }

  function findLibraryAddon(addonId) {
    for (var i = 0; i < state.library.length; i++) {
      if (state.library[i].id === addonId) return state.library[i];
    }
    return null;
  }

  function mergeUpdatedAttachment(serviceId, data) {
    var s = findService(serviceId);
    if (!s || !data) return;
    var normalized = normalizeAttachedAddon(data);
    var idx = s.addons.findIndex(function (a) { return a.addon_id === normalized.addon_id; });
    if (idx === -1) s.addons.push(normalized);
    else s.addons[idx] = normalized;
  }

  // ====================================================================
  // H3: service active toggle + Add service button
  // ====================================================================

  function bindActiveToggles() {
    document.querySelectorAll('[data-toggle-service]').forEach(function (btn) {
      if (btn.__svBound) return;
      btn.__svBound = true;
      btn.addEventListener('click', function () {
        var sid = btn.getAttribute('data-toggle-service');
        var s = findService(sid);
        if (!s) return;
        var newVal = !s.is_active;
        btn.classList.toggle('is-on', newVal);
        ajax(D.urls.servicesBase + '/' + encodeURIComponent(sid), 'PATCH', {
          op: 'update_field', field: 'is_active', value: newVal ? 1 : 0,
        }).then(function (r) {
          if (!serviceResponseOk(r)) {
            btn.classList.toggle('is-on', s.is_active);
            console.error('toggle failed:', serviceErrorMessage(r));
            return;
          }
          s.is_active = !!r.json.data.value;
          renderAll();
        });
      });
    });
  }

  function bindAddButton() {
    var btn = document.getElementById('sv-add-btn');
    if (!btn || btn.__svBound) return;
    btn.__svBound = true;
    btn.addEventListener('click', function () {
      if (state.tab === 'services') createService();
      else createLibraryAddon();
    });
  }

  function createService() {
    var name = prompt('New service name:');
    if (!name || !name.trim()) return;
    var categoryId = state.categories.length ? state.categories[0].id : null;
    if (!categoryId) {
      var catName = prompt('You have no categories yet. Enter a category name to create:');
      if (!catName || !catName.trim()) return;
      ajax(D.urls.servicesBase, 'POST', { op: 'save_category', name: catName.trim() }).then(function (r) {
        if (!serviceResponseOk(r)) { alert('Category save failed: ' + serviceErrorMessage(r)); return; }
        state.categories.push({
          id: r.json.data.id, name: r.json.data.name, slug: r.json.data.slug,
          is_active: true, sort_order: r.json.data.sort_order || 0, services: [],
        });
        actuallyCreateService(name.trim(), r.json.data.id);
      });
      return;
    }
    actuallyCreateService(name.trim(), categoryId);
  }

  function actuallyCreateService(name, categoryId) {
    ajax(D.urls.servicesBase, 'POST', {
      op: 'save_service',
      name: name, category_id: categoryId,
      price_cents: 0, prep_before_minutes: 0,
      duration_minutes: 30, cleanup_after_minutes: 0, slot_weight: 1,
    }).then(function (r) {
      if (!serviceResponseOk(r)) { alert('Service save failed: ' + serviceErrorMessage(r)); return; }
      var newService = normalizeService(Object.assign({ addons: [] }, r.json.data));
      var cat = state.categories.find(function (c) { return c.id === categoryId; });
      if (cat) cat.services.push(newService);
      state.expanded = newService.id;
      renderAll();
    });
  }

  function createLibraryAddon() {
    var name = prompt('New add-on name:');
    if (!name || !name.trim()) return;
    ajax(D.urls.addonsBase, 'POST', {
      op: 'save_addon', name: name.trim(),
      price_cents: 0, default_duration_minutes: 0,
    }).then(function (r) {
      if (!(r.ok && r.json && r.json.success)) { alert('Add-on save failed'); return; }
      var a = r.json.addon;
      state.library.push({
        id: a.id, name: a.name, description: a.description || '',
        price_cents: a.price_cents|0,
        default_duration_minutes: a.default_duration_minutes|0,
        is_active: !!a.is_active, sort_order: a.sort_order|0,
        usage_count: a.usage_count|0,
      });
      renderAll();
    });
  }

  // Wrap renderAll so bindings refresh after each render
  var _origRenderAll = renderAll;
  renderAll = function () {
    _origRenderAll();
    bindInlineEdits();
    bindActiveToggles();
    bindAddButton();
    bindDrawerFields();
    bindAddonLibEvents();
  };

  bindDrawerActions();
  bindAddonPicker();

    // expose for later heredocs to extend
  window.SvApp = {
    state: state,
    renderAll: renderAll,
    renderList: renderList,
    renderTable: renderTable,
    renderAddonLib: renderAddonLib,
    findService: findService,
    findCategoryByServiceId: findCategoryByServiceId,
    flatServices: flatServices,
    fmtMoney: fmtMoney,
    fmtDuration: fmtDuration,
    esc: esc,
    serviceWallClock: serviceWallClock,
    serviceCustomerTime: serviceCustomerTime,
    addonUsageCount: addonUsageCount,
    normalizeService: normalizeService,
    normalizeAttachedAddon: normalizeAttachedAddon,
    D: D,
    csrf: csrf,
  };

  document.addEventListener('DOMContentLoaded', function () {
    bindEvents();
    renderAll();
  });

}());
