{{-- MARKER-IMPORT-VENDOR-MODAL — the ONE vendor form. Rendered by the vendors
     page and by the import map's create-vendor modal. Nesting corrected from
     the original inline form, where the discount and feed groups sat inside
     the freight group. --}}
<div class="ia-input-grid-2">
  <div class="ia-form-group">
    <label class="ia-form-label">Vendor name <span class="ia-required">*</span></label>
    <input type="text" name="name" class="ia-input" required value="{{ old('name') }}"
           placeholder="e.g. QBP, Hawley, Amazon Business">
  </div>
  <div class="ia-form-group">
    <label class="ia-form-label">Account number</label>
    <input type="text" name="account_number" class="ia-input" value="{{ old('account_number') }}"
           placeholder="Your account # with this vendor">
  </div>
  {{-- MARKER-SO-PLACEMENT --}}
  <div class="ia-form-group">
    <label class="ia-form-label">Free freight over</label>
    <input type="number" step="0.01" min="0" name="free_freight" class="ia-input"
           placeholder="e.g. 500.00" value="{{ old('free_freight') }}">
  </div>
  {{-- MARKER-VENDOR-NET-COST --}}
  <div class="ia-form-group">
    <label class="ia-form-label">Program discount %</label>
    <input type="number" step="0.01" min="0" max="100" name="program_discount_pct" class="ia-input"
           placeholder="e.g. 5" value="{{ old('program_discount_pct') }}">
    <div class="ia-form-hint">Flat percentage off this vendor's cost, used when auto-assigning by lowest price. Leave blank if there's no program.</div>
  </div>
  <div class="ia-form-group">
    <label class="ia-form-label">Catalog feed</label>
    <select name="distributor_code" class="ia-input">
      <option value="">Not a catalog distributor</option>
      @foreach(array_keys((array) config('distributors', [])) as $dcode)
        <option value="{{ $dcode }}" @selected(old('distributor_code') === $dcode)>{{ strtoupper($dcode) }}</option>
      @endforeach
    </select>
    <div class="ia-form-hint">Links this vendor to its catalog import so imported items attach here. One vendor per feed.</div>
  </div>
  <div class="ia-form-group">
    <label class="ia-form-label">Website</label>
    <input type="text" name="website" class="ia-input" value="{{ old('website') }}" placeholder="vendor.com">
  </div>
</div>
<div class="ia-input-grid-2">
  <div class="ia-form-group">
    <label class="ia-form-label">Contact email</label>
    <input type="email" name="contact_email" class="ia-input" value="{{ old('contact_email') }}">
  </div>
  <div class="ia-form-group">
    <label class="ia-form-label">Contact phone</label>
    <input type="tel" name="contact_phone" class="ia-input" value="{{ old('contact_phone') }}">
  </div>
</div>
<div class="ia-form-group">
  <label class="ia-form-label">Notes</label>
  <textarea name="notes" class="ia-input" rows="2"
            placeholder="Daily cutoff times, rep names, ordering quirks…">{{ old('notes') }}</textarea>
</div>
