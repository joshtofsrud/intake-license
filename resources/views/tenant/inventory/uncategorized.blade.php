@extends('layouts.tenant.app')
@php $pageTitle = 'Uncategorized'; @endphp
{{-- MARKER-PATCH-HLC23 --}}

@section('content')
<div style="max-width:1000px">
  <div class="ia-page-head">
    <div class="ia-page-head-left">
      <h1 class="ia-page-title">Inventory</h1>
      <p class="ia-page-subtitle">{{ number_format($total) }} uncategorized {{ Str::plural('item', $total) }}</p>
    </div>
  </div>

  @include('layouts.tenant._inventory-tabs')

  @if(session('flash'))
    <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
  @endif

  {{-- filters --}}
  <form method="GET" action="{{ route('tenant.inventory.uncategorized') }}"
        style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
    <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search name or SKU"
           style="padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text);min-width:180px">
    <select name="brand" style="padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)">
      <option value="">All brands</option>
      @foreach($brandOptions as $b)<option value="{{ $b }}" @selected($filters['brand']===$b)>{{ $b }}</option>@endforeach
    </select>
    <select name="cat" style="padding:7px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)">
      <option value="">All catalog categories</option>
      @foreach($catCategoryOptions as $c)<option value="{{ $c }}" @selected($filters['cat']===$c)>{{ $c }}</option>@endforeach
    </select>
    <button class="ia-btn ia-btn--primary" type="submit">Filter</button>
    @if($filters['brand'] || $filters['cat'] || $filters['q'])
      <a class="ia-btn" href="{{ route('tenant.inventory.uncategorized') }}">Clear</a>
    @endif
  </form>

  @if($items->isEmpty())
    <div class="ia-card" style="text-align:center;padding:42px 20px;color:var(--ia-text-dim)">
      <div style="font-size:30px;margin-bottom:8px">✓</div>
      Nothing uncategorized in this view.
    </div>
  @else
    @if($categories->isEmpty())
      <div class="ia-flash ia-flash--info">Create a category first to assign items.
        <a href="{{ route('tenant.inventory.categories.index') }}">Manage categories →</a></div>
    @endif
    <form method="POST" action="{{ route('tenant.inventory.uncategorized.assign') }}">
      @csrf
      <input type="hidden" name="f_brand" value="{{ $filters['brand'] ?? '' }}">
      <input type="hidden" name="f_cat" value="{{ $filters['cat'] ?? '' }}">
      <input type="hidden" name="f_q" value="{{ $filters['q'] ?? '' }}">

      <div class="ia-card" style="padding:6px 14px">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="text-align:left;color:var(--ia-text-dim)">
            <th style="width:28px;padding:8px 10px"><input type="checkbox"
              onclick="document.querySelectorAll('.uc-cb').forEach(c=>c.checked=this.checked)"></th>
            <th style="padding:8px 10px">Item</th>
            <th style="padding:8px 10px">Brand</th>
            <th style="padding:8px 10px">Catalog category</th>
          </tr></thead>
          <tbody>
          @foreach($items as $it)
            <tr style="border-top:.5px solid var(--ia-border)">
              <td style="padding:10px"><input class="uc-cb" type="checkbox" name="item_ids[]" value="{{ $it->id }}"></td>
              <td style="padding:10px"><div style="font-weight:600">{{ $it->name }}</div>
                <div style="font-size:11px;color:var(--ia-text-dim);font-family:var(--ia-mono)">{{ $it->sku }}</div></td>
              <td style="padding:10px">{{ $it->distributorCatalog->manufacturer ?? '—' }}</td>
              <td style="padding:10px;color:var(--ia-text-dim)">{{ $it->distributorCatalog->category ?? '—' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>

      <div style="position:sticky;bottom:0;background:var(--ia-surface);border-top:1px solid var(--ia-border);padding:14px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px">
        <span style="font-size:12px;color:var(--ia-text-dim)">Assign to:</span>
        <select name="category_id" required
                style="padding:8px 10px;border-radius:var(--ia-r-md);font-size:13px;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)">
          <option value="">Choose category…</option>
          @foreach($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </select>
        <button class="ia-btn ia-btn--primary" type="submit">Assign selected</button>
        <label style="font-size:12px;color:var(--ia-text-dim);margin-left:auto;cursor:pointer">
          <input type="checkbox" name="select_all" value="1"> apply to all {{ number_format($total) }} matching the filter
        </label>
      </div>
    </form>
    @if($items->count() >= 500)
      <p style="font-size:12px;color:var(--ia-text-dim);margin-top:10px">Showing first 500. Use filters or "apply to all matching" to cover the rest.</p>
    @endif
  @endif
</div>
@endsection
