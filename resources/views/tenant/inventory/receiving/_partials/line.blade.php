@php
  $isUnexpected = str_starts_with($line->status, 'unexpected_');
@endphp
<tr data-line-id="{{ $line->id }}" data-status="{{ $line->status }}"
    @if($isUnexpected) style="background:rgba(244,180,0,.06)" @endif>
  <td>
    <div style="font-weight:500">{{ $line->name }}</div>
    @if($line->item?->category?->name)
      <div style="font-size:11px;color:var(--ia-text-muted);margin-top:1px">{{ $line->item->category->name }}</div>
    @elseif($isUnexpected)
      <div style="font-size:11px;color:#f4b400;margin-top:1px">Unexpected · not on PO</div>
    @endif
  </td>
  <td><code style="font-size:11.5px;color:var(--ia-accent)">{{ $line->sku }}</code></td>
  <td style="text-align:right;font-variant-numeric:tabular-nums">
    @if($isUnexpected)
      <span style="color:var(--ia-text-muted)">—</span>
    @else
      <input class="ia-input rcv-cell" data-field="expected_quantity" type="number" min="0" max="99999"
             value="{{ $line->expected_quantity }}" style="width:70px;padding:3px 6px;text-align:right">
    @endif
  </td>
  <td style="text-align:right">
    <input class="ia-input rcv-cell" data-field="received_quantity" type="number" min="0" max="99999"
           value="{{ $line->received_quantity }}" style="width:70px;padding:3px 6px;text-align:right">
  </td>
  <td>
    <select class="ia-input rcv-cell" data-field="status" style="padding:3px 6px;font-size:12px">
      @foreach($statusOptions as $val => $label)
        <option value="{{ $val }}" @selected($line->status === $val)>{{ $label }}</option>
      @endforeach
    </select>
  </td>
  <td style="text-align:right">
    <input class="ia-input rcv-cell" data-field="unit_cost_dollars" type="text" inputmode="decimal"
           value="{{ $line->unit_cost_cents !== null ? number_format($line->unit_cost_cents / 100, 2, '.', '') : '' }}"
           style="width:80px;padding:3px 6px;text-align:right" placeholder="0.00">
  </td>
  <td style="text-align:right">
    <button type="button" class="ia-btn ia-btn--ghost"
            onclick="rcvRemoveLine('{{ $line->id }}')"
            style="padding:2px 8px;color:var(--ia-text-muted)" title="Remove">×</button>
  </td>
</tr>
