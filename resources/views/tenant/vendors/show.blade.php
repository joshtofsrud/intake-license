@extends('layouts.tenant.app')
@php $pageTitle = $vendor->name; @endphp
@section('content')

{{-- VENDOR-SHOW · stat tiles + items + open SOs + recent shipments --}}

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div class="ia-text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600;margin-bottom:4px">
      <a href="{{ route('tenant.vendors.index') }}" style="color:inherit;text-decoration:none">← Vendors</a>
    </div>
    <h1 class="ia-page-title">
      {{ $vendor->name }}
      @if(!$vendor->is_active)
        <span class="ia-pill ia-pill--muted" style="margin-left:8px;vertical-align:middle;font-size:11px">Inactive</span>
      @endif
    </h1>
    <p class="ia-page-subtitle">
      @if($vendor->website){{ $vendor->website }}@endif
      @if($vendor->account_number) · account #{{ $vendor->account_number }} @endif
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.vendors.edit', ['id' => $vendor->id]) }}"
       class="ia-btn ia-btn--secondary">Edit</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--{{ session('flash')['type'] }}">{{ session('flash')['message'] }}</div>
@endif

{{-- ========== STAT TILES ========== --}}
<div class="vendor-stats">
  <div class="vendor-stat">
    <div class="vendor-stat-label">Items sourced</div>
    <div class="vendor-stat-value">{{ number_format($itemCount) }}</div>
    <div class="vendor-stat-sub">
      @if($itemCount > 0) through item-vendor pivot @else none yet @endif
    </div>
  </div>
  <div class="vendor-stat">
    <div class="vendor-stat-label">Avg lead time</div>
    <div class="vendor-stat-value">
      @if($avgLeadDays !== null){{ $avgLeadDays }}<span style="font-size:14px;color:var(--ia-text-muted);font-weight:500">d</span>@else <span class="ia-text-muted">—</span> @endif
    </div>
    <div class="vendor-stat-sub">
      @if($avgLeadDays !== null) actual, ordered → arrived @else no closed SOs yet @endif
    </div>
  </div>
  <div class="vendor-stat">
    <div class="vendor-stat-label">Open SOs</div>
    <div class="vendor-stat-value">{{ number_format($openSoCount) }}</div>
    <div class="vendor-stat-sub">
      @if($openSoCount > 0) needed / ordered / arrived @else nothing in flight @endif
    </div>
  </div>
  <div class="vendor-stat">
    <div class="vendor-stat-label">Recent shipments</div>
    <div class="vendor-stat-value">{{ number_format($recentShipments->count()) }}</div>
    <div class="vendor-stat-sub">last 10 · all-time history</div>
  </div>
</div>

