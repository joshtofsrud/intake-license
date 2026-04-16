@extends('layouts.tenant.app')
@php
  $pageTitle = $appointment->ra_number;
  $statusLabels = [
    'pending'     => 'Pending',
    'confirmed'   => 'Confirmed',
    'in_progress' => 'In progress',
    'completed'   => 'Completed',
    'shipped'     => 'Shipped',
    'closed'      => 'Closed',
    'cancelled'   => 'Cancelled',
    'refunded'    => 'Refunded',
  ];
  $transitionLabels = [
    'confirmed'   => 'Confirm',
    'in_progress' => 'Start work',
    'completed'   => 'Mark completed',
    'shipped'     => 'Mark shipped',
    'closed'      => 'Close job',
    'cancelled'   => 'Cancel',
    'refunded'    => 'Refund',
  ];
  $updateUrl = route('tenant.appointments.update', $appointment->id);
@endphp

<style>
.appt-layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }
.appt-section-label { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; font-weight: 500; opacity: .45; margin-bottom: 10px; }
.appt-line-items { width: 100%; border-collapse: collapse; font-size: 13px; }
.appt-line-items th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 500; opacity: .45; padding: 6px 0; text-align: left; border-bottom: 0.5px solid var(--ia-border); }
.appt-line-items td { padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); vertical-align: middle; }
.appt-line-items tr:last-child td { border-bottom: none; }
.appt-line-items .ia-num { text-align: right; font-variant-numeric: tabular-nums; }
.appt-total-row { display: flex; justify-content: space-between; padding: 10px 0; border-top: 0.5px solid var(--ia-border); font-weight: 500; }
.appt-response { display: flex; flex-direction: column; gap: 2px; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); }
.appt-response:last-child { border-bottom: none; }
.appt-response-label { font-size: 11px; opacity: .45; }
.appt-response-value { font-size: 13px; }
.appt-charge-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.appt-charge-row:last-child { border-bottom: none; }
.sidebar-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.sidebar-stat:last-child { border-bottom: none; }
.sidebar-stat-label { opacity: .5; }
.sidebar-stat-value { font-weight: 500; }
.add-charge-form { display: none; margin-top: 12px; padding-top: 12px; border-top: 0.5px solid var(--ia-border); }
.add-charge-form.open { display: block; }
@media (max-width: 900px) { .appt-layout { grid-template-columns: 1fr; } }
</style>

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">
      Work order
    </div>
    <h1 class="ia-page-title" style="display:flex;align-items:center;gap:12px">
      {{ $appointment->ra_number }}
      <span class="ia-badge ia-badge--{{ str_replace('_','-',$appointment->status) }}" style="font-size:12px">
        {{ $statusLabels[$appointment->status] ?? $appointment->status }}
      </span>
    </h1>
    <p class="ia-page-subtitle">
      {{ $appointment->customerName() }} ·
      {{ $appointment->appointment_date->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">← Back</a>

    @foreach($transitions as $toStatus)
      @php $isDestructive = in_array($toStatus, $destructive); @endphp
      <form method="POST" action="{{ $updateUrl }}" style="display:inline">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="status">
        <input type="hidden" name="status" value="{{ $toStatus }}">
        <button type="submit"
          class="ia-btn {{ $isDestructive ? 'ia-btn--danger' : 'ia-btn--secondary' }}"
          @if($isDestructive) data-confirm="Are you sure you want to {{ $transitionLabels[$toStatus] ?? $toStatus }} this job?" @endif>
          {{ $transitionLabels[$toStatus] ?? ucfirst($toStatus) }}
        </button>
      </form>
    @endforeach
  </div>
</div>

<div class="appt-layout">

  <div style="display:flex;flex-direction:column;gap:20px">

    {{-- Line items --}}
    <div class="ia-card">
      <div class="appt-section-label">Services</div>

      @if($appointment->items->isEmpty())
        <p style="font-size:13px;opacity:.4">No items on this appointment.</p>
      @else
        <table class="appt-line-items">
          <thead>
            <tr>
              <th>Item</th>
              <th>Tier</th>
              <th class="ia-num">Price</th>
            </tr>
          </thead>
          <tbody>
            @foreach($appointment->items as $item)
              <tr>
                <td style="font-weight:500">{{ $item->item_name_snapshot }}</td>
                <td style="opacity:.6">{{ $item->tier_name_snapshot }}</td>
                <td class="ia-num">{{ format_money($item->price_cents) }}</td>
              </tr>
            @endforeach
            @foreach($appointment->addons as $addon)
              <tr>
                <td style="opacity:.7">{{ $addon->addon_name_snapshot }}</td>
                <td style="opacity:.4;font-size:12px">Add-on</td>
                <td class="ia-num">{{ format_money($addon->price_cents) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <div class="appt-total-row">
          <span>Subtotal</span>
          <span>{{ format_money($appointment->subtotal_cents) }}</span>
        </div>
      @endif
    </div>

    {{-- Form responses --}}
    @if($appointment->responses->isNotEmpty())
    <div class="ia-card">
      <div class="appt-section-label">Customer details</div>
      @foreach($appointment->responses as $r)
        <div class="appt-response">
          <div class="appt-response-label">{{ $r->field_label_snapshot }}</div>
          <div class="appt-response-value">{{ $r->response_value ?: '—' }}</div>
        </div>
      @endforeach
    </div>
    @endif

    {{-- Charges --}}
    <div class="ia-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div class="appt-section-label" style="margin-bottom:0">Additional charges</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-toggle">
          + Add charge
        </button>
      </div>

      <form method="POST" action="{{ $updateUrl }}" class="add-charge-form" id="add-charge-form">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="add_charge">
        <div class="ia-input-grid-2" style="margin-bottom:10px">
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Description <span class="ia-required">*</span></label>
            <input type="text" name="description" class="ia-input" placeholder="e.g. New brake cable" required>
          </div>
          <div class="ia-form-group" style="margin-bottom:0">
            <label class="ia-form-label">Amount ($) <span class="ia-required">*</span></label>
            <input type="number" name="amount_display" class="ia-input" placeholder="25.00"
              step="0.01" min="0.01" id="charge-amount-display">
            <input type="hidden" name="amount_cents" id="charge-amount-cents">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save charge</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="add-charge-cancel">Cancel</button>
        </div>
      </form>

      @if($appointment->charges->isEmpty())
        <p style="font-size:13px;opacity:.4">No additional charges.</p>
      @else
        @foreach($appointment->charges as $charge)
          <div class="appt-charge-row">
            <div>
              <div style="font-size:13px">{{ $charge->description }}</div>
              <div style="font-size:11px;opacity:.4">
                {{ \Carbon\Carbon::parse($charge->created_at)->format('M j') }} ·
                {{ $charge->is_paid ? 'Paid' : 'Unpaid' }}
              </div>
            </div>
            <div style="font-weight:500">{{ format_money($charge->amount_cents) }}</div>
          </div>
        @endforeach

        <div class="appt-total-row">
          <span>Charges total</span>
          <span>{{ format_money($appointment->charges->sum('amount_cents')) }}</span>
        </div>
      @endif
    </div>

  </div>

  <div style="display:flex;flex-direction:column;gap:16px">

    {{-- Customer --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Customer</div>
      <div style="font-weight:500;margin-bottom:4px">
        {{ $appointment->customerName() }}
      </div>
      <div style="font-size:13px;opacity:.6;margin-bottom:2px">
        {{ $appointment->customer_email }}
      </div>
      @if($appointment->customer_phone)
        <div style="font-size:13px;opacity:.6;margin-bottom:10px">
          {{ $appointment->customer_phone }}
        </div>
      @else
        <div style="margin-bottom:10px"></div>
      @endif
      @if($appointment->customer_id)
        <a href="{{ route('tenant.customers.show', $appointment->customer_id) }}"
           class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%;justify-content:center">
          View customer profile →
        </a>
      @endif
    </div>

    {{-- Slot weight --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Capacity slots
      </div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Auto-calculated</span>
        <span class="sidebar-stat-value">{{ $appointment->slot_weight_auto ?? 1 }}</span>
      </div>
      @if($appointment->slot_weight_overridden)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label" style="color:#EF9F27">Overridden by staff</span>
        <span class="sidebar-stat-value" style="color:#EF9F27">{{ $appointment->slot_weight }}</span>
      </div>
      @endif
      <form method="POST" action="{{ $updateUrl }}" style="margin-top:12px">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="slot_weight">
        <label class="ia-form-label">Override slot weight</label>
        <select name="slot_weight" class="ia-input" style="margin-bottom:8px">
          @foreach([1,2,3,4] as $w)
            <option value="{{ $w }}" @selected($appointment->slot_weight == $w)>
              {{ $w }} slot{{ $w > 1 ? 's' : '' }}
              @if($w == 1) — normal job
              @elseif($w == 2) — bigger job
              @elseif($w == 3) — large job
              @elseif($w == 4) — full day job
              @endif
            </option>
          @endforeach
        </select>
        <p style="font-size:11px;opacity:.4;margin-bottom:8px">
          This controls how many capacity slots this job uses when checking daily availability.
        </p>
        <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%">
          Update slots
        </button>
      </form>
    </div>

    {{-- Payment --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Payment</div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Status</span>
        <span>
          <span class="ia-badge ia-badge--{{ $appointment->payment_status }}">
            {{ ucfirst($appointment->payment_status) }}
          </span>
        </span>
      </div>
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Subtotal</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->subtotal_cents) }}</span>
      </div>
      @if($appointment->tax_cents > 0)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Tax</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->tax_cents) }}</span>
      </div>
      @endif
      @if($appointment->charges->sum('amount_cents') > 0)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Charges</span>
        <span class="sidebar-stat-value">{{ format_money($appointment->charges->sum('amount_cents')) }}</span>
      </div>
      @endif
      <div class="sidebar-stat">
        <span class="sidebar-stat-label" style="font-weight:500">Total</span>
        <span class="sidebar-stat-value" style="font-size:16px">{{ format_money($appointment->total_cents) }}</span>
      </div>
      @if($appointment->paid_cents > 0)
      <div class="sidebar-stat">
        <span class="sidebar-stat-label">Paid</span>
        <span class="sidebar-stat-value" style="color:#3B6D11">{{ format_money($appointment->paid_cents) }}</span>
      </div>
      @endif

      <form method="POST" action="{{ $updateUrl }}" style="margin-top:12px">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="payment">
        <select name="payment_status" class="ia-input ia-input--sm" style="margin-bottom:8px">
          @foreach(['unpaid','partial','paid','refunded'] as $ps)
            <option value="{{ $ps }}" @selected($appointment->payment_status === $ps)>
              {{ ucfirst($ps) }}
            </option>
          @endforeach
        </select>
        <button type="submit" class="ia-btn ia-btn--secondary ia-btn--sm" style="width:100%">
          Update payment
        </button>
      </form>
    </div>

    {{-- Notes --}}
    <div class="ia-card ia-card--tight">
      <div class="appt-section-label">Notes</div>

      <div class="ia-note-add">
        <textarea id="note-input" rows="3" maxlength="500"
          data-maxlength="500" data-counter="note-chars"
          placeholder="Add a note…" style="width:100%;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);padding:8px 10px;font-size:13px;resize:none;font-family:var(--ia-font)"></textarea>
        <div class="ia-note-add-footer">
          <span class="ia-char-count" id="note-chars">500</span>
          <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="note-submit"
            data-url="{{ $updateUrl }}">
            Add note
          </button>
        </div>
        <p id="note-error" style="font-size:12px;color:#E24B4A;margin-top:4px;display:none"></p>
      </div>

      <div class="ia-notes" id="notes-list">
        @forelse($appointment->notes->sortByDesc('created_at') as $note)
          <div class="ia-note" data-note-id="{{ $note->id }}">
            <div class="ia-note-head">
              <span class="ia-note-author">
                {{ $note->user?->name ?? ($note->note_type === 'system' ? 'System' : 'Staff') }}
              </span>
              <span class="ia-note-time">
                {{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}
              </span>
              @if($note->note_type !== 'system')
                <button type="button" class="ia-note-delete"
                  data-note-id="{{ $note->id }}"
                  title="Delete">&#x2715;</button>
              @endif
            </div>
            <div class="ia-note-body">{{ $note->note_content }}</div>
          </div>
        @empty
          <p class="ia-notes-empty" style="font-size:13px;opacity:.4">No notes yet.</p>
        @endforelse
      </div>
    </div>

  </div>

</div>

@endsection

@push('scripts')
<script>
(function () {
  var updateUrl = '{{ $updateUrl }}';
  var csrf      = window.IntakeAdmin.csrfToken;

  var toggle  = document.getElementById('add-charge-toggle');
  var form    = document.getElementById('add-charge-form');
  var cancel  = document.getElementById('add-charge-cancel');
  var amtDisp = document.getElementById('charge-amount-display');
  var amtCents= document.getElementById('charge-amount-cents');

  if (toggle) toggle.addEventListener('click', function () {
    form.classList.add('open');
    toggle.style.display = 'none';
  });
  if (cancel) cancel.addEventListener('click', function () {
    form.classList.remove('open');
    toggle.style.display = '';
  });
  if (amtDisp) amtDisp.addEventListener('input', function () {
    amtCents.value = Math.round(parseFloat(amtDisp.value || 0) * 100);
  });

  var noteInput  = document.getElementById('note-input');
  var noteSubmit = document.getElementById('note-submit');
  var noteError  = document.getElementById('note-error');
  var notesList  = document.getElementById('notes-list');
  var noteChars  = document.getElementById('note-chars');

  if (noteInput && noteChars) {
    noteInput.addEventListener('input', function () {
      var rem = 500 - noteInput.value.length;
      noteChars.textContent = rem;
      noteChars.classList.toggle('warn', rem <= 30);
    });
  }

  if (noteSubmit) {
    noteSubmit.addEventListener('click', function () {
      var note = noteInput.value.trim();
      if (!note) { showErr('Please enter a note.'); return; }
      noteSubmit.disabled = true;
      noteSubmit.textContent = 'Saving…';

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'add_note');
      fd.append('note', note);

      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          if (!resp.success) { showErr(resp.message || 'Error.'); return; }

          var empty = notesList.querySelector('.ia-notes-empty');
          if (empty) empty.remove();

          var el = document.createElement('div');
          el.className = 'ia-note';
          el.setAttribute('data-note-id', resp.id);
          el.innerHTML =
            '<div class="ia-note-head">' +
              '<span class="ia-note-author">' + esc(resp.author) + '</span>' +
              '<span class="ia-note-time">' + esc(resp.created_at) + '</span>' +
              '<button type="button" class="ia-note-delete" data-note-id="' + resp.id + '" title="Delete">&#x2715;</button>' +
            '</div>' +
            '<div class="ia-note-body">' + esc(resp.note) + '</div>';
          notesList.insertBefore(el, notesList.firstChild);
          bindDeleteOnEl(el.querySelector('.ia-note-delete'));

          noteInput.value = '';
          if (noteChars) { noteChars.textContent = '500'; noteChars.classList.remove('warn'); }
          hideErr();
        })
        .catch(function () {
          noteSubmit.disabled = false;
          noteSubmit.textContent = 'Add note';
          showErr('Network error. Try again.');
        });
    });
  }

  document.querySelectorAll('.ia-note-delete').forEach(bindDeleteOnEl);

  function bindDeleteOnEl(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Delete this note?')) return;
      var noteId = btn.getAttribute('data-note-id');
      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'PATCH');
      fd.append('op', 'delete_note');
      fd.append('note_id', noteId);
      fetch(updateUrl, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.success) {
            var li = document.querySelector('[data-note-id="' + noteId + '"]');
            if (li) li.remove();
            if (!notesList.querySelector('.ia-note')) {
              var p = document.createElement('p');
              p.className = 'ia-notes-empty';
              p.style.cssText = 'font-size:13px;opacity:.4';
              p.textContent = 'No notes yet.';
              notesList.appendChild(p);
            }
          }
        });
    });
  }

  function showErr(msg) {
    if (noteError) { noteError.textContent = msg; noteError.style.display = ''; }
  }
  function hideErr() {
    if (noteError) noteError.style.display = 'none';
  }
  function esc(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

}());
</script>
@endpush
