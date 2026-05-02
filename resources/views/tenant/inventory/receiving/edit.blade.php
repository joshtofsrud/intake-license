@extends('layouts.tenant.app')
@php
  $pageTitle = 'Editing ' . $shipment->shipment_number;
  $statusOptions = [
    'expected' => 'Expected',
    'received' => 'Received',
    'backorder' => 'Backorder',
    'unexpected_pending' => 'Pending',
    'unexpected_added' => 'Added',
    'unexpected_hold' => 'On hold',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">{{ $shipment->shipment_number }}</h1>
    <p class="ia-page-subtitle">
      Draft · {{ $shipment->location?->name ?? '—' }} ·
      Started {{ $shipment->created_at->diffForHumans() }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
    <form method="POST" action="{{ route('tenant.inventory.receiving.destroy', ['id' => $shipment->id]) }}"
          style="display:inline" onsubmit="return confirm('Delete this draft shipment?');">
      @csrf @method('DELETE')
      <button class="ia-btn ia-btn--ghost" style="color:var(--ia-danger,#ff8080)">Delete draft</button>
    </form>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

<form method="POST" action="{{ route('tenant.inventory.receiving.update', ['id' => $shipment->id]) }}"
      class="ia-card" style="margin-bottom:14px">
  @csrf @method('PATCH')
  <div class="ia-card-body" style="padding:16px 20px">
    <div style="display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">Shipment number</label>
        <input name="shipment_number" class="ia-input" value="{{ $shipment->shipment_number }}" required maxlength="30">
      </div>
      <div class="ia-field">
        <label class="ia-label">Received date</label>
        <input name="received_date" type="date" class="ia-input" value="{{ $shipment->received_date?->toDateString() }}" required>
      </div>
      <div class="ia-field">
        <label class="ia-label">Distributor</label>
        <input name="distributor_name" class="ia-input" value="{{ $shipment->distributor_name }}" maxlength="128">
      </div>
      <div class="ia-field">
        <label class="ia-label">Distributor code</label>
        <input name="distributor_code" class="ia-input" value="{{ $shipment->distributor_code }}" maxlength="32">
      </div>
      <div class="ia-field">
        <label class="ia-label">Shipping cost (cents)</label>
        <input name="shipping_cost_cents" type="number" min="0" class="ia-input" value="{{ $shipment->shipping_cost_cents }}">
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Notes</label>
        <textarea name="notes" class="ia-input" rows="2" maxlength="2000">{{ $shipment->notes }}</textarea>
      </div>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button type="submit" class="ia-btn ia-btn--secondary">Save header</button>
    </div>
  </div>
</form>

<div id="rcv-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;margin-bottom:14px;border:1px solid var(--ia-border);border-radius:6px;overflow:hidden">
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Expected</div>
    <div style="font-size:22px;font-weight:600;margin-top:2px" data-stat="expected">{{ $shipment->expected_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Received</div>
    <div style="font-size:22px;font-weight:600;color:var(--ia-accent);margin-top:2px" data-stat="received">{{ $shipment->received_count }}</div>
  </div>
  <div style="padding:12px 14px;border-right:1px solid var(--ia-border)">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Backorder</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px" data-stat="backorder">{{ $shipment->backorder_count }}</div>
  </div>
  <div style="padding:12px 14px">
    <div style="font-size:11px;color:var(--ia-text-muted);letter-spacing:.05em;text-transform:uppercase">Unexpected</div>
    <div style="font-size:22px;font-weight:600;color:#f4b400;margin-top:2px" data-stat="unexpected">{{ $shipment->unexpected_count }}</div>
  </div>
</div>

<div class="ia-toolbar" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
  <h2 style="font-size:15px;margin:0">Line items</h2>
  <div style="display:flex;gap:8px">
    <button type="button" class="ia-btn ia-btn--secondary" onclick="rcvOpenAddModal('expected')">+ Add line</button>
    <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvOpenAddModal('unexpected')">+ Unexpected</button>
  </div>
</div>

<div class="ia-card">
  <table class="ia-table" id="rcv-lines">
    <thead>
      <tr>
        <th style="width:30%">Item</th>
        <th style="width:16%">SKU</th>
        <th style="width:9%;text-align:right">Expected</th>
        <th style="width:9%;text-align:right">Received</th>
        <th style="width:14%">Status</th>
        <th style="width:12%;text-align:right">Cost ¢</th>
        <th style="width:5%"></th>
      </tr>
    </thead>
    <tbody id="rcv-tbody">
      @forelse($shipment->items as $line)
        @include('tenant.inventory.receiving._partials.line', ['line' => $line, 'statusOptions' => $statusOptions])
      @empty
        <tr id="rcv-empty"><td colspan="7" style="text-align:center;padding:30px 16px;color:var(--ia-text-muted)">
          No lines yet. Click "+ Add line" to start.
        </td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;padding-top:16px;border-top:1px solid var(--ia-border)">
  <div id="rcv-commit-note" style="font-size:13px;color:var(--ia-text-muted)">
    Commits <strong id="rcv-commit-lines" style="color:var(--ia-accent)">0 items</strong>,
    <strong id="rcv-commit-units" style="color:var(--ia-accent)">0 units</strong>.
    Backorder + unexpected lines stay on the shipment but won't write movements.
  </div>
  <form method="POST" action="{{ route('tenant.inventory.receiving.commit', ['id' => $shipment->id]) }}"
        id="rcv-commit-form" onsubmit="return rcvConfirmCommit(event);">
    @csrf
    <button type="submit" class="ia-btn ia-btn--primary" id="rcv-commit-btn"
            @if($shipment->received_count === 0) disabled @endif>
      Commit shipment
    </button>
  </form>
</div>

<div id="rcv-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;align-items:flex-start;justify-content:center;padding-top:80px">
  <div style="background:var(--ia-card,#111);border:1px solid var(--ia-border);border-radius:8px;padding:20px 22px;width:90%;max-width:560px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h3 style="font-size:15px;margin:0" id="rcv-modal-title">Add line item</h3>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseModal()" style="padding:2px 8px">×</button>
    </div>

    <div id="rcv-modal-search-block">
      <label class="ia-label">Search inventory <span style="color:var(--ia-text-muted);font-weight:normal">(scan or type SKU/UPC/name)</span></label>
      <input type="text" class="ia-input" id="rcv-search" placeholder="SKU, UPC, or name…" autocomplete="off" style="width:100%">
      <div id="rcv-results" style="margin-top:8px;max-height:200px;overflow-y:auto;border:1px solid var(--ia-border);border-radius:4px;display:none"></div>
    </div>

    <div id="rcv-modal-form" style="display:none;margin-top:14px">
      <div id="rcv-selected-summary" style="padding:8px 12px;background:var(--ia-card-soft,#0e0e0e);border-radius:4px;margin-bottom:12px;font-size:12.5px"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <div class="ia-field">
          <label class="ia-label">Expected qty</label>
          <input type="number" class="ia-input" id="rcv-expected-qty" min="0" max="99999" value="1">
        </div>
        <div class="ia-field">
          <label class="ia-label">Received qty</label>
          <input type="number" class="ia-input" id="rcv-received-qty" min="0" max="99999" value="1">
        </div>
        <div class="ia-field">
          <label class="ia-label">Unit cost (cents)</label>
          <input type="number" class="ia-input" id="rcv-unit-cost" min="0" placeholder="auto from item">
        </div>
      </div>
      <div id="rcv-unexpected-extra" style="display:none;margin-top:10px">
        <div class="ia-field">
          <label class="ia-label">Item name (no match in inventory)</label>
          <input type="text" class="ia-input" id="rcv-unexpected-name" maxlength="255" placeholder="What is this?">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px">
          <div class="ia-field">
            <label class="ia-label">SKU</label>
            <input type="text" class="ia-input" id="rcv-unexpected-sku" maxlength="64">
          </div>
          <div class="ia-field">
            <label class="ia-label">UPC</label>
            <input type="text" class="ia-input" id="rcv-unexpected-upc" maxlength="20">
          </div>
        </div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseModal()">Cancel</button>
        <button type="button" class="ia-btn ia-btn--primary" id="rcv-add-confirm" onclick="rcvSubmitAdd()">Add line</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var csrf = '{{ csrf_token() }}';
  var urls = {
    addItem:    '{{ route("tenant.inventory.receiving.items.store",  ["id" => $shipment->id]) }}',
    updateItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.update", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    removeItem: function (lineId) { return '{{ route("tenant.inventory.receiving.items.destroy", ["id" => $shipment->id, "itemId" => "__LINE__"]) }}'.replace('__LINE__', lineId); },
    search:     '{{ route("tenant.inventory.items.search") }}',
  };

  function jsonReq(method, url, body) {
    var opts = {
      method: method,
      headers: {
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(url, opts).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
    });
  }

  function toastOk(msg)  { if (window.IntakeToast) window.IntakeToast.success(msg); }
  function toastErr(msg) { if (window.IntakeToast) window.IntakeToast.error(msg); }

  function applyTotals(t) {
    if (!t) return;
    document.querySelector('[data-stat="expected"]').textContent   = t.expected;
    document.querySelector('[data-stat="received"]').textContent   = t.received;
    document.querySelector('[data-stat="backorder"]').textContent  = t.backorder;
    document.querySelector('[data-stat="unexpected"]').textContent = t.unexpected;
    document.getElementById('rcv-commit-lines').textContent = t.commit_lines + ' items';
    document.getElementById('rcv-commit-units').textContent = t.commit_units + ' units';
    document.getElementById('rcv-commit-btn').disabled = !t.can_commit;
    var empty = document.getElementById('rcv-empty');
    var hasLines = (t.expected + t.received + t.backorder + t.unexpected) > 0
                  || document.querySelectorAll('#rcv-tbody tr[data-line-id]').length > 0;
    if (empty) empty.style.display = hasLines ? 'none' : '';
  }

  function escapeHtml(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function statusSelectHtml(current) {
    var opts = [
      ['expected', 'Expected'], ['received', 'Received'], ['backorder', 'Backorder'],
      ['unexpected_pending', 'Pending'], ['unexpected_added', 'Added'], ['unexpected_hold', 'On hold'],
    ];
    var html = '<select class="ia-input rcv-cell" data-field="status" style="padding:3px 6px;font-size:12px">';
    opts.forEach(function (o) {
      html += '<option value="' + o[0] + '"' + (o[0] === current ? ' selected' : '') + '>' + o[1] + '</option>';
    });
    return html + '</select>';
  }

  function renderRow(line) {
    var tr = document.createElement('tr');
    tr.setAttribute('data-line-id', line.id);
    tr.setAttribute('data-status', line.status);
    if (line.is_unexpected) tr.style.background = 'rgba(244,180,0,.06)';
    tr.innerHTML =
      '<td>' +
        '<div style="font-weight:500">' + escapeHtml(line.name) + '</div>' +
        (line.category ? '<div style="font-size:11px;color:var(--ia-text-muted);margin-top:1px">' + escapeHtml(line.category) + '</div>' : '') +
      '</td>' +
      '<td><code style="font-size:11.5px;color:var(--ia-accent)">' + escapeHtml(line.sku || '') + '</code></td>' +
      '<td style="text-align:right;font-variant-numeric:tabular-nums">' +
        (line.is_unexpected
          ? '<span style="color:var(--ia-text-muted)">—</span>'
          : '<input class="ia-input rcv-cell" data-field="expected_quantity" type="number" min="0" max="99999" value="' + line.expected_quantity + '" style="width:70px;padding:3px 6px;text-align:right">') +
      '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="received_quantity" type="number" min="0" max="99999" value="' + line.received_quantity + '" style="width:70px;padding:3px 6px;text-align:right">' +
      '</td>' +
      '<td>' + statusSelectHtml(line.status) + '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="unit_cost_cents" type="number" min="0" value="' + (line.unit_cost_cents || '') + '" style="width:90px;padding:3px 6px;text-align:right" placeholder="—">' +
      '</td>' +
      '<td style="text-align:right">' +
        '<button type="button" class="ia-btn ia-btn--ghost" onclick="rcvRemoveLine(\'' + line.id + '\')" style="padding:2px 8px;color:var(--ia-text-muted)" title="Remove">×</button>' +
      '</td>';
    return tr;
  }

  document.getElementById('rcv-tbody').addEventListener('change', function (e) {
    var cell = e.target;
    if (!cell.classList.contains('rcv-cell')) return;
    var row = cell.closest('tr[data-line-id]');
    if (!row) return;
    var lineId = row.getAttribute('data-line-id');
    var field  = cell.getAttribute('data-field');
    var value  = cell.value;
    if (cell.type === 'number' && value !== '') value = parseInt(value, 10);
    if (cell.type === 'number' && value === '') value = null;
    var payload = {};
    payload[field] = value;
    jsonReq('PATCH', urls.updateItem(lineId), payload).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        applyTotals(res.body.totals);
        if (field === 'status') {
          row.setAttribute('data-status', value);
          row.style.background = (value && value.indexOf('unexpected') === 0) ? 'rgba(244,180,0,.06)' : '';
        }
        toastOk('Saved');
      } else {
        toastErr((res.body && res.body.message) || 'Could not save.');
      }
    }).catch(function () { toastErr('Network error. Try again.'); });
  });

  document.getElementById('rcv-tbody').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.classList.contains('rcv-cell')) {
      e.target.blur();
      e.preventDefault();
    }
  });

  window.rcvRemoveLine = function (lineId) {
    if (!confirm('Remove this line?')) return;
    jsonReq('DELETE', urls.removeItem(lineId)).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        var row = document.querySelector('#rcv-tbody tr[data-line-id="' + lineId + '"]');
        if (row) {
          row.style.transition = 'opacity .2s';
          row.style.opacity = '0';
          setTimeout(function () { row.remove(); applyTotals(res.body.totals); }, 200);
        } else { applyTotals(res.body.totals); }
        toastOk('Line removed');
      } else {
        toastErr((res.body && res.body.message) || 'Could not remove.');
      }
    }).catch(function () { toastErr('Network error. Try again.'); });
  };

  var modalMode = 'expected';
  var modalSelected = null;
  var lastResults = [];
  var searchTimer = null;

  window.rcvOpenAddModal = function (mode) {
    modalMode = mode;
    modalSelected = null;
    lastResults = [];
    document.getElementById('rcv-modal-title').textContent = (mode === 'unexpected') ? 'Add unexpected line' : 'Add line item';
    document.getElementById('rcv-modal-search-block').style.display = '';
    document.getElementById('rcv-search').value = '';
    document.getElementById('rcv-results').style.display = 'none';
    document.getElementById('rcv-results').innerHTML = '';
    document.getElementById('rcv-modal-form').style.display = (mode === 'unexpected') ? '' : 'none';
    document.getElementById('rcv-unexpected-extra').style.display = (mode === 'unexpected') ? '' : 'none';
    document.getElementById('rcv-unexpected-name').value = '';
    document.getElementById('rcv-unexpected-sku').value = '';
    document.getElementById('rcv-unexpected-upc').value = '';
    document.getElementById('rcv-expected-qty').value = (mode === 'unexpected') ? '0' : '1';
    document.getElementById('rcv-received-qty').value = '1';
    document.getElementById('rcv-unit-cost').value = '';
    document.getElementById('rcv-unit-cost').placeholder = 'auto from item';
    document.getElementById('rcv-selected-summary').textContent = '';
    document.getElementById('rcv-modal').style.display = 'flex';
    setTimeout(function () {
      var first = (mode === 'unexpected')
        ? document.getElementById('rcv-unexpected-name')
        : document.getElementById('rcv-search');
      first && first.focus();
    }, 50);
  };

  window.rcvCloseModal = function () {
    document.getElementById('rcv-modal').style.display = 'none';
  };

  function selectResult(r) {
    modalSelected = { id: r.id, name: r.name, sku: r.sku || '', cost: r.unit_cost_cents || '' };
    document.getElementById('rcv-selected-summary').innerHTML =
      '<strong>' + escapeHtml(r.name) + '</strong> · <code>' + escapeHtml(r.sku || '') + '</code>';
    document.getElementById('rcv-unit-cost').placeholder = r.unit_cost_cents
      ? ('default ' + r.unit_cost_cents) : 'no default';
    document.getElementById('rcv-results').style.display = 'none';
    document.getElementById('rcv-modal-form').style.display = '';
    setTimeout(function () {
      var rq = document.getElementById('rcv-received-qty');
      rq.focus(); rq.select();
    }, 30);
  }

  document.getElementById('rcv-search').addEventListener('input', function (e) {
    if (searchTimer) clearTimeout(searchTimer);
    var q = e.target.value.trim();
    if (q.length < 2) {
      lastResults = [];
      document.getElementById('rcv-results').style.display = 'none';
      return;
    }
    searchTimer = setTimeout(function () {
      fetch(urls.search + '?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (j) {
        lastResults = (j.ok && j.results) ? j.results : [];
        var box = document.getElementById('rcv-results');
        if (!lastResults.length) {
          box.innerHTML = '<div style="padding:10px 12px;color:var(--ia-text-muted);font-size:12.5px">No matches.</div>';
          box.style.display = '';
          return;
        }
        box.innerHTML = lastResults.map(function (r) {
          return '<div class="rcv-search-row" data-item-id="' + r.id +
            '" style="padding:8px 12px;border-bottom:1px solid var(--ia-border);cursor:pointer">' +
            '<div style="font-weight:500">' + escapeHtml(r.name) + '</div>' +
            '<div style="font-size:11.5px;color:var(--ia-text-muted)"><code>' + escapeHtml(r.sku || '') + '</code>' +
            (r.category ? ' · ' + escapeHtml(r.category) : '') + '</div></div>';
        }).join('');
        box.style.display = '';
      });
    }, 180);
  });

  document.getElementById('rcv-search').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var q = e.target.value.trim();
    if (q.length < 2) return;
    if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
    fetch(urls.search + '?q=' + encodeURIComponent(q), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      var results = (j.ok && j.results) ? j.results : [];
      if (results.length === 1) {
        selectResult(results[0]);
        rcvSubmitAdd(true);
      } else if (results.length > 1) {
        lastResults = results;
        var box = document.getElementById('rcv-results');
        box.innerHTML = results.map(function (r) {
          return '<div class="rcv-search-row" data-item-id="' + r.id +
            '" style="padding:8px 12px;border-bottom:1px solid var(--ia-border);cursor:pointer">' +
            '<div style="font-weight:500">' + escapeHtml(r.name) + '</div>' +
            '<div style="font-size:11.5px;color:var(--ia-text-muted)"><code>' + escapeHtml(r.sku || '') + '</code>' +
            (r.category ? ' · ' + escapeHtml(r.category) : '') + '</div></div>';
        }).join('');
        box.style.display = '';
        toastOk(results.length + ' matches — pick one');
      } else {
        toastErr('No match for "' + q + '"');
      }
    });
  });

  document.getElementById('rcv-results').addEventListener('click', function (e) {
    var row = e.target.closest('.rcv-search-row');
    if (!row) return;
    var id = row.getAttribute('data-item-id');
    var match = lastResults.find(function (r) { return r.id === id; });
    if (match) selectResult(match);
  });

  window.rcvSubmitAdd = function (fromScan) {
    var btn = document.getElementById('rcv-add-confirm');
    btn.disabled = true; btn.textContent = '…';

    var payload = {
      mode: modalMode,
      expected_quantity: parseInt(document.getElementById('rcv-expected-qty').value || '0', 10),
      received_quantity: parseInt(document.getElementById('rcv-received-qty').value || '0', 10),
    };
    var costVal = document.getElementById('rcv-unit-cost').value;
    if (costVal !== '') payload.unit_cost_cents = parseInt(costVal, 10);

    if (modalMode === 'expected') {
      if (!modalSelected) {
        toastErr('Pick an item from search first.');
        btn.disabled = false; btn.textContent = 'Add line';
        return;
      }
      payload.inventory_item_id = modalSelected.id;
    } else {
      var name = document.getElementById('rcv-unexpected-name').value.trim();
      var sku  = document.getElementById('rcv-unexpected-sku').value.trim();
      var upc  = document.getElementById('rcv-unexpected-upc').value.trim();
      if (modalSelected) {
        payload.inventory_item_id = modalSelected.id;
      } else if (!name) {
        toastErr('Enter an item name or pick a match.');
        btn.disabled = false; btn.textContent = 'Add line';
        return;
      } else {
        payload.name = name;
        if (sku) payload.sku = sku;
        if (upc) payload.upc = upc;
      }
    }

    jsonReq('POST', urls.addItem, payload).then(function (res) {
      btn.disabled = false; btn.textContent = 'Add line';
      if (res.ok && res.body && res.body.ok) {
        var emptyRow = document.getElementById('rcv-empty');
        if (emptyRow) emptyRow.remove();
        document.getElementById('rcv-tbody').appendChild(renderRow(res.body.line));
        applyTotals(res.body.totals);
        toastOk('Line added');
        if (fromScan && modalMode === 'expected') {
          modalSelected = null;
          document.getElementById('rcv-modal-form').style.display = 'none';
          document.getElementById('rcv-search').value = '';
          document.getElementById('rcv-search').focus();
          document.getElementById('rcv-selected-summary').textContent = '';
        } else {
          rcvCloseModal();
        }
      } else {
        toastErr((res.body && res.body.message) || 'Could not add.');
      }
    }).catch(function () {
      btn.disabled = false; btn.textContent = 'Add line';
      toastErr('Network error. Try again.');
    });
  };

  window.rcvConfirmCommit = function (e) {
    var lines = document.getElementById('rcv-commit-lines').textContent;
    var units = document.getElementById('rcv-commit-units').textContent;
    if (!confirm('Commit will write movements for ' + lines + ', ' + units + '. This cannot be undone. Continue?')) {
      e.preventDefault();
      return false;
    }
    return true;
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('rcv-modal').style.display === 'flex') {
      rcvCloseModal();
    }
  });
})();
</script>

@endsection
