@extends('layouts.tenant.app')
@php $pageTitle = 'Inventory categories'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inventory</h1>
    <p class="ia-page-subtitle">{{ $categories->count() }} {{ \Illuminate\Support\Str::plural('category', $categories->count()) }}</p>
  </div>
</div>

@include('layouts.tenant._inventory-tabs')

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

@if($errors->any())
  <div class="ia-flash ia-flash--error">
    @foreach($errors->all() as $error){{ $error }}<br>@endforeach
  </div>
@endif

<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head"><span class="ia-card-title">Add a category</span></div>
  <form method="POST" action="{{ route('tenant.inventory.categories.store') }}">
    @csrf
    <div class="ia-card-body">
      <div class="ia-form-group">
        <label class="ia-form-label">Name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" required value="{{ old('name') }}"
          placeholder="e.g. Drivetrain, Tubes, Lubes, Tools" style="max-width:400px">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Parent category</label>
        <select name="parent_id" class="ia-input" style="max-width:400px">
          <option value="">— None (top level) —</option>
          @foreach($tree as $o)<option value="{{ $o['id'] }}">{{ str_repeat('— ', $o['depth']) }}{{ $o['name'] }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="ia-btn ia-btn--primary">Add category</button>
    </div>
  </form>
</div>

<div class="ia-card">
  <div class="ia-card-head">
    <span class="ia-card-title">Your categories</span>
    <span style="font-size:12px;color:var(--ia-text-muted);margin-left:8px">{{ $categories->count() }} total</span>
  </div>
  @if($categories->isEmpty())
    <div class="ia-card-body" style="text-align:center;padding:40px 20px;color:var(--ia-text-muted)">
      No categories yet. Add your first one above.
    </div>
  @else
<div class="ia-table-wrap">
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Parent (move)</th>
          <th>Items</th>
        </tr>
      </thead>
      <tbody>
        @foreach($tree as $node)
          <tr>
            <td style="padding-left:{{ 12 + $node['depth'] * 22 }}px">@if($node['depth'] > 0)<span style="color:var(--ia-text-muted)">└&nbsp;</span>@endif<a href="{{ route('tenant.inventory.index', ['category' => $node['id']]) }}" style="color:var(--ia-text);text-decoration:none" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><strong>{{ $node['name'] }}</strong></a>{{-- MARKER-PATCH-HLC28-NAME --}}</td>
            <td>
              <form method="POST" action="{{ route('tenant.inventory.categories.reparent', $node['id']) }}" style="margin:0">
                @csrf
                @method('PATCH')
                <select name="parent_id" onchange="this.form.submit()" class="ia-input" style="max-width:260px;font-size:13px;padding:5px 8px">
                  <option value="">— top level —</option>
                  @foreach($tree as $o)@if($o['id'] !== $node['id'])<option value="{{ $o['id'] }}" @selected($node['parent_id'] === $o['id'])>{{ str_repeat('— ', $o['depth']) }}{{ $o['name'] }}</option>@endif @endforeach
                </select>
              </form>
            </td>
            <td>@if($node['count'] > 0)<a href="{{ route('tenant.inventory.index', ['category' => $node['id']]) }}" style="color:var(--ia-accent);text-decoration:none;font-weight:600" title="View these items">{{ $node['count'] }}</a>@else<span style="color:var(--ia-text-muted)">0</span>@endif{{-- MARKER-PATCH-HLC28-COUNT --}}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
</div>
  @endif
</div>

@endsection
