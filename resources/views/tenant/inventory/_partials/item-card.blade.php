@php
  // patch-98 per-location — show current location's count, total as secondary
  $totalStock = (int) $item->computed_stock_count;
  $hereStock  = ($hereStocks ?? null) && array_key_exists($item->id, $hereStocks)
                  ? (int) $hereStocks[$item->id]
                  : $totalStock;
  $stock = $hereStock;
  $threshold = $item->shop_reorder_threshold;
  $isLow = $threshold !== null && $stock > 0 && $stock <= $threshold;
  $isOut = $stock <= 0 && $stock >= 0;
  $isOversold = $stock < 0;

  $stockClass = '';
  if ($isOversold) $stockClass = 'ia-stock--out';
  elseif ($isOut)  $stockClass = 'ia-stock--out';
  elseif ($isLow)  $stockClass = 'ia-stock--low';

  $sellPrice = $item->effectiveSellPriceCents();
  $cost = $item->effectiveCostCents();
@endphp

<tr>
  <td>
    <a href="{{ route('tenant.inventory.show', $item->id) }}" style="font-weight:500;color:inherit;text-decoration:none">
      {{ $item->name }}
    </a>
    @if($item->shop_bin_location)
      <div style="font-size:12px;color:var(--ia-text-muted)">Bin {{ $item->shop_bin_location }}</div>
    @endif
  </td>
  <td><code style="font-size:13px">{{ $item->sku }}</code></td>
  <td>{{ $item->category?->name ?? '—' }}</td>
  <td style="text-align:right" class="{{ $stockClass }}">
    {{ $stock }}
    @if($isOversold)
      <span class="ia-badge ia-badge--red">Oversold</span>
    @elseif($isOut)
      <span class="ia-badge ia-badge--red">Out</span>
    @elseif($isLow)
      <span class="ia-badge ia-badge--amber">Low</span>
    @endif
    @if(($isMultiLocation ?? false) && $totalStock !== $hereStock)
      <div style="font-size:11px;color:var(--ia-text-muted);font-weight:400;margin-top:2px">
        {{ $totalStock }} total
      </div>
    @endif
  </td>
  <td style="text-align:right">
    {{ $sellPrice !== null ? '$' . number_format($sellPrice / 100, 2) : '—' }}
  </td>
  <td style="text-align:right;color:var(--ia-text-muted)">
    {{ $cost !== null ? '$' . number_format($cost / 100, 2) : '—' }}
  </td>
  <td style="text-align:right">
    <a href="{{ route('tenant.inventory.show', $item->id) }}" class="ia-btn ia-btn--ghost ia-btn--small">View</a>
  </td>
</tr>
