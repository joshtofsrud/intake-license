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
    'cancelled'   => 'Cancel appointment',
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

/* Status progress bar */
.appt-progress-card { padding: 18px 24px; margin-bottom: 20px; }
.appt-progress-bar { display: flex; align-items: flex-start; justify-content: space-between; position: relative; gap: 4px; }
.appt-progress-bar::before { content: ''; position: absolute; top: 12px; left: 12px; right: 12px; height: 2px; background: var(--ia-border); z-index: 0; }
.appt-progress-bar::after {
  content: ''; position: absolute; top: 12px; left: 12px; height: 2px; background: var(--ia-accent); z-index: 0;
  width: calc((100% - 24px) * var(--progress, 0));
  transition: width .25s ease;
}
.appt-progress-step {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  position: relative; z-index: 1; background: transparent; border: none; cursor: pointer; padding: 0;
  flex: 1; min-width: 0; font-family: inherit;
}
.appt-progress-step:disabled { cursor: default; }
.appt-progress-dot {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--ia-surface); border: 0.5px solid var(--ia-border);
  display: flex; align-items: center; justify-content: center;
  transition: background var(--ia-t), border-color var(--ia-t);
  color: #fff;
}
.appt-progress-step.is-done .appt-progress-dot { background: var(--ia-accent); border-color: var(--ia-accent); color: var(--ia-accent-text); }
.appt-progress-step.is-current .appt-progress-dot { border: 2px solid var(--ia-accent); background: var(--ia-surface); }
.appt-progress-dot-inner { width: 8px; height: 8px; border-radius: 50%; background: var(--ia-accent); }
.appt-progress-label { font-size: 11px; color: var(--ia-text-muted); transition: color var(--ia-t); }
.appt-progress-step.is-current .appt-progress-label { font-weight: 500; color: var(--ia-text); }
.appt-progress-step:not(:disabled):hover .appt-progress-dot { border-color: var(--ia-accent); }
.appt-progress-step.is-saving .appt-progress-dot { opacity: .5; }

