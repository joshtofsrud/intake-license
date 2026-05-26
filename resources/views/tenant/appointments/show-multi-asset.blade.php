{{-- MARKER-PATCH-158-D — Multi-asset appointment show view (read-only) --}}
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

  // Totals computed from the asset rollups + any loose (unpinned) items.
  $assetsSubtotal = $appointmentAssets->sum('subtotal_cents');
  $looseSubtotal  = $looseItems->sum('price_cents') + $looseAddons->sum('price_cents');
  $subtotalCents  = $assetsSubtotal + $looseSubtotal;
  // Tax rate from tenant settings, if any
  $taxRate        = (float) ($appointment->tenant->settings['default_tax_rate'] ?? 0);
  $taxCents       = (int) round($subtotalCents * $taxRate / 100);
  $totalCents     = $subtotalCents + $taxCents;

  $serviceCount = $appointmentAssets->sum(fn($a) => $a->items->count()) + $looseItems->count();
  $addonCount   = $appointmentAssets->sum(fn($a) => $a->addons->count()) + $looseAddons->count();

  $updateUrl = route('tenant.appointments.update', $appointment->id);
@endphp

@push('styles')
<style>
.ma-layout {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 24px;
  align-items: start;
}
@media (max-width: 1000px) {
  .ma-layout { grid-template-columns: 1fr; }
}

