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
      @if($so->customer) · for {{ $so->customer->fullName() }} @endif
      @if($so->appointment) · {{ $so->appointment->ra_number }} @endif
    </p>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== STATE STRIP ========== --}}
@php
  $stages = ['needed' => 'Needed', 'ordered' => 'Ordered', 'arrived' => 'Arrived', 'pulled' => 'Handed over'];
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
        <span class="so-status so-status--{{ $so->status }}">{{ $so->statusLabel() }}</span>
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
          {{-- MARKER-SO-REPAIRS: where it is picked up --}}
          <div>
            <div class="so-detail-label">Pickup location</div>
            <div class="so-detail-value">{{ $so->location?->name ?? '—' }}</div>
          </div>
          {{-- MARKER-SO-HANDOVER: hold, sale, and whether they've been told --}}
          @if(in_array($so->status, ['arrived', 'pulled'], true))
            <div>
              <div class="so-detail-label">Hold</div>
              <div class="so-detail-value">
                @if($so->status === 'pulled')
                  <span class="ia-text-muted">Used at handover</span>
                @elseif($hold['state'] === 'held')
                  <span class="so-hold on">Held{{ $hold['at'] ? ' at ' . $hold['at'] : '' }}</span>
                @elseif($hold['state'] === 'taken')
                  <span class="ia-text-muted">Counted out at sale</span>
                @elseif($hold['state'] === 'uncataloged')
                  <span class="ia-text-muted">Not in catalog</span>
                @elseif($hold['state'] === 'stock')
                  <span class="ia-text-muted">In stock · nobody waiting</span>
                @else
                  <span class="so-hold off">Not held</span>
                  <div class="so-bench-btns">
                    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="hold" data-id="{{ $so->id }}">Hold now</button>
                    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" data-so-act="missing" data-id="{{ $so->id }}" data-so="{{ $so->so_number }}">Not on shelf</button>
                  </div>
                @endif
              </div>
            </div>
            <div>
              <div class="so-detail-label">Customer told</div>
              <div class="so-detail-value">
                @if($so->notified_at){{ $so->notified_via }} · {{ tlocal_datetime($so->notified_at, 'M j, g:i a') }}@else<span class="ia-text-muted">Not yet</span>@endif
              </div>
            </div>
          @endif
          @if($so->sale_id)
            @php $soSale = \App\Models\Tenant\TenantSale::find($so->sale_id); @endphp
            <div>
              <div class="so-detail-label">Sale</div>
              <div class="so-detail-value">
                @if($soSale)
                  {{ $soSale->sale_number ?: 'At the register' }} · {{ in_array($soSale->payment_status, ['paid', 'partial'], true) ? ($soSale->payment_status === 'paid' ? 'paid in full' : 'part paid') : 'not paid' }}
                @else
                  <span class="ia-text-muted">Removed</span>
                @endif
              </div>
            </div>
          @endif
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

    {{-- replaces the deposit card. Money is read from the
         layaway's ledger, never stored here. The shop needs to know a customer
         has paid, because that changes how hard you chase the vendor; it does
         not take payments on this page. --}}
    @php
      $soPlan = $so->sale_id
        ? \App\Models\Tenant\TenantLayawayPlan::where('sale_id', $so->sale_id)->with('customer')->first()
        : null;
      $soPaid = $soPlan ? app(\App\Services\Tenant\LayawayService::class)->paidCents($soPlan) : 0;
      $soBal  = $soPlan ? app(\App\Services\Tenant\LayawayService::class)->balanceCents($soPlan) : 0;
    @endphp

    @if($soPlan)
      <div class="ia-card" style="margin-top:16px">
        <div class="ia-card-head">
          <span class="ia-card-title">For a layaway</span>
        </div>
        <div class="ia-card-body">
          <div class="so-detail-grid">
            <div>
              <div class="so-detail-label">Customer</div>
              <div class="so-detail-value">{{ $soPlan->customer?->name ?: '—' }}</div>
            </div>
            <div>
              <div class="so-detail-label">Paid so far</div>
              <div class="so-detail-value"><strong>{{ format_money($soPaid) }}</strong>
                @if($soBal === 0)<span class="ia-badge" style="margin-left:6px">paid in full</span>@endif
              </div>
            </div>
            <div>
              <div class="so-detail-label">Still owed</div>
              <div class="so-detail-value">{{ format_money($soBal) }}</div>
            </div>
          </div>
          <p class="ia-text-muted" style="font-size:11.5px;margin-top:12px">
            This customer is waiting on this arrival.
            <a href="{{ route('tenant.register.layaways.show', $soPlan->id) }}">Open the plan →</a>
            Payments are taken at the register, not here.
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
                    {{ $sib->customer->fullName() }}
                  @else
                    <span class="ia-text-muted">Shop stock</span>
                  @endif
                </td>
                <td>{{ $sib->quantity }}</td>
                <td><span class="so-status so-status--{{ $sib->status }}">{{ $sib->statusLabel() }}</span></td>
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
            {{-- MARKER-SO-HANDOVER --}}
            @include('tenant.special-orders._handover-button', ['ctx' => $ctx, 'who' => $so->customer ? $so->customer->fullName() : ($so->appointment ? 'work order ' . $so->appointment->ra_number : 'shop stock'), 'hold' => $hold])
            @if($so->customer_id || $so->appointment_id)
              <button type="button" class="ia-btn ia-btn--secondary" data-so-act="tell" data-id="{{ $so->id }}">{{ $so->notified_at ? 'Tell customer again' : 'Tell customer' }}</button>
            @endif
          @endif

          <button type="button" class="ia-btn ia-btn--danger" onclick="SoActions.openCancel()">Cancel order</button>
        </div>
      </div>
      @if($so->status === 'arrived')
        <div class="so-legend" style="margin-top:14px">
          @switch($ctx['kind'])
            @case('paid') Paid on {{ $ctx['sale'] ?: 'its sale' }}, so handing over just releases the hold and takes the unit out of stock. Nothing is charged. @break
            @case('unpaid') Not paid yet. Ring it up at the register to hand it over at checkout, or hand it over without a sale with a reason (a warranty swap, a part paid elsewhere). @break
            @case('job') It's a part for {{ $ctx['label'] }}. Completing the work order uses it and takes it out of stock. @break
            @case('layaway') It's on a layaway. Handing the layaway over at the register hands this over too. @break
            @default It's shop stock and already counted in stock. Putting it away closes the order; stock doesn't change.
          @endswitch
        </div>
      @endif
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
                <strong>{{ $so->customer->fullName() }}</strong>
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
              <div class="so-detail-label">Handed over</div>
              <div class="so-detail-value" style="font-size:12px">{{ $so->pulled_at->format('M j, Y g:i a') }}@if($handedOverBy) · {{ $handedOverBy }}@endif
                @if($so->handover_note)<div class="ia-text-muted" style="font-size:11.5px">{{ $so->handover_note }}</div>@endif
              </div>
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
          {{-- MARKER-SO-REPAIRS: only vendors that carry this item --}}
          @if(empty($vendorOptions))
            <div class="ia-form-help" style="margin:0">
              @if($so->inventory_item_id)
                No vendor carries this item yet.
                <a href="{{ route('tenant.inventory.edit', ['id' => $so->inventory_item_id]) }}#item-sources" style="color:var(--ia-accent)">Add a vendor to the item</a>, then mark it ordered.
              @else
                You have no active vendors yet.
                <a href="{{ route('tenant.vendors.index') }}" style="color:var(--ia-accent)">Add a vendor</a>, then mark it ordered.
              @endif
            </div>
          @else
            <select name="vendor_id" class="ia-select" required>
              <option value="">— select —</option>
              @foreach($vendorOptions as $v)
                @php
                  $bits = [];
                  if ($v['avail'] !== null) { $bits[] = $v['avail'] > 0 ? $v['avail'] . ' available' : 'none available'; }
                  if ($v['cost_cents'] !== null) { $bits[] = format_money($v['cost_cents']); }
                  if ($v['preferred']) { $bits[] = 'preferred'; }
                @endphp
                <option value="{{ $v['id'] }}" {{ $so->vendor_id === $v['id'] ? 'selected' : '' }}>{{ $v['name'] }}{{ $bits ? ' — ' . implode(' · ', $bits) : '' }}</option>
              @endforeach
            </select>
            @if($so->inventory_item_id)
              <div class="ia-form-help">Only vendors that carry this item are listed.</div>
            @endif
          @endif
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
            <label class="ia-form-label">Est. unit cost ($)</label>
            <input type="number" name="unit_cost_estimated" class="ia-input" min="0" step="0.01" value="{{ $so->unit_cost_cents_estimated !== null ? number_format($so->unit_cost_cents_estimated / 100, 2, '.', '') : '' }}">
          </div>
        </div>
      </div>
      <div class="ia-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="SoActions.closeOrdered()">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary" @if(empty($vendorOptions)) disabled @endif>Mark ordered</button>
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
        {{-- MARKER-SO-REPAIRS: legend — what this does to stock --}}
        <p class="ia-text-muted" style="font-size:12.5px;margin-bottom:14px">
          @if($so->inventory_item_id)
            Adds what you received to stock at <strong>{{ $so->location?->name ?? 'your main location' }}</strong>. Use this when the part came in without a shipment in Receiving; a shipment that includes it marks it arrived on its own.
          @else
            This item isn't in your inventory, so stock doesn't change.
          @endif
        </p>
        <div class="ia-input-grid-2">
          <div class="ia-form-group">
            <label class="ia-form-label">Quantity received</label>
            <input type="number" name="received_quantity" class="ia-input" min="1" max="{{ $so->quantity }}" value="{{ $so->quantity }}" required>
            <div class="ia-form-help">Fewer than {{ $so->quantity }} keeps the rest on order.</div>
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Actual unit cost ($)</label>
            <input type="number" name="unit_cost_actual" class="ia-input" min="0" step="0.01" placeholder="optional">
          </div>
        </div>
        <div class="ia-input-grid-2">
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
    <form method="POST" action="{{ route('tenant.special-orders.cancel', ['id' => $so->id]) }}" id="so-cancel-form">
      @csrf
      <div class="ia-modal-head">
        <h3 class="ia-modal-title">Cancel special order</h3>
      </div>
      <div class="ia-modal-body">
        <p class="ia-text-muted" style="font-size:13px;margin-bottom:14px">
          Cancelling doesn't refund anything — refund from the sale if it was paid. The order stays in history.
          @if(in_array($so->status, ['ordered', 'arrived'], true))
            It's {{ $so->status === 'ordered' ? 'already on order, so it will still arrive' : 'on the bench' }}; you'll choose what happens to it next.
          @endif
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
// MARKER-SO-HANDOVER: cancel goes through the shared dialog, so an ordered
// or arrived order asks what happens to it instead of bouncing back.
document.getElementById('so-cancel-form')?.addEventListener('submit', function (e) {
  if (!window.IntakeSO) { return; }
  e.preventDefault();
  var reason = this.querySelector('[name=reason]').value;
  SoActions.closeCancel();
  IntakeSO.act(this.action, { reason: reason }).then(function (d) { if (d && d.ok) { location.reload(); } });
});
</script>
@endpush
@include('tenant.special-orders._handover-js')

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
.so-legend { font-size: 12.5px; color: var(--ia-text-muted); line-height: 1.55; border-left: 2px solid var(--ia-border-strong, var(--ia-border)); padding: 2px 0 2px 12px; }
.so-hold { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:600; border-radius:99px; padding:2px 9px; white-space:nowrap; }
.so-hold.on { background: rgba(190,242,100,.10); color: var(--ia-accent); }
body.ia-theme-b .so-hold.on { background: rgba(59,109,17,.10); color: #3B6D11; }
.so-hold.off { background: rgba(245,196,81,.12); color: #c9972a; }
.so-bench-btns { display:flex; gap:6px; margin-top:6px; flex-wrap:wrap; }
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
