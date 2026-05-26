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
  grid-template-columns: 28px 1fr auto auto;
  gap: 12px;
  align-items: center;
  padding: 14px 18px;
  border-bottom: 1px solid var(--ia-border);
}
/* MARKER-PATCH-158-E1 */
.ma-asset-detach {
  background: transparent; border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 14px;
  width: 26px; height: 26px;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-asset-detach:hover { color: #f87171; background: rgba(248,113,113,0.08); }
.ma-add-svc-btn {
  width: 100%;
  font: inherit; font-size: 12px;
  padding: 8px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 6px;
  color: var(--ia-text-dim);
  cursor: pointer;
  margin-top: 8px;
}
.ma-add-svc-btn:hover {
  border-color: var(--ia-accent, #BEF264);
  color: var(--ia-accent, #BEF264);
  border-style: solid;
}
.ma-add-asset-btn {
  width: 100%;
  font: inherit; font-size: 13px;
  padding: 14px;
  background: transparent;
  border: 1px dashed var(--ia-border);
  border-radius: 10px;
  color: var(--ia-text-dim);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
}
.ma-add-asset-btn:hover {
  border-color: var(--ia-accent, #BEF264);
  color: var(--ia-accent, #BEF264);
  border-style: solid;
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

/* ============== MARKER-PATCH-158-F — Empty state ============== */
.ma-empty {
  text-align: center;
  padding: 48px 20px;
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px dashed var(--ia-border);
  border-radius: 10px;
  margin-bottom: 12px;
}
.ma-empty-icon {
  width: 48px; height: 48px;
  border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  margin: 0 auto 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: var(--ia-text-faint, #52525b);
}
.ma-empty-title { font-size: 14px; font-weight: 500; margin-bottom: 6px; }
.ma-empty-sub {
  font-size: 12.5px; color: var(--ia-text-dim);
  margin-bottom: 16px;
  max-width: 360px;
  margin-left: auto; margin-right: auto;
  line-height: 1.5;
}

/* ============== MARKER-PATCH-158-E1 — Modals ============== */
.ma-modal-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 999;
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
}
.ma-modal-backdrop.is-open { display: flex; }
.ma-modal {
  background: var(--ia-surface, #111);
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  width: 540px;
  max-width: 100%;
  overflow: hidden;
  display: flex; flex-direction: column;
  max-height: 90vh;
}
.ma-modal-head {
  padding: 16px 20px;
  border-bottom: 1px solid var(--ia-border);
  display: flex; justify-content: space-between; align-items: center;
}
.ma-modal-title { font-size: 14px; font-weight: 500; }
.ma-modal-close {
  background: transparent; border: 0;
  color: var(--ia-text-dim);
  cursor: pointer;
  font-size: 16px;
  padding: 4px 8px;
  border-radius: 4px;
}
.ma-modal-close:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); color: var(--ia-text); }
.ma-modal-body {
  padding: 18px 20px;
  overflow-y: auto;
  flex: 1;
}
.ma-modal-foot {
  padding: 12px 20px;
  border-top: 1px solid var(--ia-border);
  display: flex; justify-content: flex-end; gap: 8px;
}

/* Tabs */
.ma-tabs {
  display: flex;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  padding: 3px;
  margin-bottom: 14px;
}
.ma-tab {
  flex: 1;
  padding: 7px 10px;
  border: 0;
  background: transparent;
  border-radius: 4px;
  font: inherit; font-size: 12px;
  color: var(--ia-text-dim);
  cursor: pointer;
}
.ma-tab.is-active {
  background: var(--ia-surface, #111);
  color: var(--ia-text);
}
.ma-tab-panel { display: none; }
.ma-tab-panel.is-active { display: block; }

/* Picker list (existing assets) */
.ma-picker-list {
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  max-height: 260px;
  overflow-y: auto;
}
.ma-picker-row {
  padding: 10px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex; align-items: center; gap: 12px;
  cursor: pointer;
}
.ma-picker-row:last-child { border-bottom: 0; }
.ma-picker-row:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); }
.ma-picker-radio {
  width: 14px; height: 14px;
  accent-color: var(--ia-accent, #BEF264);
  cursor: pointer;
}
.ma-picker-main { flex: 1; min-width: 0; }
.ma-picker-name { font-size: 13px; }
.ma-picker-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }

/* Catalog list (services / addons) */
.ma-catalog-list {
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  max-height: 300px;
  overflow-y: auto;
}
.ma-catalog-row {
  padding: 9px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex; align-items: center; gap: 12px;
  cursor: pointer;
}
.ma-catalog-row:last-child { border-bottom: 0; }
.ma-catalog-row:hover { background: var(--ia-surface-3, rgba(255,255,255,0.04)); }
.ma-catalog-main { flex: 1; min-width: 0; }
.ma-catalog-name { font-size: 13px; }
.ma-catalog-meta { font-size: 11px; color: var(--ia-text-dim); margin-top: 1px; }
.ma-catalog-price {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  min-width: 70px;
  text-align: right;
}

/* Form rows in modals */
.ma-form-row { margin-bottom: 14px; }
.ma-form-row:last-child { margin-bottom: 0; }
.ma-form-label {
  display: block;
  font-size: 12px;
  color: var(--ia-text-dim);
  margin-bottom: 5px;
}
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

      {{-- MARKER-PATCH-158-F — empty state when no assets attached yet --}}
      @if($appointmentAssets->isEmpty())
        <div class="ma-empty">
          <div class="ma-empty-icon">⊕</div>
          <div class="ma-empty-title">No assets yet</div>
          <div class="ma-empty-sub">
            @if($pickerAssets->isNotEmpty())
              Pick from {{ $appointment->customer->first_name ?? 'this customer' }}'s {{ $pickerAssets->count() }} saved {{ \Illuminate\Support\Str::plural('asset', $pickerAssets->count()) }}, or add a new one.
            @else
              Attach a bike, vehicle, or other item to this appointment.
            @endif
          </div>
          <button type="button" class="ia-btn ia-btn--primary" onclick="maOpenAttachAssetModal()">+ Attach asset</button>
        </div>
      @endif

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
            {{-- MARKER-PATCH-158-E1 — detach button --}}
            <button type="button" class="ma-asset-detach"
                    onclick="maDetachAsset('{{ $aa->id }}', '{{ addslashes($aa->asset_name_snapshot) }}')"
                    title="Remove this asset (services stay on appointment)">
              ✕
            </button>
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

            <button type="button" class="ma-add-svc-btn"
                    onclick="maOpenAddServiceModal('{{ $aa->id }}', '{{ addslashes($aa->asset_name_snapshot) }}')">
              + Add service or add-on to this bike
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

      {{-- MARKER-PATCH-158-E1 — real Attach asset button (only when assets already exist; empty state has its own) --}}
      @if($appointmentAssets->isNotEmpty())
        <button type="button" class="ma-add-asset-btn" onclick="maOpenAttachAssetModal()">
          + Attach asset to this appointment
        </button>
      @endif

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
          Status pipeline, charges, and payment actions land in patch 158-E2.
        </div>
      </div>

    </aside>

  </div>
</div>

{{-- ============== MARKER-PATCH-158-E1 — Modals + JS ============== --}}

{{-- Attach asset modal --}}
<div class="ma-modal-backdrop" id="ma-attach-modal" onclick="if(event.target===this) maCloseModal('ma-attach-modal')">
  <div class="ma-modal" style="width: 560px;">
    <div class="ma-modal-head">
      <div class="ma-modal-title">Attach asset to this appointment</div>
      <button type="button" class="ma-modal-close" onclick="maCloseModal('ma-attach-modal')">✕</button>
    </div>
    <div class="ma-modal-body">

      <div class="ma-tabs">
        <button type="button" class="ma-tab is-active" data-tab="existing" onclick="maSwitchAttachTab('existing')">
          From {{ $appointment->customer->first_name ?? 'this customer' }}'s assets ({{ $pickerAssets->count() }})
        </button>
        <button type="button" class="ma-tab" data-tab="new" onclick="maSwitchAttachTab('new')">
          Add new asset
        </button>
      </div>

      {{-- Existing tab --}}
      <div class="ma-tab-panel is-active" data-panel="existing">
        @if($pickerAssets->isEmpty())
          <div style="padding: 24px; text-align: center; color: var(--ia-text-dim); font-size: 13px; background: var(--ia-surface-2, rgba(255,255,255,0.02)); border: 1px dashed var(--ia-border); border-radius: 8px;">
            No saved assets to attach. Switch to <strong style="color: var(--ia-text);">Add new asset</strong> to create one.
          </div>
        @else
          <div class="ma-picker-list">
            @foreach($pickerAssets as $pa)
              <label class="ma-picker-row">
                <input type="radio" name="picker_asset_id" value="{{ $pa->id }}" class="ma-picker-radio">
                <div class="ma-picker-main">
                  <div class="ma-picker-name">{{ $pa->name }}</div>
                  <div class="ma-picker-meta">
                    @if($pa->identifier){{ $pa->identifier }} · @endif
                    @if($pa->last_seen_at)
                      last seen {{ \Carbon\Carbon::parse($pa->last_seen_at)->format('M j, Y') }}
                    @else
                      never serviced
                    @endif
                  </div>
                </div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

      {{-- New tab --}}
      <div class="ma-tab-panel" data-panel="new">
        <div class="ma-form-row">
          <label class="ma-form-label">Name</label>
          <input id="ma-new-name" class="ia-input" type="text" maxlength="200" placeholder="e.g. Red Cannondale Synapse">
        </div>
        <div class="ma-form-row">
          <label class="ma-form-label">Identifier <span style="color: var(--ia-text-faint, #52525b);">— optional</span></label>
          <input id="ma-new-identifier" class="ia-input" type="text" maxlength="120" placeholder="Serial, license plate, microchip, tag…">
        </div>
        <div class="ma-form-row">
          <label class="ma-form-label">Notes <span style="color: var(--ia-text-faint, #52525b);">— optional</span></label>
          <textarea id="ma-new-notes" class="ia-input" rows="3" maxlength="5000" placeholder="Distinguishing features, prior issues…"></textarea>
        </div>
        <div style="font-size: 11.5px; color: var(--ia-text-dim);">
          Creates a new asset on {{ $appointment->customer->first_name ?? 'the customer' }}'s record AND attaches it to this appointment.
        </div>
      </div>

    </div>
    <div class="ma-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="maCloseModal('ma-attach-modal')">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="ma-attach-submit" onclick="maSubmitAttach()">Attach</button>
    </div>
  </div>
</div>

{{-- Add service-to-asset modal --}}
<div class="ma-modal-backdrop" id="ma-add-svc-modal" onclick="if(event.target===this) maCloseModal('ma-add-svc-modal')">
  <div class="ma-modal" style="width: 560px;">
    <div class="ma-modal-head">
      <div class="ma-modal-title" id="ma-add-svc-title">Add to asset</div>
      <button type="button" class="ma-modal-close" onclick="maCloseModal('ma-add-svc-modal')">✕</button>
    </div>
    <div class="ma-modal-body">

      <div class="ma-tabs">
        <button type="button" class="ma-tab is-active" data-tab="service" onclick="maSwitchSvcTab('service')">
          Services ({{ $availableServices->count() }})
        </button>
        <button type="button" class="ma-tab" data-tab="addon" onclick="maSwitchSvcTab('addon')">
          Add-ons ({{ $availableAddons->count() }})
        </button>
      </div>

      <div class="ma-tab-panel is-active" data-panel="service">
        @if($availableServices->isEmpty())
          <div style="padding: 18px; text-align: center; color: var(--ia-text-dim); font-size: 12.5px;">
            No active services in the catalog.
          </div>
        @else
          <div class="ma-catalog-list">
            @foreach($availableServices as $svc)
              <label class="ma-catalog-row">
                <input type="radio" name="svc_choice" value="service:{{ $svc->id }}" class="ma-picker-radio">
                <div class="ma-catalog-main">
                  <div class="ma-catalog-name">{{ $svc->name }}</div>
                  @if($svc->duration_minutes)
                    <div class="ma-catalog-meta">{{ $svc->duration_minutes }} min</div>
                  @endif
                </div>
                <div class="ma-catalog-price">${{ number_format($svc->price_cents / 100, 2) }}</div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

      <div class="ma-tab-panel" data-panel="addon">
        @if($availableAddons->isEmpty())
          <div style="padding: 18px; text-align: center; color: var(--ia-text-dim); font-size: 12.5px;">
            No active add-ons in the catalog.
          </div>
        @else
          <div class="ma-catalog-list">
            @foreach($availableAddons as $addon)
              <label class="ma-catalog-row">
                <input type="radio" name="svc_choice" value="addon:{{ $addon->id }}" class="ma-picker-radio">
                <div class="ma-catalog-main">
                  <div class="ma-catalog-name">{{ $addon->name }}</div>
                  @if($addon->default_duration_minutes)
                    <div class="ma-catalog-meta">{{ $addon->default_duration_minutes }} min</div>
                  @endif
                </div>
                <div class="ma-catalog-price">${{ number_format($addon->price_cents / 100, 2) }}</div>
              </label>
            @endforeach
          </div>
        @endif
      </div>

    </div>
    <div class="ma-modal-foot">
      <button type="button" class="ia-btn ia-btn--ghost" onclick="maCloseModal('ma-add-svc-modal')">Cancel</button>
      <button type="button" class="ia-btn ia-btn--primary" id="ma-add-svc-submit" onclick="maSubmitAddService()">Add</button>
    </div>
  </div>
</div>

<script>
// MARKER-PATCH-158-E1
(function() {
  const APPT_URL = {!! json_encode(route('tenant.appointments.update', $appointment->id)) !!};
  const CSRF     = {!! json_encode(csrf_token()) !!};

  // Currently-targeted asset for "add service to asset" modal
  let currentAssetId = null;

  // Generic post helper
  async function post(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
    fd.append('_method', 'PATCH');
    fd.append('_token', CSRF);
    const r = await fetch(APPT_URL, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    });
    let data = null;
    try { data = await r.json(); } catch (e) {}
    return { ok: r.ok && data && data.ok, message: data?.message || `HTTP ${r.status}`, data };
  }

  function openModal(id)  { document.getElementById(id).classList.add('is-open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('is-open'); }
  window.maCloseModal = closeModal;

  // ---------------------- Attach asset ----------------------
  window.maOpenAttachAssetModal = function() {
    // reset
    document.getElementById('ma-new-name').value = '';
    document.getElementById('ma-new-identifier').value = '';
    document.getElementById('ma-new-notes').value = '';
    document.querySelectorAll('input[name="picker_asset_id"]').forEach(r => r.checked = false);
    // default to existing tab unless no existing
    const hasExisting = document.querySelectorAll('input[name="picker_asset_id"]').length > 0;
    maSwitchAttachTab(hasExisting ? 'existing' : 'new');
    openModal('ma-attach-modal');
  };

  window.maSwitchAttachTab = function(tab) {
    document.querySelectorAll('#ma-attach-modal .ma-tab').forEach(t => {
      t.classList.toggle('is-active', t.dataset.tab === tab);
    });
    document.querySelectorAll('#ma-attach-modal .ma-tab-panel').forEach(p => {
      p.classList.toggle('is-active', p.dataset.panel === tab);
    });
  };

  window.maSubmitAttach = async function() {
    const btn = document.getElementById('ma-attach-submit');
    btn.disabled = true;
    const activeTab = document.querySelector('#ma-attach-modal .ma-tab.is-active')?.dataset.tab;

    let result;
    if (activeTab === 'existing') {
      const sel = document.querySelector('input[name="picker_asset_id"]:checked');
      if (!sel) { alert('Pick an asset first, or use the "Add new asset" tab.'); btn.disabled = false; return; }
      result = await post({ op: 'attach_existing_asset', customer_asset_id: sel.value });
    } else {
      const name = document.getElementById('ma-new-name').value.trim();
      if (!name) { alert('Name is required.'); btn.disabled = false; return; }
      result = await post({
        op: 'attach_new_asset',
        name,
        identifier: document.getElementById('ma-new-identifier').value.trim(),
        notes:      document.getElementById('ma-new-notes').value.trim(),
      });
    }

    btn.disabled = false;
    if (!result.ok) { alert('Attach failed: ' + result.message); return; }
    location.reload();
  };

  // ---------------------- Add service to asset ----------------------
  window.maOpenAddServiceModal = function(appointmentAssetId, assetName) {
    currentAssetId = appointmentAssetId;
    document.getElementById('ma-add-svc-title').textContent = 'Add to ' + assetName;
    document.querySelectorAll('input[name="svc_choice"]').forEach(r => r.checked = false);
    maSwitchSvcTab('service');
    openModal('ma-add-svc-modal');
  };

  window.maSwitchSvcTab = function(tab) {
    document.querySelectorAll('#ma-add-svc-modal .ma-tab').forEach(t => {
      t.classList.toggle('is-active', t.dataset.tab === tab);
    });
    document.querySelectorAll('#ma-add-svc-modal .ma-tab-panel').forEach(p => {
      p.classList.toggle('is-active', p.dataset.panel === tab);
    });
  };

  window.maSubmitAddService = async function() {
    const sel = document.querySelector('input[name="svc_choice"]:checked');
    if (!sel) { alert('Pick a service or add-on first.'); return; }
    const btn = document.getElementById('ma-add-svc-submit');
    btn.disabled = true;
    const [kind, id] = sel.value.split(':');
    const payload = { op: 'add_service_to_asset', appointment_asset_id: currentAssetId, kind };
    if (kind === 'service') payload.service_item_id = id;
    else                    payload.addon_id        = id;
    const result = await post(payload);
    btn.disabled = false;
    if (!result.ok) { alert('Add failed: ' + result.message); return; }
    location.reload();
  };

  // ---------------------- Detach asset ----------------------
  window.maDetachAsset = async function(appointmentAssetId, assetName) {
    if (!confirm('Detach "' + assetName + '" from this appointment?\n\nServices on this asset will move to "Unassigned services" rather than being deleted.')) return;
    const result = await post({ op: 'detach_asset', appointment_asset_id: appointmentAssetId });
    if (!result.ok) { alert('Detach failed: ' + result.message); return; }
    location.reload();
  };

  // Escape closes any open modal
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.ma-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
  });
})();
</script>

@endsection
