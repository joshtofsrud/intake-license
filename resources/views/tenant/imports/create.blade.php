@extends('layouts.tenant.app')
@php $pageTitle = 'New import'; @endphp
{{-- MARKER-IMPORT1 --}}

@section('content')
@include('tenant.imports._styles')

@if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ $errors->first() }}</div>@endif

<div class="ia-page-head">
  <div>
    <h1 class="ia-page-title">New import</h1>
    <p class="ia-page-subtitle">Upload a CSV. Nothing is written until you've seen a preview.</p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.imports.index') }}" class="ia-btn ia-btn--ghost">Cancel</a>
  </div>
</div>

<form method="POST" action="{{ route('tenant.imports.store') }}" enctype="multipart/form-data">
  @csrf
  {{-- MARKER-IMPORT2 — pick what you're importing --}}
  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">What are you importing?</span></div>
    <div class="ia-card-body">
      <label class="imp-radio"><input type="radio" name="type" value="customers" checked>
        <span><b>Customers</b><span>Names, contact details, address, notes, VIP, and the business
          fields — business name, tax exemption, payment terms, PO required. Matched on email.</span></span></label>
      <label class="imp-radio"><input type="radio" name="type" value="inventory">
        <span><b>Inventory</b><span>SKU, name, description, cost and price, reorder points, bin,
          size and colour, plus category, vendor and stock on hand. Matched on SKU.</span></span></label>
    </div>
  </div>

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Your file</span></div>
    <div class="ia-card-body">

      <div class="imp-drop">
        <input type="file" name="file" accept=".csv,.txt" required class="ia-input" style="max-width:420px;margin:0 auto">
        <p style="margin-top:10px">CSV or tab-separated · up to 20&nbsp;MB</p>
      </div>

      <p class="imp-hint" style="margin-top:14px">
        Passwords, SMS consent and Stripe ids can't be imported. Consent has to be evidenced, not assigned.
      </p>
    </div>
  </div>

  <div class="imp-foot">
    <span></span>
    <button type="submit" class="ia-btn ia-btn--primary">Upload and map fields</button>
  </div>
</form>
@endsection
