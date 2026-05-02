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

<h2 style="font-size:15px;margin:0 0 8px 0">Line items</h2>

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
    <tr id="rcv-newline" data-newline="1">
      <td><span style="color:var(--ia-text-muted);font-size:12px">Type or scan to add a line…</span></td>
      <td>
        <input type="text" class="ia-input" id="rcv-newline-sku" autocomplete="off"
               placeholder="SKU or UPC" style="padding:3px 6px;font-size:12.5px;width:100%">
      </td>
      <td style="text-align:right;color:var(--ia-text-muted)">—</td>
      <td style="text-align:right;color:var(--ia-text-muted)">—</td>
      <td><span style="color:var(--ia-text-muted);font-size:11px">auto</span></td>
      <td style="text-align:right;color:var(--ia-text-muted)">—</td>
      <td></td>
    </tr>
  </tbody>
</table>

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
})();
</script>

@endsection
