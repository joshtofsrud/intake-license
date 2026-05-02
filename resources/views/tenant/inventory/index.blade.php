@extends('layouts.tenant.app')
@php
  $pageTitle = 'Inventory';
  $sortLabels = [
    'name_asc'   => 'Name A–Z',
    'name_desc'  => 'Name Z–A',
    'sku_asc'    => 'SKU A–Z',
    'sku_desc'   => 'SKU Z–A',
    'stock_asc'  => 'Stock low → high',
    'stock_desc' => 'Stock high → low',
  ];
  $stockLabels = [
    ''     => 'All stock levels',
    'low'  => 'Low stock only',
    'out'  => 'Out of stock only',
  ];
@endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ number_format($total) }} {{ Str::plural('item', $total) }}</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.inventory.receiving.index') }}" class="ia-btn">Receiving ↓</a>
    @if($hasCategories)
      <a href="{{ route('tenant.inventory.create') }}" class="ia-btn ia-btn--primary">+ New item</a>
    @else
      <a href="{{ route('tenant.inventory.categories.index') }}" class="ia-btn ia-btn--primary">Set up categories</a>
    @endif
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

@if(!$hasCategories)
  <div class="ia-card" style="border-left: 4px solid var(--ia-accent); margin-bottom: 20px">
    <div class="ia-card-body">
      <strong>Get started:</strong> Create at least one category before adding items. Categories help you organize and filter your inventory — Drivetrain, Tubes, Lubes, Tools, etc.
    </div>
  </div>
@else

<form method="get" action="{{ route('tenant.inventory.index') }}" class="ia-toolbar">
  <input type="search" name="s" class="ia-input" value="{{ $search }}"
    placeholder="Search name, SKU, or UPC…" style="max-width:300px">

  <select name="category" class="ia-input" style="width:auto">
    <option value="">All categories</option>
    @foreach($categories as $cat)
      <option value="{{ $cat->id }}" @selected($category === $cat->id)>{{ $cat->name }}</option>
    @endforeach
  </select>

  <select name="stock" class="ia-input" style="width:auto">
    @foreach($stockLabels as $val => $label)
      <option value="{{ $val }}" @selected($stock === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <select name="sort" class="ia-input" style="width:auto">
    @foreach($sortLabels as $val => $label)
      <option value="{{ $val }}" @selected($sort === $val)>{{ $label }}</option>
    @endforeach
  </select>

  <button type="submit" class="ia-btn ia-btn--secondary">Filter</button>
  @if($search || $category || $stock || $sort !== 'name_asc')
    <a href="{{ route('tenant.inventory.index') }}" class="ia-btn ia-btn--ghost">Reset</a>
  @endif
</form>

<div class="ia-card">
  @if($items->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No items match your filters.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>SKU</th>
          <th>Category</th>
          <th style="text-align:right">Stock</th>
          <th style="text-align:right">Price</th>
          <th style="text-align:right">Cost</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($items as $item)
          @include('tenant.inventory._partials.item-card', ['item' => $item])
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>

@if($total > $perPage)
  <div class="ia-pagination">
    @php
      $pages = (int) ceil($total / $perPage);
      $qs = function($p) use ($search, $category, $stock, $sort) {
        return http_build_query(array_filter([
          's' => $search, 'category' => $category, 'stock' => $stock,
          'sort' => $sort, 'page' => $p,
        ]));
      };
    @endphp
    @if($page > 1)
      <a href="?{{ $qs($page - 1) }}" class="ia-btn ia-btn--ghost">← Prev</a>
    @endif
    <span class="ia-pagination-info">Page {{ $page }} of {{ $pages }}</span>
    @if($page < $pages)
      <a href="?{{ $qs($page + 1) }}" class="ia-btn ia-btn--ghost">Next →</a>
    @endif
  </div>
@endif

@endif

@endsection
