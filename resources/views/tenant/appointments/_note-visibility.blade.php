{{-- MARKER-NOTE-VISIBILITY: who can see a note, as a toggle on the note
     itself so it can be changed after the note is written. Expects $note;
     system (activity) notes get a fixed "Staff only" label. --}}
@if($note->note_type === 'system')
  <span class="nv-pill" title="Activity notes are never shown to the customer">Staff only</span>
@else
  <button type="button" class="nv-pill nv-toggle {{ $note->is_customer_visible ? 'is-on' : '' }}"
          data-note-vis="{{ $note->id }}" data-on="{{ $note->is_customer_visible ? '1' : '0' }}"
          title="Click to change who can see this note">{{ $note->is_customer_visible ? 'Customer can see' : 'Staff only' }}</button>
@endif