<div class="vendor-show-grid">

  {{-- LEFT COLUMN --}}
  <div class="vendor-show-col">

    {{-- Items sourced from this vendor --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Items sourced from {{ $vendor->name }}</span>
        @if($items->count() > 0)
          <span class="ia-text-muted" style="font-size:11.5px">{{ $items->count() }} {{ Str::plural('item', $items->count()) }}</span>
        @endif
      </div>
      @if($items->isEmpty())
        <div class="ia-card-body">
          <p class="ia-text-muted" style="font-size:13px;margin:0">
            No items linked yet. Items get linked when staff sets this vendor as a source on an item detail page (Stage 5), or when a special order is placed and arrives.
          </p>
        </div>
      @else
        <table class="ia-table ia-table--inset">
          <thead>
            <tr>
              <th>Item</th>
              <th>Vendor SKU</th>
              <th>Unit cost</th>
              <th>Last ordered</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($items as $item)
              <tr>
                <td>
                  <strong>{{ $item->name }}</strong>
                  @if($item->sku)<div class="ia-text-muted" style="font-size:11.5px">{{ $item->sku }}</div>@endif
                </td>
                <td class="ia-text-muted">{{ $item->pivot->vendor_sku ?: '—' }}</td>
                <td>
                  @if($item->pivot->unit_cost_cents !== null)
                    {{ format_money($item->pivot->unit_cost_cents) }}
                  @else
                    <span class="ia-text-muted">—</span>
                  @endif
                </td>
                <td class="ia-text-muted" style="font-size:12px">
                  @if($item->pivot->last_ordered_at)
                    {{ \Carbon\Carbon::parse($item->pivot->last_ordered_at)->format('M j, Y') }}
                  @else
                    <em>never</em>
                  @endif
                </td>
                <td>
                  @if($item->pivot->is_preferred)
                    <span class="ia-pill ia-pill--accent">Preferred</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

    {{-- Open special orders for this vendor --}}
    <div class="ia-card" style="margin-top:18px">
      <div class="ia-card-head">
        <span class="ia-card-title">Open special orders</span>
        @if($openSos->count() > 0)
          <span class="ia-text-muted" style="font-size:11.5px">{{ $openSos->count() }} open</span>
        @endif
      </div>
      @if($openSos->isEmpty())
        <div class="ia-card-body">
          <p class="ia-text-muted" style="font-size:13px;margin:0">
            No open special orders with this vendor. Once Stage 4b (SO list + detail) ships, this list will populate from {{ $vendor->name }}'s outstanding orders.
          </p>
        </div>
      @else
        <table class="ia-table ia-table--inset">
          <thead>
            <tr>
              <th>SO</th>
              <th>Item</th>
              <th>Status</th>
              <th>ETA</th>
            </tr>
          </thead>
          <tbody>
            @foreach($openSos as $so)
              <tr>
                <td class="ia-text-muted">{{ $so->so_number }}</td>
                <td>
                  <strong>{{ $so->item_name_snapshot }}</strong>
                  @if($so->quantity > 1)<span class="ia-text-muted"> ×{{ $so->quantity }}</span>@endif
                </td>
                <td>
                  <span class="so-status so-status--{{ $so->status }}">{{ ucfirst($so->status) }}</span>
                </td>
                <td class="ia-text-muted" style="font-size:12px">
                  @if($so->expected_arrival_date)
                    @php $eta = $so->expected_arrival_date; @endphp
                    @php $isOverdue = $so->status === 'ordered' && $eta->isPast(); @endphp
                    @if($isOverdue)
                      <span style="color:#F87171;font-weight:600">{{ $eta->format('M j') }} — overdue</span>
                    @else
                      {{ $eta->format('M j') }}
                    @endif
                  @else
                    <em>—</em>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

  </div>

  {{-- RIGHT COLUMN --}}
  <div class="vendor-show-col">

    {{-- Contact card --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Contact</span>
      </div>
      <div class="ia-card-body" style="padding-top:14px">
        <div class="vendor-contact-grid">
          <div class="vendor-contact-row">
            <span class="vendor-contact-label">Email</span>
            <span class="vendor-contact-value">{{ $vendor->contact_email ?: '—' }}</span>
          </div>
          <div class="vendor-contact-row">
            <span class="vendor-contact-label">Phone</span>
            <span class="vendor-contact-value">{{ $vendor->contact_phone ?: '—' }}</span>
          </div>
          <div class="vendor-contact-row">
            <span class="vendor-contact-label">Website</span>
            <span class="vendor-contact-value">{{ $vendor->website ?: '—' }}</span>
          </div>
          <div class="vendor-contact-row">
            <span class="vendor-contact-label">Account #</span>
            <span class="vendor-contact-value">{{ $vendor->account_number ?: '—' }}</span>
          </div>
        </div>
        @if($vendor->notes)
          <div class="vendor-notes">
            <div class="vendor-contact-label" style="margin-bottom:6px">Notes</div>
            <div style="font-size:13px;color:var(--ia-text);line-height:1.55">{{ $vendor->notes }}</div>
          </div>
        @endif
      </div>
    </div>

    {{-- Recent receive shipments --}}
    <div class="ia-card" style="margin-top:18px">
      <div class="ia-card-head">
        <span class="ia-card-title">Recent receive shipments</span>
        @if($recentShipments->count() > 0)
          <span class="ia-text-muted" style="font-size:11.5px">{{ $recentShipments->count() }} {{ Str::plural('shipment', $recentShipments->count()) }}</span>
        @endif
      </div>
      @if($recentShipments->isEmpty())
        <div class="ia-card-body">
          <p class="ia-text-muted" style="font-size:13px;margin:0">
            No receive shipments linked to this vendor yet.
          </p>
        </div>
      @else
        <div class="ia-card-body" style="padding-top:8px">
          @foreach($recentShipments as $sh)
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:0.5px solid var(--ia-border);font-size:13px">
              <div>
                <strong>{{ $sh->shipment_number }}</strong>
                <div class="ia-text-muted" style="font-size:11.5px">
                  {{ $sh->received_count }} {{ Str::plural('item', $sh->received_count) }}
                  · {{ ucfirst($sh->status) }}
                </div>
              </div>
              <div class="ia-text-muted" style="font-size:12px;text-align:right">
                @if($sh->received_date){{ \Carbon\Carbon::parse($sh->received_date)->format('M j, Y') }}@else <em>draft</em> @endif
              </div>
            </div>
          @endforeach
        </div>
      @endif
    </div>

    {{-- Delete card — last, separate, with confirmation --}}
    <div class="ia-card" style="margin-top:18px;border-color:rgba(248,113,113,0.18)">
      <div class="ia-card-head">
        <span class="ia-card-title">Danger zone</span>
      </div>
      <div class="ia-card-body">
        <p class="ia-text-muted" style="font-size:12.5px;margin:0 0 12px;line-height:1.55">
          Soft-deletes the vendor. Existing item-vendor pivot rows and SO records still resolve their vendor (via withTrashed). Blocked if any open SOs reference this vendor.
        </p>
        <form method="POST"
              action="{{ route('tenant.vendors.destroy', ['id' => $vendor->id]) }}"
              onsubmit="return confirm('Remove this vendor? Existing data stays linked but the vendor will no longer appear in lists.');">
          @csrf
          @method('DELETE')
          <button type="submit" class="ia-btn ia-btn--danger ia-btn--small">Remove vendor</button>
        </form>
      </div>
    </div>

  </div>

</div>

@push('styles')
<style>
/* VENDOR-SHOW · stat tiles + 2-column grid */

.vendor-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 20px;
}
@media (max-width: 800px) {
  .vendor-stats { grid-template-columns: repeat(2, 1fr); }
}
.vendor-stat {
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-lg);
  padding: 14px 16px;
}
.vendor-stat-label {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-muted); font-weight: 600; margin-bottom: 6px;
}
.vendor-stat-value {
  font-size: 22px; font-weight: 700; letter-spacing: -0.01em;
  font-variant-numeric: tabular-nums;
}
.vendor-stat-sub {
  font-size: 11px; color: var(--ia-text-muted); margin-top: 2px;
}

.vendor-show-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 18px;
}
@media (max-width: 900px) {
  .vendor-show-grid { grid-template-columns: 1fr; }
}
.vendor-show-col { display: flex; flex-direction: column; }

.vendor-contact-grid { display: grid; gap: 10px; }
.vendor-contact-row {
  display: grid;
  grid-template-columns: 80px 1fr;
  gap: 8px;
  font-size: 13px;
}
.vendor-contact-label {
  font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--ia-text-muted); font-weight: 600;
  align-self: center;
}
.vendor-contact-value { color: var(--ia-text); }
.vendor-notes {
  margin-top: 14px; padding-top: 14px; border-top: 0.5px solid var(--ia-border);
}

/* SO status pills — copy-friendly with the design language */
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

/* Small variant of the destructive button */
.ia-btn--danger {
  background: rgba(248,113,113,0.06);
  color: #F87171;
  border: 0.5px solid rgba(248,113,113,0.3);
}
.ia-btn--danger:hover { background: rgba(248,113,113,0.12); }

.ia-table--inset {
  border: none;
  border-top: 0.5px solid var(--ia-border);
  border-radius: 0;
}
</style>
@endpush

@endsection
