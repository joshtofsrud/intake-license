/*
 * MARKER-LIVE-IDENTIFY — the moment someone tabs out of SKU, barcode, EAN or
 * part number on the Add or Edit item form, ask whether that product is
 * already in inventory, and say so right under the field.
 *
 * <form data-identify-url="…" data-identify-mode="create|edit" data-identify-except="item id">
 * Add item: Open it / Add stock / It's a different product (which sets
 * duplicate_ok so the save-time check lets it through).
 * Edit item: Open it, and a note to merge instead — saving onto another
 * item's barcode is refused by the server.
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-identify-url]');
  if (!form) { return; }

  var url    = form.getAttribute('data-identify-url');
  var mode   = form.getAttribute('data-identify-mode') || 'create';
  var except = form.getAttribute('data-identify-except') || '';
  var fields = ['sku', 'catalog_upc', 'catalog_ean', 'catalog_mpn'];
  var last = {};
  var different = {};

  function money(c) { return (c === null || c === undefined) ? 'no price' : '$' + (c / 100).toFixed(2); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function boxFor(input) {
    var id = 'identify-' + input.name;
    var box = document.getElementById(id);
    if (box) { return box; }
    box = document.createElement('div');
    box.id = id;
    box.className = 'ia-flash';
    box.setAttribute('role', 'status');
    box.style.cssText = 'display:none;border:0.5px solid var(--ia-accent);margin:4px 0 16px';
    var anchor = input.closest('.ia-form-row') || input.closest('.ia-form-group') || input;
    anchor.insertAdjacentElement('afterend', box);
    return box;
  }

  function show(input, m) {
    var box = boxFor(input);
    var title = m.strength === 'possible' ? 'Possibly already in your inventory' : 'Already in your inventory';
    var html = '<strong>' + title + '</strong>'
      + '<div style="margin:6px 0 10px">' + esc(m.name) + ' · ' + money(m.price) + ' · ' + esc(m.stock) + ' in stock · same ' + esc(m.how) + '</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
      + '<a class="ia-btn ia-btn--secondary ia-btn--sm" href="' + esc(m.url) + '">Open it</a>';
    if (mode === 'create') {
      html += '<a class="ia-btn ia-btn--primary ia-btn--sm" href="' + esc(m.adjust_url) + '">Add stock</a>'
        + '<button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-different="' + esc(m.id) + '" style="margin-left:auto">It\'s a different product</button>';
    } else {
      html += '<span style="font-size:12.5px;opacity:.8">Merge the two items instead of giving them the same code.</span>';
    }
    html += '</div>';
    box.innerHTML = html;
    box.style.display = '';
    var btn = box.querySelector('[data-different]');
    if (btn) {
      btn.addEventListener('click', function () {
        different[btn.getAttribute('data-different')] = true;
        var ok = form.querySelector('input[name="duplicate_ok"]');
        if (ok) { ok.value = '1'; }
        box.style.display = 'none';
      });
    }
  }

  function hide(input) {
    var box = document.getElementById('identify-' + input.name);
    if (box) { box.style.display = 'none'; }
  }

  function check(input) {
    var value = input.value.trim();
    if (last[input.name] === value) { return; }
    last[input.name] = value;
    if (!value) { hide(input); return; }

    var q = url + '?field=' + encodeURIComponent(input.name) + '&value=' + encodeURIComponent(value)
      + (except ? '&except=' + encodeURIComponent(except) : '');
    fetch(q, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : { match: null }; })
      .then(function (d) {
        if (input.value.trim() !== value) { return; }
        if (d && d.match && !different[d.match.id]) { show(input, d.match); } else { hide(input); }
      })
      .catch(function () { /* the save-time check still stands */ });
  }

  fields.forEach(function (name) {
    var input = form.querySelector('[name="' + name + '"]');
    if (!input) { return; }
    input.addEventListener('change', function () { check(input); });
    input.addEventListener('blur', function () { check(input); });
  });
})();
