{{-- MARKER-INV-EMPTY — shared by the desktop card and the mobile list.
     $emptyVariant is 'desk' or 'mobile' and only affects sizing. --}}
@php
  $pad = $emptyVariant === 'mobile' ? '32px 18px' : '40px 20px';

  // Link builder: start from what is set now, override, always reset page.
  $emptyLink = function (array $over) use ($search, $category, $brand, $distributor, $stock, $sort) {
      $base = [
          's'           => $search,
          'category'    => $category,
          'brand'       => $brand,
          'distributor' => $distributor,
          'stock'       => $stock,
          'sort'        => $sort === 'name_asc' ? null : $sort,
      ];
      $merged = array_merge($base, $over);

      return route('tenant.inventory.index', array_filter(
          $merged,
          fn ($v) => $v !== null && $v !== ''
      ));
  };
@endphp

<div style="padding:{{ $pad }};text-align:center;color:var(--ia-text-muted);font-size:13px">

  @if($emptyReason === 'search')
    <div style="margin-bottom:10px">
      Nothing matches <strong style="color:var(--ia-text)">{{ $search }}</strong>.
    </div>
    <a href="{{ $emptyLink(['s' => null]) }}" style="text-decoration:underline">Clear the search</a>

  @elseif($emptyReason === 'stock')
    <div style="margin-bottom:10px">
      Nothing here at this stock level.
    </div>
    <a href="{{ $emptyLink(['stock' => null]) }}" style="text-decoration:underline">Show every stock level</a>

  @else
    <div style="margin-bottom:4px;color:var(--ia-text)">
      No items match those filters together.
    </div>

    @if(!empty($suggestBrands))
      <div style="margin:14px 0 8px">Brands with items in this category:</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:center;max-width:640px;margin:0 auto">
        @foreach($suggestBrands as $b)
          <a href="{{ $emptyLink(['brand' => $b['name'], 'stock' => null]) }}"
             style="display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border:0.5px solid var(--ia-border);border-radius:999px;text-decoration:none;color:var(--ia-text);font-size:12px">
            {{ $b['name'] }}
            <span style="color:var(--ia-text-muted)">{{ number_format($b['count']) }}</span>
          </a>
        @endforeach
      </div>
    @endif

    @if(!empty($suggestCategories))
      <div style="margin:14px 0 8px">
        <strong style="color:var(--ia-text)">{{ $brand }}</strong> appears in these categories:
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:center;max-width:640px;margin:0 auto">
        @foreach($suggestCategories as $c)
          <a href="{{ $emptyLink(['category' => $c['id'], 'stock' => null]) }}"
             style="display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border:0.5px solid var(--ia-border);border-radius:999px;text-decoration:none;color:var(--ia-text);font-size:12px">
            {{ $c['name'] }}
            <span style="color:var(--ia-text-muted)">{{ number_format($c['count']) }}</span>
          </a>
        @endforeach
      </div>
    @endif

    @if(!empty($suggestBrands) || !empty($suggestCategories))
      {{-- MARKER-INV-EMPTY — legend: these counts are across ALL stock levels
           and the links clear the stock filter. Without saying so, a count of
           42 next to a page showing 0 reads as a bug. --}}
      <div style="margin-top:12px;font-size:11px;color:var(--ia-text-dim)">
        Counts cover every stock level; choosing one clears your stock filter.
      </div>
    @endif

    <div style="margin-top:16px">
      <a href="{{ route('tenant.inventory.index') }}" style="text-decoration:underline">Clear all filters</a>
    </div>
  @endif

</div>
