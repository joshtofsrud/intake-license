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


@push('styles')
<style>
/* "Best on desktop" mobile notice (patch #38). Hidden on >640px. */
.recv-mobile-notice{display:none;background:rgba(250,180,106,.08);border:0.5px solid rgba(250,180,106,.25);border-radius:var(--ia-r-lg);padding:14px 16px;margin-bottom:16px}
.recv-mobile-notice-title{font-size:13px;font-weight:600;color:#FAB46A;margin-bottom:4px;display:flex;align-items:center;gap:6px}
.recv-mobile-notice-body{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
@media(max-width:640px){
  .recv-mobile-notice{display:block}
}
</style>
@endpush

@section('content')


{{-- Mobile "best on desktop" notice (patch #38). Receiving is line-by-line
     entry that doesn't fit a phone — v1.1 will likely add barcode scanning
     and a different mobile flow. For now we surface the limitation rather
     than rebuild the form. --}}
<div class="recv-mobile-notice">
  <div class="recv-mobile-notice-title">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Best on desktop
  </div>
  <div class="recv-mobile-notice-body">
    Receiving works on mobile, but line-by-line entry is faster on a larger screen. Mobile-optimized receiving (with barcode scanning) is on the roadmap.
  </div>
</div>

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
        <label class="ia-label">Shipping cost</label>
        <input name="shipping_cost_dollars" type="text" inputmode="decimal" class="ia-input"
               value="{{ number_format($shipment->shipping_cost_cents / 100, 2, '.', '') }}"
               placeholder="0.00">
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

<div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 8px 0">
  <h2 style="font-size:15px;margin:0">Line items</h2>
  <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvNewItem()"
          style="padding:4px 10px;font-size:12.5px;color:var(--ia-accent,#BEF264)">+ New item</button>
</div>

<div class="ia-table-wrap">
<table class="ia-table" id="rcv-lines">
  <thead>
    <tr>
      <th style="width:30%">Item</th>
      <th style="width:16%">SKU / UPC</th>
      <th style="width:9%;text-align:right">Expected</th>
      <th style="width:9%;text-align:right">Received</th>
      <th style="width:14%">Status</th>
      <th style="width:12%;text-align:right">Cost</th>
      <th style="width:5%"></th>
    </tr>
  </thead>
  <tbody id="rcv-tbody">
    @foreach($shipment->items as $line)
      @include('tenant.inventory.receiving._partials.line', ['line' => $line, 'statusOptions' => $statusOptions])
    @endforeach
    <tr id="rcv-newline" data-newline="1" style="background:var(--ia-surface-2,rgba(190,242,100,.04))">
      <td>
        <span style="color:var(--ia-accent,#BEF264);font-weight:500;font-size:13px">+ Add line</span>
        <div style="font-size:11px;color:var(--ia-text-muted);margin-top:2px">Scan or type to find an item</div>
      </td>
      <td>
        <input type="text" class="ia-input" id="rcv-newline-sku" autocomplete="off"
               placeholder="SKU, UPC, or name + Enter"
               style="padding:5px 9px;font-size:12.5px;width:100%;border:1px solid var(--ia-border,#2a2a2a);background:var(--ia-input-bg,#0a0a0a)">
      </td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td><span style="color:var(--ia-text-dim,#555);font-size:11px">auto</span></td>
      <td style="text-align:right;color:var(--ia-text-dim,#555)">—</td>
      <td></td>
    </tr>
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

<div id="rcv-item-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:200;align-items:flex-start;justify-content:center;padding-top:60px;overflow-y:auto">
  <div style="background:var(--ia-card,#111);border:1px solid var(--ia-border);border-radius:8px;padding:18px 22px;width:94%;max-width:680px;margin-bottom:60px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="font-size:15px;margin:0" id="rcv-item-modal-title">Item</h3>
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()" style="padding:2px 8px">×</button>
    </div>

    <div id="rcv-item-modal-error" style="display:none;padding:8px 12px;background:rgba(255,80,80,.12);border:1px solid rgba(255,80,80,.3);border-radius:4px;margin-bottom:12px;font-size:12.5px;color:#ff8080"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px">
      <div class="ia-field">
        <label class="ia-label">SKU *</label>
        <input type="text" class="ia-input" id="rcv-item-sku" maxlength="64" required>
      </div>
      <div class="ia-field">
        <label class="ia-label">Category *</label>
        <select class="ia-input" id="rcv-item-category" required>
          <option value="">— pick category —</option>
        </select>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Name *</label>
        <input type="text" class="ia-input" id="rcv-item-name" maxlength="255" required>
      </div>
      <div class="ia-field" style="grid-column:1 / -1">
        <label class="ia-label">Description</label>
        <textarea class="ia-input" id="rcv-item-description" rows="2"></textarea>
      </div>
      <div class="ia-field">
        <label class="ia-label">Cost</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-cost" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Sell price</label>
        <input type="text" inputmode="decimal" class="ia-input" id="rcv-item-sell" placeholder="0.00">
      </div>
      <div class="ia-field">
        <label class="ia-label">Case quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-case-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder threshold</label>
        <input type="number" min="0" class="ia-input" id="rcv-item-reorder-threshold">
      </div>
      <div class="ia-field">
        <label class="ia-label">Reorder quantity</label>
        <input type="number" min="1" class="ia-input" id="rcv-item-reorder-qty">
      </div>
      <div class="ia-field">
        <label class="ia-label">Bin location</label>
        <input type="text" maxlength="50" class="ia-input" id="rcv-item-bin">
      </div>
      <div class="ia-field" style="display:flex;align-items:center;gap:10px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-active" checked> Active
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
          <input type="checkbox" id="rcv-item-oversell"> Allow oversell
        </label>
      </div>
      <div class="ia-field" id="rcv-item-catalog-info" style="display:none;font-size:11.5px;color:var(--ia-text-muted)">
        <span id="rcv-item-catalog-upc"></span>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="rcvCloseItemModal()">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="rcv-item-save-btn" onclick="rcvSaveItem()">Save</button>
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
  function toastInfo(msg){ if (window.IntakeToast) window.IntakeToast.info(msg); }

  function centsToDollars(c) {
    if (c == null || c === '') return '';
    return (c / 100).toFixed(2);
  }

  function applyTotals(t) {
    if (!t) return;
    document.querySelector('[data-stat="expected"]').textContent   = t.expected;
    document.querySelector('[data-stat="received"]').textContent   = t.received;
    document.querySelector('[data-stat="backorder"]').textContent  = t.backorder;
    document.querySelector('[data-stat="unexpected"]').textContent = t.unexpected;
    document.getElementById('rcv-commit-lines').textContent = t.commit_lines + ' items';
    document.getElementById('rcv-commit-units').textContent = t.commit_units + ' units';
    document.getElementById('rcv-commit-btn').disabled = !t.can_commit;
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
        (line.category ? '<div style="font-size:11px;color:var(--ia-text-muted);margin-top:1px">' + escapeHtml(line.category) + '</div>'
          : (line.is_unexpected ? '<div style="font-size:11px;color:#f4b400;margin-top:1px">Unexpected · not on PO</div>' : '')) +
      '</td>' +
      '<td><code style="font-size:11.5px;color:var(--ia-accent)">' + escapeHtml(line.sku || '') + '</code></td>' +
      '<td style="text-align:right">' +
        (line.is_unexpected
          ? '<span style="color:var(--ia-text-muted)">—</span>'
          : '<input class="ia-input rcv-cell" data-field="expected_quantity" type="number" min="0" max="99999" value="' + line.expected_quantity + '" style="width:64px;padding:3px 6px;text-align:right">') +
      '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="received_quantity" type="number" min="0" max="99999" value="' + line.received_quantity + '" style="width:64px;padding:3px 6px;text-align:right">' +
      '</td>' +
      '<td>' + statusSelectHtml(line.status) + '</td>' +
      '<td style="text-align:right">' +
        '<input class="ia-input rcv-cell" data-field="unit_cost_dollars" type="text" inputmode="decimal" value="' + centsToDollars(line.unit_cost_cents) + '" style="width:80px;padding:3px 6px;text-align:right" placeholder="0.00">' +
      '</td>' +
      '<td style="text-align:right;white-space:nowrap">' +
        (line.inventory_item_id
          ? '<button type="button" class="ia-btn ia-btn--ghost" onclick="rcvEditItem(\'' + line.inventory_item_id + '\', \'' + line.id + '\')" style="padding:2px 6px;color:var(--ia-text-muted);margin-right:2px" title="Edit item">✎</button>'
          : '') +
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
    var rawValue = cell.value;
    var sendValue;

    if (field === 'unit_cost_dollars') {
      sendValue = rawValue;
    } else if (cell.type === 'number') {
      sendValue = rawValue === '' ? null : parseInt(rawValue, 10);
    } else {
      sendValue = rawValue;
    }

    var payload = {};
    payload[field] = sendValue;
    jsonReq('PATCH', urls.updateItem(lineId), payload).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        applyTotals(res.body.totals);
        if (field === 'status') {
          row.setAttribute('data-status', sendValue);
          row.style.background = (sendValue && sendValue.indexOf('unexpected') === 0) ? 'rgba(244,180,0,.06)' : '';
        }
        if (field === 'unit_cost_dollars' && res.body.line) {
          cell.value = centsToDollars(res.body.line.unit_cost_cents);
        }
        toastOk('Saved');
      } else {
        toastErr((res.body && res.body.message) || 'Could not save.');
      }
    }).catch(function () { toastErr('Network error. Try again.'); });
  });

  document.getElementById('rcv-tbody').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.classList.contains('rcv-cell')) {
      e.preventDefault();
      e.target.blur();
      setTimeout(function () {
        var sku = document.getElementById('rcv-newline-sku');
        if (sku) sku.focus();
      }, 50);
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

  var newLineInput = document.getElementById('rcv-newline-sku');
  var newLineRow   = document.getElementById('rcv-newline');
  var submittingNewLine = false;

  newLineInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (submittingNewLine) return;
    var raw = newLineInput.value.trim();
    if (raw.length < 1) return;
    submittingNewLine = true;
    newLineInput.disabled = true;

    fetch(urls.search + '?q=' + encodeURIComponent(raw), {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      var results = (j.ok && j.results) ? j.results : [];
      if (results.length === 1) {
        addExpectedLine(results[0]);
      } else if (results.length > 1) {
        submittingNewLine = false;
        newLineInput.disabled = false;
        newLineInput.focus();
        toastInfo(results.length + ' matches — type more to narrow');
      } else {
        addUnexpectedLine(raw);
      }
    }).catch(function () {
      submittingNewLine = false;
      newLineInput.disabled = false;
      toastErr('Network error. Try again.');
    });
  });

  function addExpectedLine(item) {
    var payload = {
      mode: 'expected',
      inventory_item_id: item.id,
      expected_quantity: 1,
      received_quantity: 1,
    };
    jsonReq('POST', urls.addItem, payload).then(function (res) {
      finishNewLine(res, 'Line added');
    }).catch(function () { finishNewLine({ ok: false }, 'Network error.'); });
  }

  function addUnexpectedLine(raw) {
    var looksLikeUpc = /^[0-9]{8,14}$/.test(raw);
    var payload = {
      mode: 'unexpected',
      name: raw,
      sku: looksLikeUpc ? null : raw,
      upc: looksLikeUpc ? raw : null,
      expected_quantity: 0,
      received_quantity: 1,
    };
    jsonReq('POST', urls.addItem, payload).then(function (res) {
      finishNewLine(res, 'Unexpected SKU added');
    }).catch(function () { finishNewLine({ ok: false }, 'Network error.'); });
  }

  function finishNewLine(res, successMsg) {
    submittingNewLine = false;
    newLineInput.disabled = false;
    if (res.ok && res.body && res.body.ok) {
      var newRow = renderRow(res.body.line);
      newLineRow.parentNode.insertBefore(newRow, newLineRow);
      applyTotals(res.body.totals);
      newLineInput.value = '';
      newLineInput.focus();
      toastOk(successMsg);
    } else {
      toastErr((res.body && res.body.message) || 'Could not add line.');
      newLineInput.focus();
      newLineInput.select();
    }
  }

  window.rcvConfirmCommit = function (e) {
    var lines = document.getElementById('rcv-commit-lines').textContent;
    var units = document.getElementById('rcv-commit-units').textContent;
    if (!confirm('Commit will write movements for ' + lines + ', ' + units + '. This cannot be undone. Continue?')) {
      e.preventDefault();
      return false;
    }
    return true;
  };

  setTimeout(function () { newLineInput.focus(); }, 100);

  // ─── Item modal (edit existing + create new) ──────────────────────
  var modalMode = null;             // 'edit' | 'create'
  var modalEditingItemId = null;    // when mode=edit
  var modalLineId = null;           // the receiving line that opened the modal (mode=edit)
  var modalCategoriesLoaded = false;

  function ensureCategoriesLoaded() {
    if (modalCategoriesLoaded) return Promise.resolve();
    return fetch('{{ route("tenant.inventory.receiving.categories.list") }}', {
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      var sel = document.getElementById('rcv-item-category');
      sel.innerHTML = '<option value="">— pick category —</option>';
      j.categories.forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        sel.appendChild(opt);
      });
      modalCategoriesLoaded = true;
    });
  }

  function clearItemModal() {
    document.getElementById('rcv-item-sku').value = '';
    document.getElementById('rcv-item-name').value = '';
    document.getElementById('rcv-item-description').value = '';
    document.getElementById('rcv-item-cost').value = '';
    document.getElementById('rcv-item-sell').value = '';
    document.getElementById('rcv-item-case-qty').value = '';
    document.getElementById('rcv-item-reorder-threshold').value = '';
    document.getElementById('rcv-item-reorder-qty').value = '';
    document.getElementById('rcv-item-bin').value = '';
    document.getElementById('rcv-item-active').checked = true;
    document.getElementById('rcv-item-oversell').checked = false;
    document.getElementById('rcv-item-category').value = '';
    document.getElementById('rcv-item-catalog-info').style.display = 'none';
    document.getElementById('rcv-item-modal-error').style.display = 'none';
  }

  function showModalError(msg) {
    var box = document.getElementById('rcv-item-modal-error');
    box.textContent = msg;
    box.style.display = '';
  }

  window.rcvNewItem = function () {
    modalMode = 'create';
    modalEditingItemId = null;
    modalLineId = null;
    document.getElementById('rcv-item-modal-title').textContent = '+ New item';
    document.getElementById('rcv-item-save-btn').textContent = 'Create + add line';
    clearItemModal();
    ensureCategoriesLoaded().then(function () {
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-sku').focus(); }, 50);
    });
  };

  window.rcvEditItem = function (itemId, lineId) {
    modalMode = 'edit';
    modalEditingItemId = itemId;
    modalLineId = lineId;
    document.getElementById('rcv-item-modal-title').textContent = 'Edit item';
    document.getElementById('rcv-item-save-btn').textContent = 'Save';
    clearItemModal();
    ensureCategoriesLoaded().then(function () {
      var url = '{{ route("tenant.inventory.receiving.items.quick.show", ["id" => "__ID__"]) }}'.replace('__ID__', itemId);
      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.item) {
        toastErr(j.message || 'Could not load item.');
        return;
      }
      var it = j.item;
      document.getElementById('rcv-item-sku').value = it.sku || '';
      document.getElementById('rcv-item-name').value = it.name || '';
      document.getElementById('rcv-item-description').value = it.description || '';
      document.getElementById('rcv-item-cost').value = it.shop_cost_dollars || '';
      document.getElementById('rcv-item-sell').value = it.shop_sell_price_dollars || '';
      document.getElementById('rcv-item-case-qty').value = it.shop_case_quantity || '';
      document.getElementById('rcv-item-reorder-threshold').value = it.shop_reorder_threshold || '';
      document.getElementById('rcv-item-reorder-qty').value = it.shop_reorder_quantity || '';
      document.getElementById('rcv-item-bin').value = it.shop_bin_location || '';
      document.getElementById('rcv-item-active').checked = !!it.is_active;
      document.getElementById('rcv-item-oversell').checked = !!it.allow_oversell;
      document.getElementById('rcv-item-category').value = it.category_id || '';
      if (it.catalog_upc) {
        document.getElementById('rcv-item-catalog-info').style.display = '';
        document.getElementById('rcv-item-catalog-upc').textContent = 'Catalog UPC: ' + it.catalog_upc;
      }
      document.getElementById('rcv-item-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('rcv-item-name').focus(); }, 50);
    });
  };

  window.rcvCloseItemModal = function () {
    document.getElementById('rcv-item-modal').style.display = 'none';
    modalMode = null;
    modalEditingItemId = null;
    modalLineId = null;
  };

  window.rcvSaveItem = function () {
    var btn = document.getElementById('rcv-item-save-btn');
    var origLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';
    document.getElementById('rcv-item-modal-error').style.display = 'none';

    var payload = {
      category_id: document.getElementById('rcv-item-category').value,
      sku:         document.getElementById('rcv-item-sku').value.trim(),
      name:        document.getElementById('rcv-item-name').value.trim(),
      description: document.getElementById('rcv-item-description').value.trim() || null,
      shop_cost_dollars:       document.getElementById('rcv-item-cost').value.trim() || null,
      shop_sell_price_dollars: document.getElementById('rcv-item-sell').value.trim() || null,
      shop_case_quantity:      document.getElementById('rcv-item-case-qty').value || null,
      shop_reorder_threshold:  document.getElementById('rcv-item-reorder-threshold').value || null,
      shop_reorder_quantity:   document.getElementById('rcv-item-reorder-qty').value || null,
      shop_bin_location:       document.getElementById('rcv-item-bin').value.trim() || null,
      is_active:      document.getElementById('rcv-item-active').checked,
      allow_oversell: document.getElementById('rcv-item-oversell').checked,
    };

    if (!payload.sku || !payload.name || !payload.category_id) {
      btn.disabled = false; btn.textContent = origLabel;
      showModalError('SKU, name, and category are required.');
      return;
    }

    if (modalMode === 'edit') {
      var url = '{{ route("tenant.inventory.receiving.items.quick.update", ["id" => "__ID__"]) }}'.replace('__ID__', modalEditingItemId);
      jsonReq('PATCH', url, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          if (modalLineId) {
            var row = document.querySelector('#rcv-tbody tr[data-line-id="' + modalLineId + '"]');
            if (row) {
              var nameDiv = row.querySelector('td:first-child div:first-child');
              if (nameDiv) nameDiv.textContent = res.body.item.name;
              var skuCode = row.querySelector('td:nth-child(2) code');
              if (skuCode) skuCode.textContent = res.body.item.sku;
            }
          }
          rcvCloseItemModal();
          toastOk('Item saved');
        } else {
          showModalError((res.body && res.body.message) || 'Could not save.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    } else {
      payload.add_as_line = true;
      payload.received_quantity = 1;
      var createUrl = '{{ route("tenant.inventory.receiving.items.quick.create", ["id" => $shipment->id]) }}';
      jsonReq('POST', createUrl, payload).then(function (res) {
        btn.disabled = false; btn.textContent = origLabel;
        if (res.ok && res.body && res.body.ok) {
          if (res.body.line) {
            var newRow = renderRow(res.body.line);
            newLineRow.parentNode.insertBefore(newRow, newLineRow);
            applyTotals(res.body.totals);
          }
          rcvCloseItemModal();
          toastOk('Item created and added to shipment');
        } else {
          showModalError((res.body && res.body.message) || 'Could not create.');
        }
      }).catch(function () {
        btn.disabled = false; btn.textContent = origLabel;
        showModalError('Network error. Try again.');
      });
    }
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('rcv-item-modal').style.display === 'flex') {
      rcvCloseItemModal();
    }
  });
})();
</script>

@endsection