/* Header */
.ma-page-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  gap: 24px; margin-bottom: 20px;
}
.ma-page-eyebrow {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em;
  color: var(--ia-text-faint, #52525b); margin-bottom: 4px;
}
.ma-page-title {
  font-size: 22px; font-weight: 500; letter-spacing: -0.01em;
  display: flex; align-items: center; gap: 12px;
}
.ma-status-pill {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 3px 10px; border-radius: 4px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  border: 1px solid var(--ia-border);
}
.ma-status-pill--scheduled  { background: rgba(96,165,250,0.12); color: #93c5fd; border-color: rgba(96,165,250,0.25); }
.ma-status-pill--confirmed  { background: rgba(96,165,250,0.12); color: #93c5fd; border-color: rgba(96,165,250,0.25); }
.ma-status-pill--in_progress{ background: rgba(251,191,36,0.12); color: #fcd34d; border-color: rgba(251,191,36,0.25); }
.ma-status-pill--completed  { background: rgba(74,222,128,0.10); color: #86efac; border-color: rgba(74,222,128,0.25); }
.ma-status-pill--pending    { background: var(--ia-surface-3, rgba(255,255,255,0.04)); color: var(--ia-text-dim); border-color: var(--ia-border); }
.ma-page-sub {
  margin-top: 6px;
  font-size: 13px; color: var(--ia-text-dim);
  display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
}
.ma-page-sub .dot { color: var(--ia-text-faint, #52525b); }

/* Customer card */
.ma-customer-card {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 18px;
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  margin-bottom: 20px;
}
.ma-customer-avatar {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(190,242,100,0.15);
  color: var(--ia-accent, #BEF264);
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 500; font-size: 15px;
}
.ma-customer-name { font-size: 15px; font-weight: 500; }
.ma-customer-meta {
  font-size: 12px; color: var(--ia-text-dim);
  display: flex; gap: 14px; margin-top: 2px; flex-wrap: wrap;
}
.ma-customer-meta .sep { color: var(--ia-text-faint, #52525b); }

/* Section heads */
.ma-section-head {
  display: flex; align-items: center; justify-content: space-between;
  margin: 4px 0 14px;
}
.ma-section-title {
  font-size: 13px; font-weight: 500;
  display: flex; align-items: center; gap: 10px;
}
.ma-section-title .count {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
  padding: 2px 7px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  border-radius: 4px;
  color: var(--ia-text-dim);
}
.ma-section-sub {
  font-size: 12px; color: var(--ia-text-dim);
  margin-bottom: 14px; line-height: 1.55;
}

/* Asset cards */
.ma-asset {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
  overflow: hidden;
}
.ma-asset-head {
  display: grid;
  grid-template-columns: 28px 1fr auto;
  gap: 12px;
  align-items: center;
  padding: 14px 18px;
  border-bottom: 1px solid var(--ia-border);
}
.ma-asset-num {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600;
}
.ma-asset-name {
  font-size: 14px; font-weight: 500;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.ma-pill {
  font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
  padding: 2px 6px; border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-pill--persistent {
  background: rgba(190,242,100,0.1);
  color: var(--ia-accent, #BEF264);
  border: 1px solid rgba(190,242,100,0.3);
}
.ma-asset-meta {
  font-size: 12px; color: var(--ia-text-dim); margin-top: 2px;
}
.ma-asset-subtotal {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  text-align: right;
}
.ma-asset-subtotal-label {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  margin-bottom: 2px;
}

/* Services pinned to an asset */
.ma-asset-services { padding: 8px 18px 14px; }
.ma-service-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 10px 12px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  margin-bottom: 6px;
}
.ma-service-row:last-child { margin-bottom: 0; }
.ma-service-name { font-size: 13px; }
.ma-service-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }
.ma-service-tag {
  font-size: 10px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
  padding: 2px 6px; border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-service-tag--addon {
  background: rgba(96,165,250,0.08);
  color: #93c5fd;
}
.ma-service-price {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  min-width: 70px;
  text-align: right;
}
.ma-service-empty {
  font-size: 12px; color: var(--ia-text-dim);
  padding: 8px 12px;
  font-style: italic;
}

/* "Loose" items section — services not pinned to any asset (back-compat) */
.ma-loose-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
  padding: 14px 18px;
}
.ma-loose-title {
  font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-dim); margin-bottom: 12px;
}

/* "Coming in 158-E" affordances — visible but disabled */
.ma-coming-soon {
  width: 100%;
  font: inherit;
  font-size: 13px;
  padding: 14px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 10px;
  color: var(--ia-text-faint, #52525b);
  display: flex; align-items: center; justify-content: center;
  cursor: not-allowed;
}
.ma-coming-soon-inner-service {
  width: 100%;
  font: inherit;
  font-size: 12px;
  padding: 8px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 6px;
  color: var(--ia-text-faint, #52525b);
  margin-top: 8px;
  display: flex; align-items: center; justify-content: center;
  cursor: not-allowed;
}

/* Right rail cards */
.ma-rail { display: flex; flex-direction: column; gap: 12px; }
.ma-rail-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
}
.ma-rail-card-title {
  font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--ia-text-faint, #52525b); margin-bottom: 10px;
}
.ma-rail-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 6px 0;
  border-top: 0.5px solid var(--ia-border);
  font-size: 13px;
}
.ma-rail-row:first-of-type { border-top: 0; padding-top: 0; }
.ma-rail-row .k { color: var(--ia-text-dim); }
.ma-rail-row .v { color: var(--ia-text); font-variant-numeric: tabular-nums; }
.ma-rail-row--total {
  border-top: 1px solid var(--ia-border);
  margin-top: 6px; padding-top: 10px; font-weight: 500;
}
.ma-rail-row--total .v { font-size: 16px; }
.ma-schedule-row {
  display: grid;
  grid-template-columns: 80px 1fr;
  padding: 6px 0;
  font-size: 13px;
  border-top: 0.5px solid var(--ia-border);
}
.ma-schedule-row:first-of-type { border-top: 0; padding-top: 0; }
.ma-schedule-row .lbl {
  color: var(--ia-text-faint, #52525b);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  align-self: center;
}

/* "Use legacy view" banner — for any debugging fallback */
.ma-fallback-banner {
  background: rgba(251,191,36,0.06);
  border: 1px solid rgba(251,191,36,0.18);
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 18px;
  font-size: 12px;
  color: var(--ia-text-dim);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.ma-fallback-banner a {
  color: var(--ia-accent, #BEF264);
  text-decoration: none;
}
.ma-fallback-banner a:hover { text-decoration: underline; }
</style>
@endpush

@section('mobile-back', 'Appointments|' . route('tenant.appointments.index'))

@section('content')
<div class="ia-page" style="padding: 24px 28px 60px; max-width: 1400px; margin: 0 auto;">

  {{-- Header --}}
  <div class="ma-page-head">
    <div>
      <div class="ma-page-eyebrow">Appointment</div>
      <h1 class="ma-page-title">
        {{ $appointment->ra_number }}
        <span class="ma-status-pill ma-status-pill--{{ $appointment->status }}">{{ $statusLabels[$appointment->status] ?? $appointment->status }}</span>
      </h1>
      <div class="ma-page-sub">
        <span>{{ $appointment->appointment_date->format('D M j') }}@if($appointment->appointment_time), {{ \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A') }}@endif</span>
        @if($appointment->resource)
          <span class="dot">·</span>
          <span>{{ $appointment->resource->name }}</span>
        @endif
        <span class="dot">·</span>
        <span>{{ $appointmentAssets->count() }} {{ \Illuminate\Support\Str::plural('asset', $appointmentAssets->count()) }} · {{ $serviceCount + $addonCount }} {{ \Illuminate\Support\Str::plural('service', $serviceCount + $addonCount) }}</span>
      </div>
    </div>
  </div>

  {{-- Customer card --}}
  @if($appointment->customer)
    @php
      $initials = strtoupper(substr($appointment->customer->first_name ?? '?', 0, 1) . substr($appointment->customer->last_name ?? '', 0, 1));
    @endphp
    <div class="ma-customer-card">
      <div class="ma-customer-avatar">{{ $initials }}</div>
      <div>
        <div class="ma-customer-name">{{ $appointment->customer->first_name }} {{ $appointment->customer->last_name }}</div>
        <div class="ma-customer-meta">
          @if($appointment->customer->email)<span>{{ $appointment->customer->email }}</span>@endif
          @if($appointment->customer->email && $appointment->customer->phone)<span class="sep">·</span>@endif
          @if($appointment->customer->phone)<span>{{ $appointment->customer->phone }}</span>@endif
        </div>
      </div>
      <div style="margin-left: auto;">
        <a href="{{ route('tenant.customers.show', $appointment->customer->id) }}"
           class="ia-btn ia-btn--ghost ia-btn--sm">View customer →</a>
      </div>
    </div>
  @endif

  <div class="ma-layout">

    {{-- LEFT: assets + services --}}
    <main>

      <div class="ma-section-head">
        <div class="ma-section-title">
          Assets being serviced
          <span class="count">{{ $appointmentAssets->count() }}</span>
        </div>
      </div>
      <p class="ma-section-sub">Each asset has its own services and add-ons. Subtotals roll up to the total on the right.</p>

      {{-- Render each asset card --}}
      @foreach($appointmentAssets as $idx => $aa)
        @php
          $isExistingAsset = $aa->customerAsset !== null && $aa->customer_asset_id;
        @endphp
        <article class="ma-asset">
          <header class="ma-asset-head">
            <span class="ma-asset-num">{{ $idx + 1 }}</span>
            <div>
              <div class="ma-asset-name">
                {{ $aa->asset_name_snapshot }}
                @if($isExistingAsset)
                  <span class="ma-pill ma-pill--persistent">Existing</span>
                @endif
              </div>
              @if($aa->identifier_snapshot)
                <div class="ma-asset-meta">
                  {{ $aa->identifier_snapshot }}
                  @if($aa->customerAsset && $aa->customerAsset->last_seen_at)
                    · last seen {{ \Carbon\Carbon::parse($aa->customerAsset->last_seen_at)->format('M j, Y') }}
                  @endif
                </div>
              @endif
            </div>
            <div class="ma-asset-subtotal">
              <div class="ma-asset-subtotal-label">Subtotal</div>
              <div>${{ number_format($aa->subtotal_cents / 100, 2) }}</div>
            </div>
          </header>

          <div class="ma-asset-services">
            @forelse($aa->items as $item)
              <div class="ma-service-row">
                <div>
                  <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
                  @if($item->effectiveDurationMinutes() > 0)
                    <div class="ma-service-meta">{{ $item->effectiveDurationMinutes() }} min</div>
                  @endif
                </div>
                <span class="ma-service-tag">Service</span>
                <span class="ma-service-price">${{ number_format($item->effectivePriceCents() / 100, 2) }}</span>
              </div>
            @empty
              @if($aa->addons->isEmpty())
                <div class="ma-service-empty">No services yet.</div>
              @endif
            @endforelse
            @foreach($aa->addons as $addon)
              <div class="ma-service-row">
                <div>
                  <div class="ma-service-name">{{ $addon->addon_name_snapshot }}</div>
                  @if($addon->effectiveDurationMinutes() > 0)
                    <div class="ma-service-meta">{{ $addon->effectiveDurationMinutes() }} min</div>
                  @endif
                </div>
                <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
                <span class="ma-service-price">${{ number_format($addon->effectivePriceCents() / 100, 2) }}</span>
              </div>
            @endforeach

            <button type="button" class="ma-coming-soon-inner-service" disabled
                    title="Add service / add-on interaction lands in patch 158-E">
              + Add service or add-on to this bike (coming in next patch)
            </button>
          </div>
        </article>
      @endforeach

      {{-- Loose items section — only shown if there are unpinned items --}}
      @if($looseItems->isNotEmpty() || $looseAddons->isNotEmpty())
        <div class="ma-loose-card">
          <div class="ma-loose-title">Unassigned services</div>
          @foreach($looseItems as $item)
            <div class="ma-service-row" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
              </div>
              <span class="ma-service-tag">Service</span>
              <span class="ma-service-price">${{ number_format($item->effectivePriceCents() / 100, 2) }}</span>
            </div>
          @endforeach
          @foreach($looseAddons as $addon)
            <div class="ma-service-row" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">{{ $addon->addon_name_snapshot }}</div>
              </div>
              <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
              <span class="ma-service-price">${{ number_format($addon->effectivePriceCents() / 100, 2) }}</span>
            </div>
          @endforeach
          <div style="font-size: 11.5px; color: var(--ia-text-faint, #52525b); margin-top: 8px; line-height: 1.5;">
            These services aren't pinned to any asset. Pinning interaction comes in patch 158-E.
          </div>
        </div>
      @endif

      {{-- Add asset button (read-only placeholder for E) --}}
      <button type="button" class="ma-coming-soon" disabled
              title="Attach asset interaction lands in patch 158-E">
        + Attach asset to this appointment (coming in next patch)
      </button>

    </main>

    {{-- RIGHT RAIL --}}
    <aside class="ma-rail">

      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Totals</div>
        <div class="ma-rail-row"><span class="k">Assets</span><span class="v">{{ $appointmentAssets->count() }}</span></div>
        <div class="ma-rail-row"><span class="k">Services</span><span class="v">{{ $serviceCount }}</span></div>
        @if($addonCount > 0)
          <div class="ma-rail-row"><span class="k">Add-ons</span><span class="v">{{ $addonCount }}</span></div>
        @endif
        <div class="ma-rail-row"><span class="k">Subtotal</span><span class="v">${{ number_format($subtotalCents / 100, 2) }}</span></div>
        @if($taxRate > 0)
          <div class="ma-rail-row"><span class="k">Tax ({{ $taxRate }}%)</span><span class="v">${{ number_format($taxCents / 100, 2) }}</span></div>
        @endif
        <div class="ma-rail-row ma-rail-row--total"><span class="k">Total</span><span class="v">${{ number_format($totalCents / 100, 2) }}</span></div>
      </div>

      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Schedule</div>
        <div class="ma-schedule-row">
          <span class="lbl">Date</span>
          <span>{{ $appointment->appointment_date->format('D M j, Y') }}</span>
        </div>
        @if($appointment->appointment_time)
          <div class="ma-schedule-row">
            <span class="lbl">Time</span>
            <span>{{ \Carbon\Carbon::parse($appointment->appointment_time)->format('g:i A') }}</span>
          </div>
        @endif
        @if($appointment->resource)
          <div class="ma-schedule-row">
            <span class="lbl">Resource</span>
            <span>{{ $appointment->resource->name }}</span>
          </div>
        @endif
        @if($appointment->total_duration_minutes)
          <div class="ma-schedule-row">
            <span class="lbl">Duration</span>
            <span>{{ $appointment->total_duration_minutes }} min</span>
          </div>
        @endif
      </div>

      @if($appointment->staff_notes)
        <div class="ma-rail-card">
          <div class="ma-rail-card-title">Internal note</div>
          <div style="font-size: 13px; color: var(--ia-text); white-space: pre-wrap;">{{ $appointment->staff_notes }}</div>
        </div>
      @endif

      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Payment</div>
        <div class="ma-rail-row">
          <span class="k">Status</span>
          <span class="v" style="text-transform: capitalize;">{{ $appointment->payment_status ?? 'unpaid' }}</span>
        </div>
        @if(($appointment->paid_cents ?? 0) > 0)
          <div class="ma-rail-row">
            <span class="k">Paid</span>
            <span class="v">${{ number_format($appointment->paid_cents / 100, 2) }}</span>
          </div>
        @endif
        <div style="font-size: 11px; color: var(--ia-text-faint, #52525b); margin-top: 10px; line-height: 1.5;">
          Charges, payments, and editing land in patch 158-E.
        </div>
      </div>

      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Status</div>
        <div style="font-size: 12px; color: var(--ia-text-dim); line-height: 1.6;">
          This is the read-only multi-asset view (patch 158-D).
          Status changes, charges, payments, line-item editing, and asset attachment
          all land in the next patch (158-E).
        </div>
      </div>

    </aside>

  </div>
</div>

@endsection
