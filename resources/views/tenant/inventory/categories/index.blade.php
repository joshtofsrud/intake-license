@extends('layouts.tenant.app')
@php $pageTitle = 'Inventory categories'; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Categories</h1>
    <p class="ia-page-subtitle">
      <a href="{{ route('tenant.inventory.index') }}">← Back to inventory</a>
    </p>
  </div>
</div>

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
    <table class="ia-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Slug</th>
          <th>Items</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        @foreach($categories as $cat)
          <tr>
            <td><strong>{{ $cat->name }}</strong></td>
            <td><code style="font-size:13px">{{ $cat->slug }}</code></td>
            <td>{{ $cat->inventoryItems()->where('is_active', true)->count() }}</td>
            <td style="color:var(--ia-text-muted)">{{ $cat->source }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@endsection
