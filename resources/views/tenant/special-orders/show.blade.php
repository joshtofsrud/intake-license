@extends('layouts.tenant.app')
@php $pageTitle = $so->so_number; @endphp

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div class="ia-text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:4px">
      <a href="{{ route('tenant.special-orders.index') }}" style="color:inherit;text-decoration:none">← Special orders</a>
    </div>
    <h1 class="ia-page-title">{{ $so->so_number }}</h1>
    <p class="ia-page-subtitle">
      {{ $so->item_name_snapshot }} ×{{ $so->quantity }}
      @if($so->customer) · for {{ $so->customer->first_name }} {{ $so->customer->last_name }} @endif
      @if($so->appointment) · {{ $so->appointment->ra_number }} @endif
    </p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== STATE STRIP ========== --}}
@php
  $stages = ['needed' => 'Needed', 'ordered' => 'Ordered', 'arrived' => 'Arrived', 'pulled' => 'Pulled'];
  $stageIdx = array_search($so->status, array_keys($stages));
  $isCancelled = $so->status === 'cancelled';
@endphp
<div class="so-state-strip">
  @foreach($stages as $key => $label)
    @php
      $i = array_search($key, array_keys($stages));
      $isDone    = !$isCancelled && $stageIdx !== false && $i < $stageIdx;
      $isCurrent = !$isCancelled && $key === $so->status;
    @endphp
    <div class="so-state-step {{ $isDone ? 'done' : '' }} {{ $isCurrent ? 'current' : '' }}">
      {{ $label }}
    </div>
  @endforeach
  @if($isCancelled)
    <div class="so-state-step cancelled current">Cancelled</div>
  @endif
</div>

