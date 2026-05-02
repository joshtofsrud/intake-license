@php
  $stock = (int) $item->computed_stock_count;
  $threshold = $item->shop_reorder_threshold;
  $isLow = $threshold !== null && $stock <= $threshold;
  $isOut = $stock <= 0;

  $stockClass = '';
  if ($isOut) $stockClass = 'ia-stock--out';
  elseif ($isLow) $stockClass = 'ia-stock--low';

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
    @if($isOut)
      <span class="ia-badge ia-badge--red">Out</span>
    @elseif($isLow)
      <span class="ia-badge ia-badge--amber">Low</span>
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
