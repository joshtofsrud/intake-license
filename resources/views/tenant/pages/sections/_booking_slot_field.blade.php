{{-- MARKER-PATCH-602 — booking marketing slot picker. Only rendered when this
     section lives on the hidden __booking_extras page. Saves to content.booking_slot
     via the standard [data-field] autosave; the booking shell reads it to place
     the section above or below the form. --}}
@if($isBookingExtras ?? false)
  @php $__slot = ($section->content['booking_slot'] ?? 'before'); @endphp
  <div class="pb-field-row">
    <label class="pb-field-label">Placement on booking page</label>
    <select class="pb-input" data-field="booking_slot">
      <option value="before" {{ $__slot === 'before' ? 'selected' : '' }}>Above the booking form</option>
      <option value="after"  {{ $__slot === 'after'  ? 'selected' : '' }}>Below the booking form</option>
    </select>
  </div>
@endif

