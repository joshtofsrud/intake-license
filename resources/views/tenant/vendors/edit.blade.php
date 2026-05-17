@extends('layouts.tenant.app')
@php $pageTitle = 'Edit ' . $vendor->name; @endphp
@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div class="ia-text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:4px">
      <a href="{{ route('tenant.vendors.show', ['subdomain' => tenant()->subdomain, 'id' => $vendor->id]) }}" style="color:inherit;text-decoration:none">← {{ $vendor->name }}</a>
    </div>
    <h1 class="ia-page-title">Edit vendor</h1>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

@if($errors->any())
  <div class="ia-flash ia-flash--error">
    {{ $errors->first() }}
  </div>
@endif

<div class="ia-card">
  <form method="POST" action="{{ route('tenant.vendors.update', ['subdomain' => tenant()->subdomain, 'id' => $vendor->id]) }}">
    @csrf
    @method('PATCH')

    <div class="ia-card-body">
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Vendor name <span class="ia-required">*</span></label>
          <input type="text" name="name" class="ia-input" required
                 value="{{ old('name', $vendor->name) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Account number</label>
          <input type="text" name="account_number" class="ia-input"
                 value="{{ old('account_number', $vendor->account_number) }}">
        </div>
      </div>

      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Contact email</label>
          <input type="email" name="contact_email" class="ia-input"
                 value="{{ old('contact_email', $vendor->contact_email) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Contact phone</label>
          <input type="tel" name="contact_phone" class="ia-input"
                 value="{{ old('contact_phone', $vendor->contact_phone) }}">
        </div>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Website</label>
        <input type="text" name="website" class="ia-input"
               value="{{ old('website', $vendor->website) }}">
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label">Notes</label>
        <textarea name="notes" class="ia-input" rows="4"
                  placeholder="Daily cutoff times, rep names, ordering quirks, anything staff should know">{{ old('notes', $vendor->notes) }}</textarea>
      </div>

      <div class="ia-form-group">
        <label class="ia-form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="is_active" value="1"
                 {{ old('is_active', $vendor->is_active) ? 'checked' : '' }}
                 style="width:auto;margin:0">
          Active
        </label>
        <div class="ia-form-help" style="font-size:11.5px;color:var(--ia-text-muted);margin-top:4px">
          Inactive vendors don't appear in vendor pickers when creating new SOs. Existing data still references them normally.
        </div>
      </div>
    </div>

    <div class="ia-card-foot" style="display:flex;gap:8px;justify-content:flex-end">
      <a href="{{ route('tenant.vendors.show', ['subdomain' => tenant()->subdomain, 'id' => $vendor->id]) }}"
         class="ia-btn ia-btn--ghost">Cancel</a>
      <button type="submit" class="ia-btn ia-btn--primary">Save changes</button>
    </div>
  </form>
</div>

@endsection