<div class="so-show-grid">

  {{-- LEFT COLUMN --}}
  <div class="so-show-col">

    {{-- Order details card --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Order details</span>
        <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
      </div>
      <div class="ia-card-body">
        <div class="so-detail-grid">
          <div>
            <div class="so-detail-label">Item</div>
            <div class="so-detail-value">
              <strong>{{ $so->item_name_snapshot }}</strong>
              @if($so->item && $so->item->sku)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->item->sku }}</div>
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Quantity</div>
            <div class="so-detail-value"><strong>{{ $so->quantity }}</strong></div>
          </div>
          <div>
            <div class="so-detail-label">Vendor</div>
            <div class="so-detail-value">
              @if($so->vendor)
                <a href="{{ route('tenant.vendors.show', ['id' => $so->vendor->id]) }}">{{ $so->vendor->name }}</a>
              @else
                <span class="ia-text-muted">TBD</span>
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Our PO #</div>
            <div class="so-detail-value">{{ $so->po_number ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Vendor reference</div>
            <div class="so-detail-value">{{ $so->vendor_reference ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Expected arrival</div>
            <div class="so-detail-value">
              @if($so->expected_arrival_date)
                {{ $so->expected_arrival_date->format('M j, Y') }}
                @if($so->status === 'ordered' && $so->expected_arrival_date->isPast())
                  <span class="so-status so-status--overdue" style="margin-left:6px">Overdue</span>
                @endif
              @else
                —
              @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Estimated unit cost</div>
            <div class="so-detail-value">
              @if($so->unit_cost_cents_estimated !== null){{ format_money($so->unit_cost_cents_estimated) }}@else — @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Actual unit cost</div>
            <div class="so-detail-value">
              @if($so->unit_cost_cents_actual !== null){{ format_money($so->unit_cost_cents_actual) }}@else — @endif
            </div>
          </div>
          <div>
            <div class="so-detail-label">Invoice #</div>
            <div class="so-detail-value">{{ $so->vendor_invoice_number ?: '—' }}</div>
          </div>
          <div>
            <div class="so-detail-label">Invoice date</div>
            <div class="so-detail-value">{{ $so->vendor_invoice_date?->format('M j, Y') ?: '—' }}</div>
          </div>
        </div>
      </div>
    </div>

    {{-- Deposit card --}}
    @if($so->deposit_cents > 0)
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-head">
          <span class="ia-card-title">Deposit</span>
        </div>
        <div class="ia-card-body">
          <div class="so-detail-grid">
            <div>
              <div class="so-detail-label">Deposit collected</div>
              <div class="so-detail-value"><strong>{{ format_money($so->deposit_cents) }}</strong></div>
            </div>
            <div>
              <div class="so-detail-label">Paid at</div>
              <div class="so-detail-value">
                @if($so->deposit_paid_at){{ $so->deposit_paid_at->format('M j, Y') }}@else <span class="ia-text-muted">pending</span> @endif
              </div>
            </div>
          </div>
          <p class="ia-text-muted" style="font-size:11.5px;margin-top:12px">
            Deposit Stripe capture wires up in Stage 6 with the register integration. Current display reflects what's stored on the SO row.
          </p>
        </div>
      </div>
    @endif

    {{-- Notes thread --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">Notes</span>
        <span class="ia-text-muted" style="font-size:11.5px">{{ $so->notes->count() }} {{ Str::plural('note', $so->notes->count()) }}</span>
      </div>
      <div class="ia-card-body" style="padding-top:8px">
        @foreach($so->notes as $note)
          <div class="so-note {{ $note->is_system ? 'system' : '' }}">
            <div class="so-note-meta">
              <strong>{{ $note->is_system ? 'System' : ($note->user?->name ?? 'Staff') }}</strong>
              · {{ $note->created_at->format('M j, g:i a') }}
            </div>
            <div class="so-note-body">{{ $note->body }}</div>
          </div>
        @endforeach

        @if(!in_array($so->status, ['pulled', 'cancelled']))
          <form method="POST" action="{{ route('tenant.special-orders.notes.store', ['id' => $so->id]) }}" style="margin-top:14px">
            @csrf
            <textarea name="body" class="ia-input" rows="2" placeholder="Add a note (visible to staff only)…" required></textarea>
            <div style="margin-top:8px;text-align:right">
              <button type="submit" class="ia-btn ia-btn--secondary">Add note</button>
            </div>
          </form>
        @endif
      </div>
    </div>

    {{-- Batch siblings (if any) --}}
    @if($batchSiblings->isNotEmpty())
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-head">
          <span class="ia-card-title">Other rows in this batch</span>
        </div>
        <table class="ia-table ia-table--inset">
          <thead>
            <tr>
              <th>SO</th>
              <th>For</th>
              <th>Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            @foreach($batchSiblings as $sib)
              <tr style="cursor:pointer" onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $sib->id]) }}'">
                <td>{{ $sib->so_number }}</td>
                <td>
                  @if($sib->customer)
                    {{ $sib->customer->first_name }} {{ $sib->customer->last_name }}
                  @else
                    <span class="ia-text-muted">Shop stock</span>
                  @endif
                </td>
                <td>{{ $sib->quantity }}</td>
                <td><span class="so-status so-status--{{ $sib->status }}">{{ ucfirst($sib->status) }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

  </div>

  {{-- RIGHT COLUMN --}}
  <div class="so-show-col">

    {{-- Action buttons card --}}
    @if(!in_array($so->status, ['pulled', 'cancelled']))
      <div class="ia-card">
        <div class="ia-card-head"><span class="ia-card-title">Actions</span></div>
        <div class="ia-card-body" style="display:flex;flex-direction:column;gap:8px">

          @if($so->status === 'needed')
            <button type="button" class="ia-btn ia-btn--primary" onclick="SoActions.openOrdered()">Mark ordered</button>
          @endif

          @if($so->status === 'ordered')
            <button type="button" class="ia-btn ia-btn--primary" onclick="SoActions.openArrived()">Mark arrived</button>
          @endif

          @if($so->status === 'arrived')
            <form method="POST" action="{{ route('tenant.special-orders.mark-pulled', ['id' => $so->id]) }}">
              @csrf
              <button type="submit" class="ia-btn ia-btn--primary" style="width:100%">Mark pulled</button>
            </form>
          @endif

          <button type="button" class="ia-btn ia-btn--danger" onclick="SoActions.openCancel()">Cancel order</button>
        </div>
      </div>
    @endif

    {{-- Linked to card --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Linked to</span></div>
      <div class="ia-card-body">
        @if($so->customer)
          <div style="margin-bottom:14px">
            <div class="so-detail-label">Customer</div>
            <div style="margin-top:4px">
              <a href="{{ route('tenant.customers.show', ['id' => $so->customer->id]) }}">
                <strong>{{ $so->customer->first_name }} {{ $so->customer->last_name }}</strong>
              </a>
              @if($so->customer->email)
                <div class="ia-text-muted" style="font-size:11.5px">{{ $so->customer->email }}</div>
              @endif
            </div>
          </div>
        @endif
        @if($so->appointment)
          <div>
            <div class="so-detail-label">Appointment</div>
            <div style="margin-top:4px">
              <strong>{{ $so->appointment->ra_number }}</strong>
              <div class="ia-text-muted" style="font-size:11.5px">
                {{ $so->appointment->appointment_date?->format('M j, Y') }}
              </div>
            </div>
          </div>
        @endif
        @if(!$so->customer && !$so->appointment)
          <span class="ia-text-muted" style="font-size:13px">Shop stock — not linked to a customer or appointment</span>
        @endif
      </div>
    </div>

    {{-- Created from --}}
    <div class="ia-card" style="margin-top:16px">
      <div class="ia-card-head"><span class="ia-card-title">Metadata</span></div>
      <div class="ia-card-body">
        <div class="so-detail-grid">
          <div>
            <div class="so-detail-label">Created from</div>
            <div class="so-detail-value">{{ str_replace('_', ' ', ucfirst($so->created_from)) }}</div>
          </div>
          <div>
            <div class="so-detail-label">Created</div>
            <div class="so-detail-value" style="font-size:12px">{{ $so->created_at->format('M j, Y g:i a') }}</div>
          </div>
          @if($so->ordered_at)
            <div>
              <div class="so-detail-label">Ordered</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->ordered_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->arrived_at)
            <div>
              <div class="so-detail-label">Arrived</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->arrived_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->pulled_at)
            <div>
              <div class="so-detail-label">Pulled</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->pulled_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
          @if($so->cancelled_at)
            <div>
              <div class="so-detail-label">Cancelled</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->cancelled_at->format('M j, Y g:i a') }}</div>
            </div>
          @endif
        </div>
      </div>
    </div>

  </div>
</div>

{{-- Mark ordered modal --}}
<div id="so-mark-ordered-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeOrdered()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.mark-ordered', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Mark ordered</h3>
      </div>
      <div class="ia-modal-body">
        <div class="ia-form-group">
          <label class="ia-form-label">Vendor <span class="ia-required">*</span></label>
          <select name="vendor_id" class="ia-select" required>
            <option value="">— select —</option>
            @php $allVendors = \App\Models\Tenant\TenantVendor::where('tenant_id', tenant()->id)->where('is_active', true)->orderBy('name')->get(); @endphp
            @foreach($allVendors as $v)
              <option value="{{ $v->id }}" {{ $so->vendor_id === $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">PO # <span class="ia-required">*</span></label>
            <input type="text" name="po_number" class="ia-input" required value="{{ $so->po_number }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Vendor reference</label>
            <input type="text" name="vendor_reference" class="ia-input" value="{{ $so->vendor_reference }}">
          </div>
        </div>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Expected arrival <span class="ia-required">*</span></label>
            <input type="date" name="expected_arrival_date" class="ia-input" required value="{{ $so->expected_arrival_date?->format('Y-m-d') }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Est. unit cost (cents)</label>
            <input type="number" name="unit_cost_cents_estimated" class="ia-input" min="0" value="{{ $so->unit_cost_cents_estimated }}">
          </div>
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeOrdered()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Mark ordered</button>
      </div>
    </form>
  </div>
</div>

{{-- Mark arrived modal --}}
<div id="so-mark-arrived-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeArrived()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.mark-arrived', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Mark arrived</h3>
      </div>
      <div class="ia-modal-body">
        <p class="ia-text-muted" style="font-size:12.5px;margin-bottom:14px">
          Full receipt only in Stage 4b. Partial receipts ship in Stage 6 with the receiving integration.
        </p>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Actual unit cost (cents)</label>
            <input type="number" name="unit_cost_cents_actual" class="ia-input" min="0" placeholder="optional">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Invoice date</label>
            <input type="date" name="vendor_invoice_date" class="ia-input">
          </div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Invoice #</label>
          <input type="text" name="vendor_invoice_number" class="ia-input" placeholder="From the vendor's bill">
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeArrived()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Mark arrived</button>
      </div>
    </form>
  </div>
</div>

{{-- Cancel modal --}}
<div id="so-cancel-modal" class="ia-modal" style="display:none">
  <div class="ia-modal-backdrop" onclick="SoActions.closeCancel()"></div>
  <div class="ia-modal-panel" style="max-width:500px">
    <form method="POST" action="{{ route('tenant.special-orders.cancel', ['id' => $so->id]) }}">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Cancel special order</h3>
      </div>
      <div class="ia-modal-body">
        <p class="ia-text-muted" style="font-size:13px;margin-bottom:14px">
          This won't refund any deposit — handle that separately. The SO row stays in history.
        </p>
        <div class="ia-form-group">
          <label class="ia-form-label">Reason (optional)</label>
          <textarea name="reason" class="ia-input" rows="3" placeholder="Customer changed mind, vendor backordered, etc."></textarea>
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeCancel()">Keep order</button>
        <button type="submit" class="ia-btn ia-btn--danger">Cancel order</button>
      </div>
    </form>
  </div>
</div>

@push('scripts')
<script>
window.SoActions = {
  openOrdered: function () { document.getElementById('so-mark-ordered-modal').style.display = 'flex'; },
  closeOrdered: function () { document.getElementById('so-mark-ordered-modal').style.display = 'none'; },
  openArrived: function () { document.getElementById('so-mark-arrived-modal').style.display = 'flex'; },
  closeArrived: function () { document.getElementById('so-mark-arrived-modal').style.display = 'none'; },
  openCancel: function () { document.getElementById('so-cancel-modal').style.display = 'flex'; },
  closeCancel: function () { document.getElementById('so-cancel-modal').style.display = 'none'; },
};
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    SoActions.closeOrdered(); SoActions.closeArrived(); SoActions.closeCancel();
  }
});
</script>
@endpush