/* Terminal state card (cancelled / refunded) */
.appt-terminal-card { display: flex; align-items: center; gap: 14px; padding: 18px 24px; margin-bottom: 20px; }
.appt-terminal-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #fff; }
.appt-terminal-icon--cancelled { background: #A32D2D; }
.appt-terminal-icon--refunded { background: #BA7517; }
.appt-terminal-title { font-size: 15px; font-weight: 500; }
.appt-terminal-sub { font-size: 13px; color: var(--ia-text-muted); margin-top: 2px; }
.appt-terminal-card .appt-reopen-btn { margin-left: auto; }

.appt-cancel-btn { margin-top: 4px; }
</style>

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">
      Work order
    </div>
    <h1 class="ia-page-title">{{ $appointment->ra_number }}</h1>
    <p class="ia-page-subtitle">
      {{ $appointment->customerName() }} ·
      {{ $appointment->appointment_date->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.appointments.index') }}" class="ia-btn ia-btn--ghost">← Back</a>
  </div>
</div>

@php
  // Status progress bar — terminal states (cancelled/refunded) replace the bar with a card.
  $isTerminal = in_array($appointment->status, ['cancelled', 'refunded']);
  $pipelineSteps = ['pending', 'confirmed', 'in_progress', 'completed'];
  // TODO: per-tenant extensions for 'shipped' and 'closed' once Workflow settings ship.
  $currentIndex = array_search($appointment->status, $pipelineSteps);
  if ($currentIndex === false) $currentIndex = 0;
@endphp

@if($isTerminal)
  <div class="ia-card appt-terminal-card">
    <div class="appt-terminal-icon appt-terminal-icon--{{ $appointment->status }}">
      @if($appointment->status === 'cancelled')
        <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      @else
        <svg width="14" height="14" viewBox="0 0 10 10" fill="none"><path d="M2 5h6M5 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      @endif
    </div>
    <div>
      <div class="appt-terminal-title">{{ $statusLabels[$appointment->status] }}</div>
      <div class="appt-terminal-sub">This appointment is {{ $appointment->status }}. Use Reopen to revert.</div>
    </div>
    <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-reopen-btn" data-status="pending">
      Reopen
    </button>
  </div>
@else
  <div class="ia-card appt-progress-card">
    <div class="appt-progress-bar" data-current-index="{{ $currentIndex }}" data-update-url="{{ $updateUrl }}">
      @foreach($pipelineSteps as $idx => $step)
        @php
          $stepLabel = $statusLabels[$step];
          $isDone    = $idx < $currentIndex;
          $isCurrent = $idx === $currentIndex;
        @endphp
        <button type="button"
                class="appt-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                data-status="{{ $step }}"
                data-step-index="{{ $idx }}"
                data-label="{{ $stepLabel }}">
          <span class="appt-progress-dot">
            @if($isDone)
              <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @elseif($isCurrent)
              <span class="appt-progress-dot-inner"></span>
            @endif
          </span>
          <span class="appt-progress-label">{{ $stepLabel }}</span>
        </button>
      @endforeach
    </div>
  </div>
@endif

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
              <th class="ia-num">Duration</th>
              <th class="ia-num">Price</th>
            </tr>
          </thead>
          <tbody>
            @foreach($appointment->items as $item)
              <tr>
                <td style="font-weight:500">{{ $item->item_name_snapshot }}</td>
                <td class="ia-num" style="opacity:.6">{{ $item->duration_minutes_snapshot ?? 0 }} min</td>
                <td class="ia-num">{{ format_money($item->price_cents) }}</td>
              </tr>
            @endforeach
            @foreach($appointment->addons as $addon)
              <tr>
                <td style="opacity:.7">+ {{ $addon->addon_name_snapshot }}</td>
                <td class="ia-num" style="opacity:.4">{{ $addon->duration_minutes_snapshot ?? 0 }} min</td>
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

    {{-- Work order (staff-filled equipment details) --}}
    @if($appointment->workOrderFields && $appointment->workOrderFields->isNotEmpty())
    @php
      $responsesByFieldId = $appointment->workOrderResponses->keyBy('field_id');
      $identifierField = $appointment->workOrderFields->firstWhere('is_identifier', true);
      $identifierValue = $identifierField ? ($responsesByFieldId[$identifierField->id]->response_value ?? null) : null;
      $nonIdentifierFields = $appointment->workOrderFields->filter(fn($f) => !$f->is_identifier);
    @endphp
    <div class="ia-card" id="work-order-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:0.5px solid var(--ia-border)">
        <div class="appt-section-label" style="margin-bottom:0">Work order</div>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-toggle">Edit</button>
      </div>

      {{-- Display mode --}}
      <div id="wo-display">
        @if($identifierField && $identifierValue)
          <div style="margin-bottom:18px;padding-bottom:16px;border-bottom:0.5px solid var(--ia-border)">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:6px">
              {{ $identifierField->label }}
            </div>
            <div class="ia-mono" style="font-size:18px;font-weight:500;letter-spacing:.02em">
              {{ $identifierValue }}
            </div>
          </div>
        @endif

        @php
          $filledNonIdentifier = $nonIdentifierFields->filter(fn($f) => !empty($responsesByFieldId[$f->id]->response_value ?? null));
        @endphp

        @if($filledNonIdentifier->isEmpty() && (!$identifierField || !$identifierValue))
          <p style="font-size:13px;opacity:.4">No work order details recorded yet.</p>
        @elseif($filledNonIdentifier->isNotEmpty())
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px 32px">
            @foreach($filledNonIdentifier as $field)
              <div>
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--ia-text-muted);font-weight:500;margin-bottom:3px">
                  {{ $field->label }}
                </div>
                <div style="font-size:14px">{{ $responsesByFieldId[$field->id]->response_value }}</div>
              </div>
            @endforeach
          </div>
        @endif
      </div>

      {{-- Edit mode --}}
      <form id="wo-edit-form" style="display:none" method="POST" action="{{ $updateUrl }}">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="save_work_order">

        @foreach($appointment->workOrderFields as $field)
          @php $currentValue = $responsesByFieldId[$field->id]->response_value ?? ''; @endphp
          <div class="ia-form-group" style="margin-bottom:14px">
            <label class="ia-form-label">
              {{ $field->label }}
              @if($field->is_identifier)
                <span style="background:var(--ia-accent);color:var(--ia-accent-text);font-size:9px;font-weight:500;padding:1px 6px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;margin-left:6px">ID</span>
              @endif
              @if($field->is_required)
                <span class="ia-required">*</span>
              @endif
            </label>
            @if($field->field_type === 'textarea')
              <textarea name="values[{{ $field->id }}]" class="ia-input" rows="3" @if($field->is_required) required @endif>{{ $currentValue }}</textarea>
            @elseif($field->field_type === 'number')
              <input type="number" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @elseif($field->field_type === 'select')
              <select name="values[{{ $field->id }}]" class="ia-input" @if($field->is_required) required @endif>
                <option value="">—</option>
                @foreach(($field->options ?? []) as $opt)
                  <option value="{{ $opt }}" @selected($currentValue === $opt)>{{ $opt }}</option>
                @endforeach
              </select>
            @else
              <input type="text" name="values[{{ $field->id }}]" value="{{ $currentValue }}" class="ia-input" @if($field->is_required) required @endif>
            @endif
            @if($field->help_text)
              <div style="font-size:11px;opacity:.5;margin-top:3px">{{ $field->help_text }}</div>
            @endif
          </div>
        @endforeach

        <div style="display:flex;gap:8px;margin-top:16px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="wo-edit-cancel">Cancel</button>
        </div>
      </form>
    </div>
    @endif

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

    {{-- Notes --}}
    <div class="ia-card">
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

    {{-- Cancel appointment (destructive, separate from forward flow) --}}
    @unless(in_array($appointment->status, ['cancelled', 'refunded']))
      <button type="button" class="ia-btn ia-btn--danger ia-btn--sm appt-cancel-btn" style="width:100%">
        Cancel appointment
      </button>
    @endunless

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


  // Status updates — used by progress bar steps, reopen button, and cancel button.
  function updateStatus(targetStatus, targetLabel, opts) {
    opts = opts || {};
    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('_method', 'PATCH');
    fd.append('op', 'status');
    fd.append('status', targetStatus);

    return fetch(updateUrl, { method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.IntakeToast.success(targetLabel || 'Saved');
          setTimeout(function () {
            if (opts.redirectToCalendar) {
              window.location.href = '{{ route("tenant.calendar.index") }}';
            } else {
              window.location.reload();
            }
          }, 600);
          return true;
        }
        var msg = (res.body && res.body.message) || 'Could not update status.';
        window.IntakeToast.error(msg);
        return false;
      })
      .catch(function () {
        window.IntakeToast.error('Network error. Try again.');
        return false;
      });
  }

  // Progress bar — click a step to move there.
  // Forward moves go silently. Backward moves trigger a confirm modal.
  var bar = document.querySelector('.appt-progress-bar');
  if (bar) {
    var currentIndex = parseInt(bar.getAttribute('data-current-index'), 10);
    // Set the green-fill width via CSS variable.
    bar.style.setProperty('--progress', currentIndex / 3);

    bar.querySelectorAll('.appt-progress-step').forEach(function (step) {
      step.addEventListener('click', function () {
        var stepIndex = parseInt(step.getAttribute('data-step-index'), 10);
        var status    = step.getAttribute('data-status');
        var label     = step.getAttribute('data-label');
        if (stepIndex === currentIndex) return;  // clicking current is a no-op

        var go = function () {
          step.classList.add('is-saving');
          updateStatus(status, label);
        };

        if (stepIndex < currentIndex) {
          window.IntakeConfirm.show({
            title:       'Move back to ' + label + '?',
            message:     'This appointment is currently further along. Going back may surprise the customer.',
            confirmText: 'Move back',
            cancelText:  'Keep where it is'
          }).then(function (ok) { if (ok) go(); });
        } else {
          go();
        }
      });
    });
  }

  // Reopen button on terminal cards (cancelled / refunded)
  var reopenBtn = document.querySelector('.appt-reopen-btn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Reopen this appointment?',
        message:     'This will return it to Pending status.',
        confirmText: 'Reopen',
        cancelText:  'Keep closed'
      }).then(function (ok) {
        if (ok) updateStatus('pending', 'Reopened');
      });
    });
  }

  // Cancel button (sidebar)
  var cancelBtn = document.querySelector('.appt-cancel-btn');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      window.IntakeConfirm.show({
        title:       'Cancel this appointment?',
        message:     "The appointment will be removed from the calendar and the customer's slot released. This stays in your records but won't show on the active schedule.",
        confirmText: 'Cancel appointment',
        cancelText:  'Keep it',
        danger:      true
      }).then(function (ok) {
        if (ok) updateStatus('cancelled', 'Cancelled', { redirectToCalendar: true });
      });
    });
  }

  // Work order edit mode toggle — wo-edit-toggle-bound
  var woDisplay = document.getElementById('wo-display');
  var woForm = document.getElementById('wo-edit-form');
  var woToggle = document.getElementById('wo-edit-toggle');
  var woCancel = document.getElementById('wo-edit-cancel');
  if (woToggle && woForm && woDisplay) {
    woToggle.addEventListener('click', function() {
      woDisplay.style.display = 'none';
      woForm.style.display = 'block';
      woToggle.style.display = 'none';
    });
  }
  if (woCancel && woForm && woDisplay) {
    woCancel.addEventListener('click', function() {
      woForm.style.display = 'none';
      woDisplay.style.display = 'block';
      if (woToggle) woToggle.style.display = '';
    });
  }

}());
</script>
@endpush
