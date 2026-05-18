@php
  $totalStock = (int) $item->computed_stock_count;
  $hereStock  = ($hereStocks ?? null) && array_key_exists($item->id, $hereStocks)
                  ? (int) $hereStocks[$item->id]
                  : $totalStock;
  $stock = $hereStock;
  $threshold = $item->shop_reorder_threshold;
  $isOversold = $stock < 0;
  $isOut = $stock === 0;
  $isLow = !$isOut && !$isOversold && $threshold !== null && $stock <= $threshold;

  $barColor = 'transparent';
  $statusCopy = null;
  if ($isOversold) {
    $barColor = '#E24B4A';
    $statusCopy = 'Oversold';
  } elseif ($isOut) {
    $barColor = '#EF9F27';
    $statusCopy = 'Out';
  } elseif ($isLow) {
    $barColor = '#EF9F27';
    $statusCopy = 'Low';
  }

  $stockColor = $isOversold ? '#E24B4A' : ($isOut || $isLow ? '#BA7517' : 'inherit');
  $detailUrl = route('tenant.inventory.show', $item->id);
  $sellPrice = $item->effectiveSellPriceCents();
  $cost = $item->effectiveCostCents();
  $isMulti = $isMultiLocation ?? false;
@endphp

<tr class="inv-row" onclick="window.location='{{ $detailUrl }}'" style="cursor:pointer">
  <td class="inv-row-bar" style="width:4px;padding:0;background:{{ $barColor }};border-radius:0"></td>

  <td class="inv-row-identity">
    <div class="inv-row-name">{{ $item->name }}</div>
    <div class="inv-row-meta">
      <code class="inv-row-sku">{{ $item->sku }}</code>
      @if($item->category)
        <span class="inv-row-pill">{{ $item->category->name }}</span>
      @endif
      @if($item->shop_bin_location)
        <span class="inv-row-bin">Bin {{ $item->shop_bin_location }}</span>
      @endif
    </div>
  </td>

  <td class="inv-row-upc">
    @if($item->catalog_upc)
      <code>{{ $item->catalog_upc }}</code>
    @else
      <span class="inv-row-dash">—</span>
    @endif
  </td>

  <td class="inv-row-color">
    {{ $item->color ?? '—' }}
  </td>

  <td class="inv-row-size">
    {{ $item->size ?? '—' }}
  </td>

  <td class="inv-row-stock">
    <div class="inv-row-stock-num" style="color:{{ $stockColor }}">{{ $stock }}</div>
    @if($statusCopy || ($isMulti && $totalStock !== $hereStock))
      <div class="inv-row-stock-meta">
        @if($statusCopy) {{ $statusCopy }} @endif
        @if($statusCopy && $isMulti && $totalStock !== $hereStock) · @endif
        @if($isMulti && $totalStock !== $hereStock) {{ $totalStock }} total @endif
      </div>
    @endif
  </td>

  <td class="inv-row-price">
    {{ $sellPrice !== null ? '$' . number_format($sellPrice / 100, 2) : '—' }}
  </td>

  <td class="inv-row-cost">
    {{ $cost !== null ? '$' . number_format($cost / 100, 2) : '—' }}
  </td>
</tr>