@push('styles')
<style>
/* SO-SHOW styles */

.so-state-strip {
  display: flex;
  gap: 4px;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  padding: 4px;
  margin-bottom: 20px;
}
.so-state-step {
  flex: 1;
  padding: 8px 10px;
  font-size: 11px;
  text-align: center;
  font-weight: 600;
  color: var(--ia-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-radius: 4px;
}
.so-state-step.done { color: var(--ia-text); }
.so-state-step.current {
  background: var(--ia-accent);
  color: #000;
}
.so-state-step.cancelled.current {
  background: rgba(248,113,113,0.2);
  color: #F87171;
}

.so-show-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 18px;
}
@media (max-width: 900px) { .so-show-grid { grid-template-columns: 1fr; } }
.so-show-col { display: flex; flex-direction: column; }

.so-detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 18px;
}
.so-detail-label {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--ia-text-muted); font-weight: 600; margin-bottom: 4px;
}
.so-detail-value { font-size: 13px; color: var(--ia-text); }

.so-note {
  padding: 10px 12px;
  margin-bottom: 8px;
  background: var(--ia-bg);
  border-radius: var(--ia-r-md);
  font-size: 12.5px;
}
.so-note.system { opacity: 0.85; }
.so-note-meta { font-size: 10.5px; color: var(--ia-text-muted); margin-bottom: 4px; }
.so-note-body { color: var(--ia-text); line-height: 1.55; }

/* Status pills (re-declared for show page) */
.so-status {
  display: inline-block; padding: 2px 8px; border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em;
}
.so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent); }
.so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-muted); }
.so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }

.ia-table--inset { border: none; border-top: 0.5px solid var(--ia-border); border-radius: 0; }

/* Modal styles — minimal, matches design language */
.ia-modal {
  position: fixed; inset: 0; z-index: 100;
  align-items: center; justify-content: center;
}
.ia-modal[style*="flex"] { display: flex !important; }
.ia-modal-backdrop {
  position: absolute; inset: 0;
  background: rgba(0,0,0,0.55);
}
.ia-modal-panel {
  position: relative;
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  width: 92vw; max-width: 500px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  z-index: 1;
}
.ia-modal-head { padding: 16px 20px; border-bottom: 0.5px solid var(--ia-border); }
.ia-modal-title { margin: 0; font-size: 15px; font-weight: 600; }
.ia-modal-body { padding: 18px 20px; overflow-y: auto; }
.ia-modal-foot {
  padding: 12px 20px; border-top: 0.5px solid var(--ia-border);
  display: flex; gap: 8px; justify-content: flex-end;
}
</style>
@endpush

@endsection
