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

  // MARKER-PATCH-158-E2 — status pipeline (mirrors legacy show.blade.php)
  $isTerminal    = in_array($appointment->status, ['cancelled', 'refunded']);
  $pipelineSteps = ['pending', 'confirmed', 'in_progress', 'completed'];
  if ($appointment->status === 'shipped') $pipelineSteps[] = 'shipped';
  if ($appointment->status === 'closed')  $pipelineSteps[] = 'closed';
  $currentIndex = array_search($appointment->status, $pipelineSteps);
  if ($currentIndex === false) $currentIndex = 0;
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

/* ============== MARKER-PATCH-158-G3 — Top row (status | customer tile) ============== */
.ma-top-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 18px;
  align-items: stretch;
}
@media (max-width: 900px) {
  .ma-top-row { grid-template-columns: 1fr; }
}
.ma-top-tile {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
}
.ma-top-tile-label {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ia-text-faint, #52525b);
  margin-bottom: 14px;
}

/* When the progress card is a top tile, drop its outer padding/margins and
   let the tile container handle them. The bar centers vertically in the
   remaining space so it visually aligns with the right-tile content. */
.ma-top-tile.ma-progress-card {
  padding: 16px 20px;
  margin-bottom: 0;
  justify-content: flex-start;
}
.ma-top-tile.ma-terminal-card {
  margin-bottom: 0;
  align-items: center;
  flex-direction: row;
  gap: 12px;
}

/* Customer header inside the right tile */
.ma-top-customer {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.ma-top-customer-main {
  flex: 1;
  min-width: 0;
}
.ma-top-customer-main .ma-customer-name {
  font-size: 14px;
  font-weight: 500;
}
.ma-top-customer-main .ma-customer-meta {
  font-size: 11.5px;
  color: var(--ia-text-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 2px;
  display: block;
}
.ma-top-customer-main .ma-customer-meta .sep { margin: 0 4px; opacity: 0.6; }
.ma-top-view-link {
  flex-shrink: 0;
  font-size: 12px;
  color: var(--ia-accent, #BEF264);
  text-decoration: none;
}
.ma-top-view-link:hover { text-decoration: underline; }

/* Resource picker row inside right tile */
.ma-top-resource {
  padding-top: 12px;
  border-top: 0.5px solid var(--ia-border);
  margin-bottom: 12px;
}
.ma-top-resource-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Actions row inside right tile */
.ma-top-actions {
  display: flex;
  gap: 8px;
  padding-top: 12px;
  border-top: 0.5px solid var(--ia-border);
  margin-top: auto; /* push actions to bottom of tile when tile is taller */
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

/* Customer avatar + name + meta — used in the top-row tile (G3) */
.ma-customer-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: rgba(190,242,100,0.15);
  color: var(--ia-accent, #BEF264);
  display: inline-flex; align-items: center; justify-content: center;
  font-weight: 500; font-size: 13px;
  flex-shrink: 0;
}
.ma-customer-name { font-size: 14px; font-weight: 500; }
.ma-customer-meta {
  font-size: 12px; color: var(--ia-text-dim);
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
  grid-template-columns: 1fr 90px 90px 24px;
  gap: 10px;
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

/* ============== MARKER-PATCH-158-E2 — Status pipeline (mirrors legacy) ============== */
.ma-progress-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 18px 22px;
  margin-bottom: 16px;
}
.ma-progress-bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  position: relative;
  gap: 4px;
}
.ma-progress-bar::before {
  content: '';
  position: absolute;
  top: 12px; left: 12px; right: 12px;
  height: 2px; background: var(--ia-border);
  z-index: 0;
}
.ma-progress-bar::after {
  content: '';
  position: absolute;
  top: 12px; left: 12px;
  height: 2px; background: var(--ia-accent, #BEF264);
  z-index: 0;
  /* MARKER-PATCH-158-G1 — fixed overshoot: legacy uses fraction (0..1) of
     (100% - 24px) to account for the 12px padding on each side. */
  width: calc((100% - 24px) * var(--progress, 0));
  transition: width 0.3s;
}
.ma-progress-step {
  position: relative;
  z-index: 1;
  background: transparent;
  border: 0;
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  cursor: pointer;
  padding: 0;
  font: inherit;
  flex: 1;
}
.ma-progress-step:disabled { cursor: default; }
.ma-progress-dot {
  width: 26px; height: 26px;
  border-radius: 50%;
  background: var(--ia-surface, #111);
  border: 2px solid var(--ia-border);
  display: flex; align-items: center; justify-content: center;
  color: var(--ia-accent-text, #0a0a0a);
  transition: all 0.15s;
}
.ma-progress-step.is-done .ma-progress-dot {
  background: var(--ia-accent, #BEF264);
  border-color: var(--ia-accent, #BEF264);
}
.ma-progress-step.is-current .ma-progress-dot {
  border: 2px solid var(--ia-accent, #BEF264);
  background: var(--ia-surface, #111);
}
.ma-progress-dot-inner {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--ia-accent, #BEF264);
}
.ma-progress-label {
  font-size: 11px;
  color: var(--ia-text-dim);
  transition: color 0.15s;
}
.ma-progress-step.is-current .ma-progress-label {
  font-weight: 500;
  color: var(--ia-text);
}
.ma-progress-step:not(:disabled):hover .ma-progress-dot {
  border-color: var(--ia-accent, #BEF264);
}
.ma-progress-step.is-saving .ma-progress-dot { opacity: 0.5; }

.ma-terminal-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 12px;
}
.ma-terminal-icon {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  color: var(--ia-text-dim);
}
.ma-terminal-title { font-size: 13px; font-weight: 500; }

/* Inline edits + remove on service rows */
.ma-service-edit {
  width: 70px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  color: var(--ia-text);
  font: inherit; font-size: 12.5px;
  padding: 3px 6px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-service-edit:hover { border-color: var(--ia-border); }
.ma-service-edit:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-service-remove {
  background: transparent; border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 12px;
  width: 22px; height: 22px;
  border-radius: 3px;
  cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-service-remove:hover { color: #f87171; background: rgba(248,113,113,0.08); }

/* ============== MARKER-PATCH-158-E3 — Charges + Payment ============== */
.ma-charges-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-charges-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
}
.ma-add-charge-form {
  margin-bottom: 14px;
  padding-bottom: 14px;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-charge-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 0;
  border-bottom: 0.5px solid var(--ia-border);
  font-size: 13px;
}
.ma-charge-row:last-child { border-bottom: 0; }

/* ============== MARKER-PATCH-158-G4 — Per-asset Parts section ============== */

/* The collapsible Parts section lives inside each asset card, just below
   the services list. <details> drives the open/closed state with no JS. */
.ma-asset-parts {
  border-top: 0.5px solid var(--ia-border);
  margin-top: 8px;
}
/* MARKER-PATCH-158-G6 — Horizontal padding so the collapsible content
   doesn't sit flush against the asset card edges. Matches .ma-asset-head's
   18px so labels/inputs align vertically with the card title above. */
.ma-asset-parts-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px 8px;
  cursor: pointer;
  user-select: none;
  list-style: none;
}
.ma-asset-parts-head::-webkit-details-marker { display: none; }
.ma-asset-parts-title {
  font-size: 12px;
  font-weight: 500;
  color: var(--ia-text);
}
.ma-asset-parts-count {
  font-size: 11px;
  font-weight: 500;
  padding: 1px 7px;
  background: var(--ia-surface-2, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
  border-radius: 9px;
  font-variant-numeric: tabular-nums;
}
.ma-asset-parts-chev {
  margin-left: auto;
  font-size: 12px;
  color: var(--ia-text-faint, #52525b);
  transition: transform 0.15s;
}
.ma-asset-parts[open] .ma-asset-parts-chev { transform: rotate(180deg); }

.ma-asset-parts-body {
  padding: 4px 18px 12px;
}
.ma-asset-parts-empty {
  font-size: 12px;
  opacity: .4;
  margin: 4px 0 12px;
}

/* Per-asset picker: same styles as the loose .ma-part-picker, but scoped */
.ma-asset-part-pickerwrap {
  position: relative;
  margin-top: 10px;
}
.ma-asset-part-pickerwrap .ia-input { width: 100%; }
.ma-asset-part-results {
  position: absolute;
  top: 100%; left: 0; right: 0;
  margin-top: 4px;
  background: var(--ia-surface, #111);
  border: 1px solid var(--ia-border);
  border-radius: 6px;
  max-height: 280px;
  overflow-y: auto;
  z-index: 20;
}
.ma-asset-part-results[hidden] { display: none; }

.ma-asset-custom-form {
  margin-top: 10px;
  padding: 12px;
  border: 0.5px solid var(--ia-border);
  border-radius: 6px;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-asset-custom-form[hidden] { display: none; }
.ma-asset-custom-form-head {
  font-size: 12px;
  font-weight: 500;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ma-asset-custom-grid {
  display: grid;
  grid-template-columns: 1.6fr 0.7fr 0.5fr auto;
  gap: 8px;
  align-items: end;
}

/* ============== MARKER-PATCH-158-G5 — Per-asset Work order section ============== */
.ma-asset-wo {
  border-top: 0.5px solid var(--ia-border);
  margin-top: 8px;
}
.ma-asset-wo .ma-asset-parts-head { /* reuse parts head styles */ }
.ma-asset-wo-body {
  /* MARKER-PATCH-158-G6 — Horizontal padding so form fields don't touch
     the asset card edges. Matches .ma-asset-parts-body. */
  padding: 4px 18px 12px;
}
.ma-asset-wo-empty {
  font-size: 12px;
  opacity: .5;
  margin: 4px 0 12px;
}
.ma-asset-wo-id-block {
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-asset-wo-id-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--ia-text-faint, #52525b);
  font-weight: 500;
  margin-bottom: 4px;
}
.ma-asset-wo-id-value {
  font-family: ui-monospace, 'SF Mono', monospace;
  font-size: 15px;
  font-weight: 500;
  letter-spacing: 0.02em;
}
.ma-asset-wo-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px 24px;
}
.ma-asset-wo-field-value { font-size: 13px; }
.ma-asset-wo-edit-form[hidden] { display: none; }
.ma-wo-id-pill {
  background: var(--ia-accent, #BEF264);
  color: var(--ia-accent-text, #0a0a0a);
  font-size: 9px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 3px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-left: 6px;
}

/* ============== MARKER-PATCH-158-E4 — Parts card + table (reused by G4 Unassigned section) ============== */
.ma-parts-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-parts-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ma-parts-table th {
  font-size: 10.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  padding: 6px 0;
  text-align: left;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-parts-table th.num { text-align: right; }
.ma-parts-table td {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
  vertical-align: middle;
}
.ma-parts-table td.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-parts-table tr:last-child td { border-bottom: 0; }
.ma-part-qty-edit {
  width: 60px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 4px;
  color: var(--ia-text);
  font: inherit; font-size: 12.5px;
  padding: 3px 6px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-part-qty-edit:hover { border-color: var(--ia-border); }
.ma-part-qty-edit:focus {
  outline: none;
  border-color: var(--ia-accent, #BEF264);
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
}
.ma-part-qty-edit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ma-part-picker-result {
  padding: 8px 12px;
  cursor: pointer;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-part-picker-result:last-child { border-bottom: 0; }
.ma-part-picker-result:hover,
.ma-part-picker-result.is-active {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-part-picker-result .name {
  font-size: 13px;
}
.ma-part-picker-result .meta {
  font-size: 11px;
  color: var(--ia-text-dim);
  margin-top: 2px;
  display: flex;
  justify-content: space-between;
}
.ma-part-picker-custom {
  padding: 10px 12px;
  cursor: pointer;
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border-top: 0.5px solid var(--ia-border);
  font-size: 12.5px;
  color: var(--ia-accent, #BEF264);
}
.ma-part-picker-custom:hover {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-part-picker-empty {
  padding: 12px;
  text-align: center;
  font-size: 12px;
  color: var(--ia-text-dim);
}

/* ============== MARKER-PATCH-158-E5 — Work order + Notes ============== */
.ma-wo-card,
.ma-notes-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-wo-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
  padding-bottom: 10px;
  border-bottom: 0.5px solid var(--ia-border);
}

/* Notes list styling — mirrors legacy ia-note look */
.ma-note {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-note:first-child { padding-top: 0; }
.ma-note:last-child { border-bottom: 0; padding-bottom: 0; }
.ma-note-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
  font-size: 11.5px;
}
.ma-note-author {
  font-weight: 500;
  color: var(--ia-text);
}
.ma-note-time {
  color: var(--ia-text-faint, #52525b);
}
.ma-note-visibility {
  font-size: 9.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-note-visibility--customer {
  background: rgba(96, 165, 250, 0.10);
  color: #93c5fd;
}
.ma-note-delete {
  background: transparent;
  border: 0;
  color: var(--ia-text-faint, #52525b);
  font-size: 11px;
  width: 18px; height: 18px;
  border-radius: 3px;
  cursor: pointer;
  margin-left: auto;
  display: inline-flex; align-items: center; justify-content: center;
}
.ma-note-delete:hover { color: #f87171; background: rgba(248, 113, 113, 0.08); }
.ma-note-body {
  font-size: 13px;
  white-space: pre-wrap;
  line-height: 1.5;
}
.ma-notes-empty {
  font-size: 13px;
  opacity: .4;
  margin: 0;
}

/* ============== MARKER-PATCH-158-E6 — Special orders + polish ============== */
.ma-so-card {
  background: var(--ia-surface, rgba(255,255,255,0.02));
  border: 1px solid var(--ia-border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-top: 14px;
}
.ma-so-head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 14px;
}
.ma-so-warning {
  background: rgba(245, 158, 11, 0.08);
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 12px;
  font-size: 12.5px;
  line-height: 1.5;
}
.ma-so-warning strong { color: #F59E0B; }
.ma-so-warning span { color: var(--ia-text-dim); }
.ma-so-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ma-so-table th {
  font-size: 10.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--ia-text-faint, #52525b);
  padding: 6px 0;
  text-align: left;
  border-bottom: 0.5px solid var(--ia-border);
}
.ma-so-table th.num { text-align: right; }
.ma-so-table td {
  padding: 10px 0;
  border-bottom: 0.5px solid var(--ia-border);
  vertical-align: middle;
}
.ma-so-table td.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.ma-so-table tr:last-child td { border-bottom: 0; }
.ma-so-table tbody tr:hover {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
}
.ma-so-status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.ma-so-status--needed   { background: rgba(167,139,250,0.10); color: #A78BFA; }
.ma-so-status--ordered  { background: rgba(96,165,250,0.10);  color: #60A5FA; }
.ma-so-status--arrived  { background: rgba(190,242,100,0.10); color: var(--ia-accent, #BEF264); }
.ma-so-status--pulled   { background: rgba(200,200,200,0.06); color: var(--ia-text-dim); }
.ma-so-status--cancelled{ background: rgba(248,113,113,0.10); color: #F87171; text-decoration: line-through; }
.ma-so-status--overdue  { background: rgba(248,113,113,0.15); color: #F87171; }

/* System notes — visually differentiated as activity-log entries */
.ma-note--system {
  background: var(--ia-surface-2, rgba(255,255,255,0.02));
  border-radius: 6px;
  padding: 8px 12px;
  margin: 4px 0;
  border-bottom: 0 !important;
}
.ma-note--system + .ma-note:not(.ma-note--system) { margin-top: 10px; }
.ma-note--system .ma-note-author {
  font-size: 10.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--ia-text-faint, #52525b);
}
.ma-note--system .ma-note-body {
  font-size: 12px;
  color: var(--ia-text-dim);
}

/* ============== MARKER-PATCH-158-G1 — Sale callout banners ============== */
.ma-sale-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  margin-bottom: 16px;
  border-radius: 8px;
}
.ma-sale-banner-icon {
  font-size: 20px;
  line-height: 1;
}
.ma-sale-banner-body { flex: 1; min-width: 0; }
.ma-sale-banner-title {
  font-weight: 500;
  font-size: 13px;
  color: var(--ia-text);
}
.ma-sale-banner-sub {
  font-size: 12px;
  color: var(--ia-text-dim);
  margin-top: 2px;
}
.ma-sale-banner--checkout {
  background: rgba(251, 191, 36, 0.10);
  border: 0.5px solid rgba(251, 191, 36, 0.35);
}
.ma-sale-banner--paid {
  background: rgba(132, 204, 22, 0.08);
  border: 0.5px solid rgba(132, 204, 22, 0.30);
}
.ma-sale-banner--overage {
  background: rgba(251, 191, 36, 0.10);
  border: 0.5px solid rgba(251, 191, 36, 0.45);
}

/* MARKER-PATCH-158-G2 — Rail action stack (reschedule + cancel) */
.ma-rail-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 4px;
}

/* MARKER-PATCH-158-G3 — Cancel button dark-red theme (mirrors legacy CANCEL-RED-DARK).
   Without this, ia-btn--danger renders too light against the dark surface. */
.ma-cancel-btn.ia-btn--danger,
button.ma-cancel-btn {
  background: #6B1F1F !important;
  color: #FFD0D0 !important;
  border: 1px solid #8C2C2C !important;
}
.ma-cancel-btn.ia-btn--danger:hover,
button.ma-cancel-btn:hover {
  background: #8C2C2C !important;
  color: #FFE5E5 !important;
}

/* Payment status badge */
.ma-payment-badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 8px;
  border-radius: 3px;
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-payment-badge--paid {
  background: rgba(74, 222, 128, 0.12);
  color: #86efac;
}
.ma-payment-badge--partial,
.ma-payment-badge--deposit_paid {
  background: rgba(251, 191, 36, 0.12);
  color: #fcd34d;
}
.ma-payment-badge--unpaid {
  background: var(--ia-surface-3, rgba(255,255,255,0.04));
  color: var(--ia-text-dim);
}
.ma-payment-badge--refunded {
  background: rgba(248, 113, 113, 0.10);
  color: #fca5a5;
}

/* Asset name inline edit */
/* MARKER-PATCH-158-E4 — fixed CSS specificity so input doesn't pick up
   browser/ia-input default white background. Higher specificity + !important
   on the visual properties because ia-input wins generic selectors. */
.ma-asset .ma-asset-name-edit,
input.ma-asset-name-edit {
  background: transparent !important;
  border: 1px solid transparent !important;
  border-radius: 4px;
  color: var(--ia-text) !important;
  font: inherit;
  font-size: 14px !important;
  font-weight: 500 !important;
  font-family: var(--ia-font, inherit) !important;
  padding: 2px 6px;
  width: 100%;
  max-width: 100%;
  box-shadow: none !important;
  -webkit-appearance: none;
  appearance: none;
}
.ma-asset .ma-asset-name-edit:hover,
input.ma-asset-name-edit:hover {
  border-color: var(--ia-border) !important;
}
.ma-asset .ma-asset-name-edit:focus,
input.ma-asset-name-edit:focus {
  outline: none !important;
  border-color: var(--ia-accent, #BEF264) !important;
  background: var(--ia-surface-2, rgba(255,255,255,0.02)) !important;
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

  {{-- MARKER-PATCH-158-G1 — Sale callout banners (mirrors legacy bannerSale) --}}
  @php
    $bannerSale     = $appointment->openRegisterSale();
    $bannerBalance  = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
    $bannerOverage  = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
    $bannerPaidFull = ($appointment->payment_status === 'paid');
  @endphp
  @if($bannerSale)
    <div class="ma-sale-banner ma-sale-banner--checkout">
      <span class="ma-sale-banner-icon">💳</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Ready for checkout — ${{ number_format($bannerBalance / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">
          Sale {{ $bannerSale->sale_number }} parked in the register for
          {{ trim(($appointment->customer->first_name ?? '') . ' ' . ($appointment->customer->last_name ?? '')) ?: 'this customer' }}.
        </div>
      </div>
      <a href="{{ route('tenant.register.index', []) }}?resume={{ $bannerSale->id }}"
         class="ia-btn ia-btn--primary ia-btn--sm">Open in register →</a>
    </div>
  @elseif($bannerPaidFull)
    <div class="ma-sale-banner ma-sale-banner--paid">
      <span class="ma-sale-banner-icon">✅</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Paid in full — ${{ number_format(($appointment->paid_cents ?? 0) / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">
          @if($appointment->payments()->count() === 1 && $appointment->payments()->first()->kind === 'deposit')
            Customer prepaid before service. No checkout needed.
          @else
            {{ $appointment->payments()->count() }} {{ $appointment->payments()->count() === 1 ? 'payment' : 'payments' }} on file.
          @endif
        </div>
      </div>
    </div>
  @elseif($bannerOverage > 0)
    <div class="ma-sale-banner ma-sale-banner--overage">
      <span class="ma-sale-banner-icon">⚠</span>
      <div class="ma-sale-banner-body">
        <div class="ma-sale-banner-title">Customer overpaid — ${{ number_format($bannerOverage / 100, 2) }}</div>
        <div class="ma-sale-banner-sub">Refund the overage or adjust the total.</div>
      </div>
    </div>
  @endif

  {{-- MARKER-PATCH-158-G3 — Top row: status pipeline (left) + customer/resource/actions tile (right) --}}
  @php
    $maCurrentResource = $availableResources->firstWhere('id', $appointment->resource_id);
    $maInitials = $appointment->customer
      ? strtoupper(substr($appointment->customer->first_name ?? '?', 0, 1) . substr($appointment->customer->last_name ?? '', 0, 1))
      : '?';
  @endphp
  <div class="ma-top-row">

    {{-- LEFT: Status pipeline (or terminal card) --}}
    @if($isTerminal)
      <div class="ma-top-tile ma-terminal-card">
        <div class="ma-terminal-icon">
          @if($appointment->status === 'cancelled')✕@else↩@endif
        </div>
        <div class="ma-terminal-title">{{ $statusLabels[$appointment->status] ?? $appointment->status }}</div>
        <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm" data-status="pending" id="ma-reopen-btn" style="margin-left:auto;">
          Reopen
        </button>
      </div>
    @else
      <div class="ma-top-tile ma-progress-card">
        <div class="ma-top-tile-label">Status</div>
        <div class="ma-progress-bar"
             data-current-index="{{ $currentIndex }}"
             data-update-url="{{ $updateUrl }}"
             style="--progress: {{ count($pipelineSteps) > 1 ? $currentIndex / (count($pipelineSteps) - 1) : 0 }};">
          @foreach($pipelineSteps as $idx => $step)
            @php
              $stepLabel = $statusLabels[$step] ?? $step;
              $isDone    = $idx < $currentIndex;
              $isCurrent = $idx === $currentIndex;
            @endphp
            <button type="button"
                    class="ma-progress-step {{ $isDone ? 'is-done' : '' }} {{ $isCurrent ? 'is-current' : '' }}"
                    data-status="{{ $step }}"
                    data-step-index="{{ $idx }}"
                    data-label="{{ $stepLabel }}">
              <span class="ma-progress-dot">
                @if($isDone)
                  <svg width="12" height="12" viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                @elseif($isCurrent)
                  <span class="ma-progress-dot-inner"></span>
                @endif
              </span>
              <span class="ma-progress-label">{{ $stepLabel }}</span>
            </button>
          @endforeach
        </div>
      </div>
    @endif

    {{-- RIGHT: Customer + resource + actions tile --}}
    <div class="ma-top-tile" data-appt-resource-card data-appt-id="{{ $appointment->id }}">
      @if($appointment->customer)
        <div class="ma-top-customer">
          <div class="ma-customer-avatar">{{ $maInitials }}</div>
          <div class="ma-top-customer-main">
            <div class="ma-customer-name">{{ $appointment->customer->first_name }} {{ $appointment->customer->last_name }}</div>
            <div class="ma-customer-meta">
              @if($appointment->customer->email)<span>{{ $appointment->customer->email }}</span>@endif
              @if($appointment->customer->email && $appointment->customer->phone)<span class="sep">·</span>@endif
              @if($appointment->customer->phone)<span>{{ $appointment->customer->phone }}</span>@endif
            </div>
          </div>
          <a href="{{ route('tenant.customers.show', $appointment->customer->id) }}"
             class="ma-top-view-link">View →</a>
        </div>
      @endif

      {{-- Resource picker (data attrs match legacy so appointment-resource.js auto-binds) --}}
      <div class="ma-top-resource">
        <div class="ma-top-tile-label" style="margin-bottom: 6px;">Resource</div>
        <div class="ma-top-resource-row">
          @if($maCurrentResource)
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $maCurrentResource->color_hex ?: '#888' }};flex-shrink:0;"></span>
          @else
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#666;flex-shrink:0;"></span>
          @endif
          <select class="ia-input ia-input--sm" data-appt-resource-select style="flex: 1; min-width: 0;">
            @foreach($availableResources as $r)
              <option value="{{ $r->id }}" @selected($r->id === $appointment->resource_id)>
                {{ $r->name }}@if($r->subtitle) · {{ $r->subtitle }}@endif
              </option>
            @endforeach
          </select>
          <button type="button"
                  class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-appt-resource-save
                  style="flex-shrink: 0;">Save</button>
        </div>
      </div>

      {{-- Actions (reschedule + cancel) --}}
      @unless($isTerminal)
        <div class="ma-top-actions">
          <button type="button" class="ia-btn ia-btn--secondary ia-btn--sm appt-b-reschedule-btn" style="flex: 1;">↻ Reschedule</button>
          <button type="button" class="ia-btn ia-btn--danger ia-btn--sm ma-cancel-btn" style="flex: 1;">Cancel</button>
        </div>
      @endunless
    </div>

  </div>

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
                {{-- MARKER-PATCH-158-E2 — inline rename --}}
                <input type="text"
                       class="ma-asset-name-edit asset-name-edit"
                       data-aa-id="{{ $aa->id }}"
                       value="{{ $aa->asset_name_snapshot }}"
                       maxlength="200"
                       title="Click to edit name">
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
              {{-- MARKER-PATCH-158-E2 — inline edits + remove --}}
              <div class="ma-service-row line-row" data-kind="service" data-item-id="{{ $item->id }}">
                <div>
                  <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
                  <div class="ma-service-meta" style="margin-top:1px;">
                    <span class="ma-service-tag">Service</span>
                  </div>
                </div>
                <div style="text-align:right;">
                  <input type="number" min="0" class="ma-service-edit line-edit"
                    data-field="duration_minutes"
                    value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                    title="Duration (minutes)">
                  <span style="font-size:10px;opacity:.5;">min</span>
                </div>
                <div style="text-align:right;">
                  <span style="opacity:.5;font-size:11px;">$</span>
                  <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                    data-field="price_dollars"
                    value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                    title="Price (dollars)">
                </div>
                <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
              </div>
            @empty
              @if($aa->addons->isEmpty())
                <div class="ma-service-empty">No services yet.</div>
              @endif
            @endforelse
            @foreach($aa->addons as $addon)
              <div class="ma-service-row line-row" data-kind="addon" data-item-id="{{ $addon->id }}">
                <div>
                  <div class="ma-service-name">+ {{ $addon->addon_name_snapshot }}</div>
                  <div class="ma-service-meta" style="margin-top:1px;">
                    <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
                  </div>
                </div>
                <div style="text-align:right;">
                  <input type="number" min="0" class="ma-service-edit line-edit"
                    data-field="duration_minutes"
                    value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                    title="Duration (minutes)">
                  <span style="font-size:10px;opacity:.5;">min</span>
                </div>
                <div style="text-align:right;">
                  <span style="opacity:.5;font-size:11px;">$</span>
                  <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                    data-field="price_dollars"
                    value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                    title="Price (dollars)">
                </div>
                <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
              </div>
            @endforeach

            <button type="button" class="ma-add-svc-btn"
                    onclick="maOpenAddServiceModal('{{ $aa->id }}', '{{ addslashes($aa->asset_name_snapshot) }}')">
              + Add service or add-on to this bike
            </button>
          </div>

          {{-- MARKER-PATCH-158-G4 — Parts section per asset (collapsible) --}}
          <details class="ma-asset-parts" data-aa-id="{{ $aa->id }}" @if($aa->parts->isNotEmpty()) open @endif>
            <summary class="ma-asset-parts-head">
              <span class="ma-asset-parts-title">Parts &amp; products</span>
              <span class="ma-asset-parts-count">{{ $aa->parts->count() }}</span>
              <span class="ma-asset-parts-chev">▾</span>
            </summary>
            <div class="ma-asset-parts-body">
              @if($aa->parts->isNotEmpty())
                <table class="ma-parts-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th class="num" style="width: 70px;">Qty</th>
                      <th class="num" style="width: 80px;">Price</th>
                      <th class="num" style="width: 80px;">Total</th>
                      <th style="width: 22px;"></th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($aa->parts as $part)
                      @php
                        $invItem = $part->inventoryItem;
                        $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
                        $stockProjected = ($stockNow !== null && !$part->isCommitted())
                          ? $stockNow - (int) $part->quantity
                          : null;
                      @endphp
                      <tr class="ma-part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
                        <td>
                          <div style="font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span>{{ $part->item_name_snapshot }}</span>
                            @if(!$part->inventory_item_id)
                              <span class="ma-pill">Custom</span>
                            @endif
                          </div>
                          @if($part->item_sku_snapshot)
                            <div style="font-size: 11px; opacity: .45; font-family: ui-monospace, 'SF Mono', monospace; margin-top: 2px;">{{ $part->item_sku_snapshot }}</div>
                          @endif
                          @if($stockNow !== null)
                            <div style="font-size: 11px; opacity: .55; margin-top: 3px;">
                              @if($part->isCommitted())
                                Stock decremented · current: {{ $stockNow }}
                              @else
                                Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                              @endif
                            </div>
                          @endif
                        </td>
                        <td class="num">
                          <input type="number" min="1" max="999"
                            class="ma-part-qty-edit"
                            value="{{ $part->quantity }}"
                            data-part-id="{{ $part->id }}"
                            {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}>
                        </td>
                        <td class="num">${{ number_format($part->effectiveUnitPriceCents() / 100, 2) }}</td>
                        <td class="num" data-line-total>${{ number_format($part->lineTotalCents() / 100, 2) }}</td>
                        <td>
                          <button type="button" class="ma-service-remove ma-part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              @else
                <p class="ma-asset-parts-empty">No products yet.</p>
              @endif

              {{-- Per-asset picker. Same UI as the loose picker but scoped via data-aa-id. --}}
              <div class="ma-asset-part-pickerwrap">
                <input type="text" class="ia-input ma-asset-part-picker"
                       data-aa-id="{{ $aa->id }}"
                       placeholder="+ Add product or custom item to this bike…"
                       autocomplete="off">
                <div class="ma-asset-part-results" data-aa-id="{{ $aa->id }}" hidden></div>
              </div>

              {{-- Per-asset custom item form (hidden until user clicks "+ Custom item" in picker) --}}
              <div class="ma-asset-custom-form" data-aa-id="{{ $aa->id }}" hidden>
                <div class="ma-asset-custom-form-head">
                  <span>Custom item</span>
                  <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-custom-cancel" data-aa-id="{{ $aa->id }}" style="padding: 2px 8px; font-size: 11px;">Cancel</button>
                </div>
                <div class="ma-asset-custom-grid">
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Name</label>
                    <input type="text" class="ia-input ma-asset-custom-name" maxlength="255" placeholder="e.g. Special-order grommet">
                  </div>
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Price</label>
                    <input type="number" class="ia-input ma-asset-custom-price" min="0" step="0.01" placeholder="0.00" style="text-align: right;">
                  </div>
                  <div>
                    <label class="ma-form-label" style="font-size: 11px; margin-bottom: 4px;">Qty</label>
                    <input type="number" class="ia-input ma-asset-custom-qty" min="1" max="999" value="1" style="text-align: right;">
                  </div>
                  <div>
                    <button type="button" class="ia-btn ia-btn--primary ia-btn--sm ma-asset-custom-save" data-aa-id="{{ $aa->id }}">Add</button>
                  </div>
                </div>
              </div>
            </div>
          </details>

          {{-- MARKER-PATCH-158-G5 — Work order details section per asset (collapsible).
               Renders only when the tenant has work-order fields configured.
               Responses are keyed by (appointment_id, field_id, appointment_asset_id). --}}
          @if($appointment->workOrderFields && $appointment->workOrderFields->isNotEmpty())
            @php
              $aaResponses        = $aa->workOrderResponses->keyBy('field_id');
              $aaIdentifierField  = $appointment->workOrderFields->firstWhere('is_identifier', true);
              $aaIdentifierValue  = $aaIdentifierField ? ($aaResponses[$aaIdentifierField->id]->response_value ?? null) : null;
              $aaNonIdentifier    = $appointment->workOrderFields->filter(fn($f) => !$f->is_identifier);
              $aaFilledCount      = $appointment->workOrderFields->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null))->count();
            @endphp
            <details class="ma-asset-wo" data-aa-id="{{ $aa->id }}" @if($aaFilledCount > 0) open @endif>
              <summary class="ma-asset-parts-head">
                <span class="ma-asset-parts-title">Work order details</span>
                <span class="ma-asset-parts-count">{{ $aaFilledCount }}/{{ $appointment->workOrderFields->count() }}</span>
                <span class="ma-asset-parts-chev">▾</span>
              </summary>
              <div class="ma-asset-wo-body">

                {{-- Display mode --}}
                <div class="ma-asset-wo-display" data-aa-id="{{ $aa->id }}">
                  @if($aaIdentifierField && $aaIdentifierValue)
                    <div class="ma-asset-wo-id-block">
                      <div class="ma-asset-wo-id-label">{{ $aaIdentifierField->label }}</div>
                      <div class="ma-asset-wo-id-value">{{ $aaIdentifierValue }}</div>
                    </div>
                  @endif

                  @php $aaFilledNonId = $aaNonIdentifier->filter(fn($f) => !empty($aaResponses[$f->id]->response_value ?? null)); @endphp
                  @if($aaFilledNonId->isEmpty() && (!$aaIdentifierField || !$aaIdentifierValue))
                    <p class="ma-asset-wo-empty">No details yet — click <strong>Edit</strong> to add.</p>
                  @elseif($aaFilledNonId->isNotEmpty())
                    <div class="ma-asset-wo-grid">
                      @foreach($aaFilledNonId as $field)
                        <div>
                          <div class="ma-asset-wo-id-label">{{ $field->label }}</div>
                          <div class="ma-asset-wo-field-value">{{ $aaResponses[$field->id]->response_value }}</div>
                        </div>
                      @endforeach
                    </div>
                  @endif

                  <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-wo-edit-toggle" data-aa-id="{{ $aa->id }}" style="margin-top: 10px;">
                    Edit
                  </button>
                </div>

                {{-- Edit mode --}}
                <form class="ma-asset-wo-edit-form" data-aa-id="{{ $aa->id }}" data-update-url="{{ $updateUrl }}" hidden>
                  <input type="hidden" name="appointment_asset_id" value="{{ $aa->id }}">

                  @foreach($appointment->workOrderFields as $field)
                    @php $currentValue = $aaResponses[$field->id]->response_value ?? ''; @endphp
                    <div class="ma-form-row">
                      <label class="ma-form-label">
                        {{ $field->label }}
                        @if($field->is_identifier)
                          <span class="ma-wo-id-pill">ID</span>
                        @endif
                        @if($field->is_required)
                          <span style="color: #f87171;">*</span>
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
                        <div style="font-size: 11px; color: var(--ia-text-dim); margin-top: 4px;">{{ $field->help_text }}</div>
                      @endif
                    </div>
                  @endforeach

                  <div style="display: flex; gap: 8px; margin-top: 14px;">
                    <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save</button>
                    <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm ma-asset-wo-edit-cancel" data-aa-id="{{ $aa->id }}">Cancel</button>
                  </div>
                </form>

              </div>
            </details>
          @endif
        </article>
      @endforeach

      {{-- Loose items section — only shown if there are unpinned items --}}
      @if($looseItems->isNotEmpty() || $looseAddons->isNotEmpty())
        <div class="ma-loose-card">
          <div class="ma-loose-title">Unassigned services</div>
          @foreach($looseItems as $item)
            <div class="ma-service-row line-row" data-kind="service" data-item-id="{{ $item->id }}" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">{{ $item->item_name_snapshot }}</div>
                <div class="ma-service-meta" style="margin-top:1px;">
                  <span class="ma-service-tag">Service</span>
                </div>
              </div>
              <div style="text-align:right;">
                <input type="number" min="0" class="ma-service-edit line-edit"
                  data-field="duration_minutes"
                  value="{{ $item->duration_minutes_override ?? $item->duration_minutes_snapshot ?? 0 }}"
                  title="Duration (minutes)">
                <span style="font-size:10px;opacity:.5;">min</span>
              </div>
              <div style="text-align:right;">
                <span style="opacity:.5;font-size:11px;">$</span>
                <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                  data-field="price_dollars"
                  value="{{ number_format(($item->price_cents_override ?? $item->price_cents) / 100, 2, '.', '') }}"
                  title="Price (dollars)">
              </div>
              <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
            </div>
          @endforeach
          @foreach($looseAddons as $addon)
            <div class="ma-service-row line-row" data-kind="addon" data-item-id="{{ $addon->id }}" style="margin-bottom: 6px;">
              <div>
                <div class="ma-service-name">+ {{ $addon->addon_name_snapshot }}</div>
                <div class="ma-service-meta" style="margin-top:1px;">
                  <span class="ma-service-tag ma-service-tag--addon">Add-on</span>
                </div>
              </div>
              <div style="text-align:right;">
                <input type="number" min="0" class="ma-service-edit line-edit"
                  data-field="duration_minutes"
                  value="{{ $addon->duration_minutes_override ?? $addon->duration_minutes_snapshot ?? 0 }}"
                  title="Duration (minutes)">
                <span style="font-size:10px;opacity:.5;">min</span>
              </div>
              <div style="text-align:right;">
                <span style="opacity:.5;font-size:11px;">$</span>
                <input type="number" min="0" step="0.01" class="ma-service-edit line-edit"
                  data-field="price_dollars"
                  value="{{ number_format(($addon->price_cents_override ?? $addon->price_cents) / 100, 2, '.', '') }}"
                  title="Price (dollars)">
              </div>
              <button type="button" class="ma-service-remove line-remove" title="Remove">&#x2715;</button>
            </div>
          @endforeach
          <div style="font-size: 11.5px; color: var(--ia-text-faint, #52525b); margin-top: 8px; line-height: 1.5;">
            These services aren't pinned to any asset. Attach an asset above and add new services to pin them.
          </div>
        </div>
      @endif

      {{-- MARKER-PATCH-158-E1 — real Attach asset button (only when assets already exist; empty state has its own) --}}
      @if($appointmentAssets->isNotEmpty())
        <button type="button" class="ma-add-asset-btn" onclick="maOpenAttachAssetModal()">
          + Attach asset to this appointment
        </button>
      @endif

      {{-- MARKER-PATCH-158-E3 — Additional charges card --}}
      <div class="ma-charges-card">
        <div class="ma-charges-head">
          <div class="ma-section-title">Additional charges</div>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="ma-add-charge-toggle">
            + Add charge
          </button>
        </div>

        <form method="POST" action="{{ $updateUrl }}" class="ma-add-charge-form" id="ma-add-charge-form" style="display: none;">
          @csrf
          @method('PATCH')
          <input type="hidden" name="op" value="add_charge">
          <div style="display: grid; grid-template-columns: 1fr 140px; gap: 10px; margin-bottom: 10px;">
            <div>
              <label class="ma-form-label">Description</label>
              <input type="text" name="description" class="ia-input" placeholder="e.g. New brake cable" required>
            </div>
            <div>
              <label class="ma-form-label">Amount ($)</label>
              <input type="number" name="amount_display" class="ia-input" placeholder="25.00"
                     step="0.01" min="0.01" id="ma-charge-amount-display" required>
              <input type="hidden" name="amount_cents" id="ma-charge-amount-cents">
            </div>
          </div>
          <div style="display: flex; gap: 8px;">
            <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save charge</button>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="ma-add-charge-cancel">Cancel</button>
          </div>
        </form>

        @if($appointment->charges->isEmpty())
          <p style="font-size: 13px; opacity: .4; margin: 0;">No additional charges.</p>
        @else
          @foreach($appointment->charges as $charge)
            <div class="ma-charge-row">
              <div>
                <div style="font-size: 13px;">{{ $charge->description }}</div>
                <div style="font-size: 11px; opacity: .4; margin-top: 1px;">
                  {{ \Carbon\Carbon::parse($charge->created_at)->format('M j') }} ·
                  {{ $charge->is_paid ? 'Paid' : 'Unpaid' }}
                </div>
              </div>
              <div style="font-weight: 500; font-variant-numeric: tabular-nums;">${{ number_format($charge->amount_cents / 100, 2) }}</div>
            </div>
          @endforeach

          <div class="ma-charge-row" style="font-weight: 500; border-bottom: 0; padding-top: 10px;">
            <span>Charges total</span>
            <span style="font-variant-numeric: tabular-nums;">${{ number_format($appointment->charges->sum('amount_cents') / 100, 2) }}</span>
          </div>
        @endif
      </div>

      {{-- MARKER-PATCH-158-G4 — Unassigned parts (only shown if any parts are unpinned).
           Parts pinned to an asset live in that asset's collapsible Parts section above. --}}
      @if($looseParts->isNotEmpty())
        <div class="ma-parts-card">
          <div class="ma-charges-head">
            <div class="ma-section-title">Unassigned parts</div>
            <div style="font-size: 11px; color: var(--ia-text-faint, #52525b);">
              Not pinned to any specific asset
            </div>
          </div>

          <table class="ma-parts-table">
            <thead>
              <tr>
                <th>Item</th>
                <th class="num" style="width: 80px;">Qty</th>
                <th class="num" style="width: 90px;">Price</th>
                <th class="num" style="width: 90px;">Total</th>
                <th style="width: 28px;"></th>
              </tr>
            </thead>
            <tbody>
              @foreach($looseParts as $part)
                @php
                  $invItem = $part->inventoryItem;
                  $stockNow = $invItem ? (int) ($invItem->computed_stock_count ?? 0) : null;
                  $stockProjected = ($stockNow !== null && !$part->isCommitted())
                    ? $stockNow - (int) $part->quantity
                    : null;
                @endphp
                <tr class="ma-part-row" data-part-id="{{ $part->id }}" data-committed="{{ $part->isCommitted() ? '1' : '0' }}">
                  <td>
                    <div style="font-weight: 500; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <span>{{ $part->item_name_snapshot }}</span>
                      @if(!$part->inventory_item_id)
                        <span class="ma-pill">Custom</span>
                      @endif
                    </div>
                    @if($part->item_sku_snapshot)
                      <div style="font-size: 11px; opacity: .45; font-family: ui-monospace, 'SF Mono', monospace; margin-top: 2px;">{{ $part->item_sku_snapshot }}</div>
                    @endif
                    @if($stockNow !== null)
                      <div style="font-size: 11px; opacity: .55; margin-top: 3px;">
                        @if($part->isCommitted())
                          Stock decremented · current: {{ $stockNow }}
                        @else
                          Stock: {{ $stockNow }} → {{ $stockProjected }} on completion
                        @endif
                      </div>
                    @endif
                  </td>
                  <td class="num">
                    <input type="number" min="1" max="999"
                      class="ma-part-qty-edit"
                      value="{{ $part->quantity }}"
                      data-part-id="{{ $part->id }}"
                      {{ ($part->isCommitted() && $part->inventory_item_id) ? 'disabled' : '' }}>
                  </td>
                  <td class="num">${{ number_format($part->effectiveUnitPriceCents() / 100, 2) }}</td>
                  <td class="num" data-line-total>${{ number_format($part->lineTotalCents() / 100, 2) }}</td>
                  <td>
                    <button type="button" class="ma-service-remove ma-part-remove" data-part-id="{{ $part->id }}" title="Remove">&#x2715;</button>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      {{-- MARKER-PATCH-158-E6 — Special-order parts --}}
      @isset($specialOrdersForAppt)
        @php
          $unArrivedSos = $specialOrdersForAppt->whereIn('status', ['needed', 'ordered']);
          $showBlockWarning = $appointment->status === 'in_progress' && $unArrivedSos->isNotEmpty();
        @endphp
        <div class="ma-so-card" id="ma-so-parts-card" style="{{ $showBlockWarning ? 'border-left: 3px solid #F59E0B;' : '' }}">
          <div class="ma-so-head">
            <div class="ma-section-title">Special-order parts</div>
            <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm"
                    onclick='SoDrawer.open({customer_id: @json($appointment->customer_id), customer_label: @json(trim(($appointment->customer->first_name ?? "") . " " . ($appointment->customer->last_name ?? ""))), appointment_id: @json($appointment->id), alloc_mode: "customer_appt"})'>
              + SO for this appointment
            </button>
          </div>

          @if($showBlockWarning)
            <div class="ma-so-warning">
              <strong>⚠ {{ $unArrivedSos->count() }} part{{ $unArrivedSos->count() === 1 ? '' : 's' }} not yet arrived.</strong>
              <span>Completing this appointment will leave the customer waiting on parts. Consider waiting until parts arrive, or proceed if customer is OK with split pickup.</span>
            </div>
          @endif

          @if($specialOrdersForAppt->isEmpty())
            <p style="font-size: 13px; color: var(--ia-text-dim); padding: 6px 0; margin: 0;">No special-order parts on this appointment.</p>
          @else
            <table class="ma-so-table">
              <thead>
                <tr>
                  <th>Part</th>
                  <th class="num" style="width: 60px;">Qty</th>
                  <th style="width: 110px;">Status</th>
                  <th style="width: 80px;">ETA</th>
                  <th>Vendor</th>
                  <th style="width: 80px;">SO #</th>
                </tr>
              </thead>
              <tbody>
                @foreach($specialOrdersForAppt as $so)
                  @php
                    $isOverdue = $so->status === 'ordered' && $so->expected_arrival_date && $so->expected_arrival_date->isPast();
                    $rowOpacity = in_array($so->status, ['pulled', 'cancelled']) ? '0.55' : '1';
                  @endphp
                  <tr style="cursor: pointer; opacity: {{ $rowOpacity }};"
                      onclick="window.location.href='{{ route('tenant.special-orders.show', ['id' => $so->id]) }}'">
                    <td><strong>{{ $so->item_name_snapshot }}</strong></td>
                    <td class="num">{{ $so->quantity }}</td>
                    <td>
                      <span class="ma-so-status ma-so-status--{{ $isOverdue ? 'overdue' : $so->status }}">{{ $isOverdue ? 'Overdue' : ucfirst($so->status) }}</span>
                    </td>
                    <td style="color: var(--ia-text-dim); font-size: 12px;">
                      @if($so->expected_arrival_date){{ $so->expected_arrival_date->format('M j') }}@else — @endif
                    </td>
                    <td style="color: var(--ia-text-dim); font-size: 12px;">{{ $so->vendor?->name ?? 'TBD' }}</td>
                    <td style="font-size: 11px; color: var(--ia-text-dim);">{{ $so->so_number }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @endif
        </div>

        @include('tenant.special-orders._drawer', ['vendors' => $soVendors ?? collect()])
      @endisset

      {{-- MARKER-PATCH-158-G5 — Bottom work-order card removed; now per-asset inside each asset card --}}

      {{-- MARKER-PATCH-158-E5 — Notes card --}}
      <div class="ma-notes-card" id="ma-notes-card">
        <div class="ma-charges-head">
          <div class="ma-section-title">Notes</div>
        </div>

        <div style="margin-bottom: 14px;">
          <textarea id="ma-note-input" rows="3" maxlength="500"
            placeholder="Add a note…" class="ia-input"
            style="width: 100%; resize: vertical; font-family: inherit;"></textarea>
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 8px;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ia-text-dim); cursor: pointer;">
              <input type="checkbox" id="ma-note-customer-visible" style="accent-color: var(--ia-accent, #BEF264);">
              Also show to customer
            </label>
            <div style="display: flex; align-items: center; gap: 10px;">
              <span id="ma-note-char-count" style="font-size: 11px; color: var(--ia-text-dim); font-variant-numeric: tabular-nums;">500</span>
              <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="ma-note-submit">
                Add note
              </button>
            </div>
          </div>
          <p id="ma-note-error" style="font-size: 12px; color: #f87171; margin-top: 6px; display: none;"></p>
        </div>

        <div id="ma-notes-list">
          @forelse($appointment->notes->sortByDesc('created_at') as $note)
            <div class="ma-note {{ $note->note_type === 'system' ? 'ma-note--system' : '' }}" data-note-id="{{ $note->id }}">
              <div class="ma-note-head">
                <span class="ma-note-author">
                  {{ $note->user?->name ?? ($note->note_type === 'system' ? 'Activity' : 'Staff') }}
                </span>
                @if($note->is_customer_visible)
                  <span class="ma-note-visibility ma-note-visibility--customer">Customer-visible</span>
                @endif
                <span class="ma-note-time">
                  {{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}
                </span>
                @if($note->note_type !== 'system')
                  <button type="button" class="ma-note-delete"
                    data-note-id="{{ $note->id }}"
                    title="Delete">&#x2715;</button>
                @endif
              </div>
              <div class="ma-note-body">{{ $note->note_content }}</div>
            </div>
          @empty
            <p class="ma-notes-empty">No notes yet.</p>
          @endforelse
        </div>
      </div>

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

      {{-- MARKER-PATCH-158-E3 — Payment ledger (mirrors legacy show.blade.php) --}}
      @php
        $payments      = $appointment->payments;
        $balanceDue    = max(0, (int)$appointment->total_cents - (int)$appointment->paid_cents);
        $overage       = max(0, (int)$appointment->paid_cents - (int)$appointment->total_cents);
        $openSale      = $appointment->openRegisterSale();
        $hasOpenSale   = $openSale !== null;
      @endphp
      <div class="ma-rail-card">
        <div class="ma-rail-card-title">Payment</div>

        <div class="ma-rail-row">
          <span class="k">Status</span>
          <span class="v" style="text-transform: capitalize;">
            <span class="ma-payment-badge ma-payment-badge--{{ $appointment->payment_status }}">
              {{ ucwords(str_replace('_', ' ', $appointment->payment_status ?? 'unpaid')) }}
            </span>
          </span>
        </div>
        <div class="ma-rail-row">
          <span class="k">Subtotal</span>
          <span class="v">${{ number_format($appointment->subtotal_cents / 100, 2) }}</span>
        </div>
        @if(($appointment->tax_cents ?? 0) > 0)
          <div class="ma-rail-row">
            <span class="k">Tax</span>
            <span class="v">${{ number_format($appointment->tax_cents / 100, 2) }}</span>
          </div>
        @endif
        <div class="ma-rail-row" style="font-weight: 500;">
          <span class="k">Total</span>
          <span class="v" style="font-size: 16px;">${{ number_format($appointment->total_cents / 100, 2) }}</span>
        </div>

        @if($payments->isNotEmpty())
          <div style="margin-top:14px;padding-top:12px;border-top:0.5px solid var(--ia-border);">
            <div style="font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--ia-text-dim); font-weight: 600; margin-bottom: 8px;">Ledger</div>
            @foreach($payments as $p)
              <div style="display: flex; justify-content: space-between; align-items: flex-start; font-size: 12px; padding: 6px 0; border-bottom: 0.5px solid var(--ia-border);">
                <div style="flex: 1; min-width: 0;">
                  <div style="font-weight: 500; color: var(--ia-text);">
                    {{ in_array($p->kind, ['refund', 'overage_refund']) ? 'Refund' : ucfirst($p->kind) }}
                    · {{ $p->methodLabel() }}
                  </div>
                  <div style="font-size: 10px; color: var(--ia-text-dim); margin-top: 2px;">
                    {{ $p->recorded_at?->format('M j · g:i A') }}
                    @if($p->source === 'register_sale' && $p->register_sale_id)
                      · sale {{ optional($p->registerSale)->sale_number ?? '#' }}
                    @endif
                  </div>
                </div>
                <div style="font-weight: 500; color: {{ $p->amount_cents < 0 ? '#F09595' : '#A8D670' }};">
                  {{ $p->amount_cents < 0 ? '−' : '+' }}${{ number_format(abs($p->amount_cents) / 100, 2) }}
                </div>
              </div>
            @endforeach
          </div>
        @endif

        <div class="ma-rail-row" style="margin-top: 8px;">
          <span class="k">Paid so far</span>
          <span class="v" style="color: #A8D670;">${{ number_format(($appointment->paid_cents ?? 0) / 100, 2) }}</span>
        </div>

        @if($balanceDue > 0)
          <div class="ma-rail-row" style="font-weight: 500;">
            <span class="k">Balance owed</span>
            <span class="v" style="font-size: 14px; font-weight: 500;">${{ number_format($balanceDue / 100, 2) }}</span>
          </div>
        @elseif($overage > 0)
          <div class="ma-rail-row" style="font-weight: 500;">
            <span class="k" style="color: #FBBF24;">Customer is owed</span>
            <span class="v" style="font-size: 14px; font-weight: 500; color: #FBBF24;">${{ number_format($overage / 100, 2) }}</span>
          </div>
        @else
          <div class="ma-rail-row" style="font-weight: 500;">
            <span class="k">Balance owed</span>
            <span class="v" style="font-size: 14px; font-weight: 500; color: #A8D670;">$0.00</span>
          </div>
        @endif

        @if($hasOpenSale)
          <a href="{{ route('tenant.register.index', []) }}?resume={{ $openSale->id }}"
             class="ia-btn ia-btn--primary ia-btn--sm"
             style="display: block; width: 100%; text-align: center; margin-top: 14px;">
            Take payment in register
          </a>
        @elseif($balanceDue > 0 && !$isTerminal)
          <button type="button" id="ma-record-deposit-toggle" class="ia-btn ia-btn--secondary ia-btn--sm" style="width: 100%; margin-top: 14px;">
            + Record deposit
          </button>
          <div id="ma-record-deposit-form" style="display: none; margin-top: 10px; padding: 12px; background: var(--ia-surface-2, rgba(255,255,255,0.02)); border-radius: 6px; border: 0.5px solid var(--ia-border);">
            <label style="font-size: 11px; color: var(--ia-text-dim); display: block; margin-bottom: 4px;">Amount</label>
            <input type="number" id="ma-record-deposit-amount" min="0.01" step="0.01" placeholder="0.00"
                   style="width: 100%; padding: 6px 10px; background: var(--ia-surface, #111); border: 0.5px solid var(--ia-border); color: var(--ia-text); border-radius: 6px; font-size: 13px; margin-bottom: 8px;">
            <div style="display: flex; gap: 6px;">
              <button type="button" id="ma-record-deposit-cancel" class="ia-btn ia-btn--ghost ia-btn--sm" style="flex: 1;">Cancel</button>
              <button type="button" id="ma-record-deposit-go" class="ia-btn ia-btn--primary ia-btn--sm" style="flex: 1;">Send to register</button>
            </div>
            <p style="font-size: 10px; color: var(--ia-text-dim); margin: 8px 0 0;">Creates a draft sale in the register where you take the actual payment.</p>
          </div>
        @endif
      </div>

      {{-- MARKER-PATCH-158-G3 — Resource card + Action buttons moved to top tile (G3) --}}

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

  // ---------------------- MARKER-PATCH-158-E2 ----------------------

  // Status pipeline click → transition.
  // MARKER-PATCH-158-G1 — Forward moves go silently. Backward moves prompt
  // via IntakeConfirm (matches legacy view's behavior). Falls back to native
  // confirm() if IntakeConfirm isn't loaded for some reason.
  (function() {
    const bar = document.querySelector('.ma-progress-bar');
    if (!bar) return;
    const currentIndex = parseInt(bar.dataset.currentIndex, 10);

    bar.querySelectorAll('.ma-progress-step').forEach(function(step) {
      step.addEventListener('click', async function() {
        if (step.classList.contains('is-current')) return;
        if (step.classList.contains('is-saving')) return;

        const newStatus = step.dataset.status;
        const label     = step.dataset.label;
        const stepIndex = parseInt(step.dataset.stepIndex, 10);
        const isBackward = stepIndex < currentIndex;

        const go = async function() {
          step.classList.add('is-saving');
          const result = await post({ op: 'status', status: newStatus });
          step.classList.remove('is-saving');
          if (!result.ok) {
            if (window.IntakeToast) IntakeToast.error('Could not change status: ' + result.message);
            else alert('Could not change status: ' + result.message);
            return;
          }
          if (window.IntakeToast) IntakeToast.success(label);
          setTimeout(function() { location.reload(); }, 600);
        };

        if (isBackward) {
          if (window.IntakeConfirm) {
            const ok = await window.IntakeConfirm.show({
              title:       'Move back to ' + label + '?',
              message:     'This appointment is currently further along. Going back may surprise the customer and will revert any register sale.',
              confirmText: 'Move back',
              cancelText:  'Keep where it is',
            });
            if (ok) go();
          } else {
            if (confirm('Move back to ' + label + '?')) go();
          }
        } else {
          go();
        }
      });
    });
  })();

  // Reopen button (terminal state)
  // MARKER-PATCH-158-G1 — Use IntakeConfirm to match legacy
  const reopenBtn = document.getElementById('ma-reopen-btn');
  if (reopenBtn) {
    reopenBtn.addEventListener('click', async function() {
      let proceed = false;
      if (window.IntakeConfirm) {
        proceed = await window.IntakeConfirm.show({
          title:       'Reopen this appointment?',
          message:     'This will return it to Pending status.',
          confirmText: 'Reopen',
          cancelText:  'Keep closed',
        });
      } else {
        proceed = confirm('Reopen this appointment? Status will return to pending.');
      }
      if (!proceed) return;
      const result = await post({ op: 'status', status: 'pending' });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not reopen: ' + result.message);
        else alert('Could not reopen: ' + result.message);
        return;
      }
      if (window.IntakeToast) IntakeToast.success('Reopened');
      setTimeout(function() { location.reload(); }, 600);
    });
  }

  // Inline edit (price + duration) on service/addon rows
  document.querySelectorAll('.line-edit').forEach(function(input) {
    input.addEventListener('blur', async function() {
      const row  = input.closest('.line-row');
      if (!row) return;
      const kind = row.dataset.kind;
      const id   = row.dataset.itemId;

      const durInput = row.querySelector('.line-edit[data-field="duration_minutes"]');
      const priInput = row.querySelector('.line-edit[data-field="price_dollars"]');
      const duration = durInput ? parseInt(durInput.value, 10) : null;
      const dollars  = priInput ? parseFloat(priInput.value) : null;
      const cents    = (dollars === null || isNaN(dollars)) ? null : Math.round(dollars * 100);

      const result = await post({
        op: 'update_line_item',
        kind: kind,
        item_id: id,
        price_cents: cents === null ? '' : cents,
        duration_minutes: (duration === null || isNaN(duration)) ? '' : duration,
      });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not save: ' + result.message);
        else alert('Could not save: ' + result.message);
      } else if (window.IntakeToast) {
        IntakeToast.success('Saved');
      }
    });

    // Select all on focus so editing feels snappy
    input.addEventListener('focus', function() { input.select(); });
  });

  // Remove button on each service/addon row
  document.querySelectorAll('.line-remove').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      const row  = btn.closest('.line-row');
      if (!row) return;
      const kind = row.dataset.kind;
      const id   = row.dataset.itemId;
      if (!confirm('Remove this ' + (kind === 'addon' ? 'add-on' : 'service') + '?')) return;
      const result = await post({
        op: kind === 'addon' ? 'remove_addon' : 'remove_service',
        [kind === 'addon' ? 'addon_id' : 'item_id']: id,
      });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not remove: ' + result.message);
        else alert('Could not remove: ' + result.message);
        return;
      }
      location.reload();
    });
  });

  // Asset name inline rename
  document.querySelectorAll('.asset-name-edit').forEach(function(input) {
    let originalValue = input.value;
    input.addEventListener('focus', function() { originalValue = input.value; input.select(); });
    input.addEventListener('blur', async function() {
      const newName = input.value.trim();
      if (newName === originalValue) return;
      if (newName === '') { input.value = originalValue; return; }
      const aaId = input.dataset.aaId;
      const result = await post({
        op: 'rename_appointment_asset',
        appointment_asset_id: aaId,
        name: newName,
      });
      if (!result.ok) {
        input.value = originalValue;
        if (window.IntakeToast) IntakeToast.error('Could not rename: ' + result.message);
        else alert('Could not rename: ' + result.message);
        return;
      }
      originalValue = newName;
      if (window.IntakeToast) IntakeToast.success('Renamed');
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.value = originalValue; input.blur(); }
    });
  });

  // ---------------------- MARKER-PATCH-158-E3 ----------------------

  // Add-charge form toggle
  (function() {
    const toggle = document.getElementById('ma-add-charge-toggle');
    const form   = document.getElementById('ma-add-charge-form');
    const cancel = document.getElementById('ma-add-charge-cancel');
    const dollarsInput = document.getElementById('ma-charge-amount-display');
    const centsInput   = document.getElementById('ma-charge-amount-cents');
    if (!toggle || !form) return;

    toggle.addEventListener('click', function() {
      form.style.display = 'block';
      toggle.style.display = 'none';
      setTimeout(function() { form.querySelector('input[name="description"]').focus(); }, 50);
    });
    if (cancel) cancel.addEventListener('click', function() {
      form.style.display = 'none';
      toggle.style.display = '';
      form.reset();
    });
    // Convert dollars -> cents in hidden input on submit
    form.addEventListener('submit', function(e) {
      const dollars = parseFloat(dollarsInput.value);
      if (isNaN(dollars) || dollars <= 0) {
        e.preventDefault();
        alert('Enter a valid amount.');
        return;
      }
      centsInput.value = Math.round(dollars * 100);
    });
  })();

  // Record-deposit flow
  (function() {
    const toggleBtn = document.getElementById('ma-record-deposit-toggle');
    const form      = document.getElementById('ma-record-deposit-form');
    const cancelBtn = document.getElementById('ma-record-deposit-cancel');
    const goBtn     = document.getElementById('ma-record-deposit-go');
    const amtInput  = document.getElementById('ma-record-deposit-amount');
    if (!toggleBtn || !form) return;

    toggleBtn.addEventListener('click', function() {
      form.style.display = 'block';
      toggleBtn.style.display = 'none';
      setTimeout(function() { amtInput.focus(); }, 50);
    });
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
      form.style.display = 'none';
      toggleBtn.style.display = '';
      amtInput.value = '';
    });
    if (goBtn) goBtn.addEventListener('click', async function() {
      const dollars = parseFloat(amtInput.value);
      if (isNaN(dollars) || dollars <= 0) { alert('Enter a valid amount.'); return; }
      const cents = Math.round(dollars * 100);
      goBtn.disabled = true;
      const result = await post({ op: 'record_deposit', amount_cents: cents });
      goBtn.disabled = false;
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error(result.message);
        else alert(result.message);
        return;
      }
      // Redirect to register
      const url = result.data?.redirect_url;
      if (url) { window.location.href = url; }
      else { location.reload(); }
    });
  })();

  // ---------------------- MARKER-PATCH-158-E4 — Inventory parts ----------------------

  // Part quantity inline edit
  document.querySelectorAll('.ma-part-qty-edit').forEach(function(input) {
    let originalValue = input.value;
    input.addEventListener('focus', function() { originalValue = input.value; input.select(); });
    input.addEventListener('blur', async function() {
      const newQty = parseInt(input.value, 10);
      if (isNaN(newQty) || newQty < 1) { input.value = originalValue; return; }
      if (String(newQty) === originalValue) return;
      const partId = input.dataset.partId;
      const result = await post({
        op: 'update_part_quantity',
        part_id: partId,
        quantity: newQty,
      });
      if (!result.ok) {
        input.value = originalValue;
        if (window.IntakeToast) IntakeToast.error('Could not update quantity: ' + result.message);
        else alert('Could not update quantity: ' + result.message);
        return;
      }
      originalValue = String(newQty);
      // Update line total cell in this row
      const row = input.closest('.ma-part-row');
      if (row && result.data && result.data.line_total_display) {
        const totalCell = row.querySelector('[data-line-total]');
        if (totalCell) totalCell.textContent = result.data.line_total_display;
      }
      if (window.IntakeToast) IntakeToast.success('Quantity updated');
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.value = originalValue; input.blur(); }
    });
  });

  // Part remove button
  document.querySelectorAll('.ma-part-remove').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Remove this part from the appointment?')) return;
      const partId = btn.dataset.partId;
      const result = await post({ op: 'remove_part', part_id: partId });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not remove: ' + result.message);
        else alert('Could not remove: ' + result.message);
        return;
      }
      location.reload();
    });
  });

  // Part picker with autocomplete
  (function() {
    const input   = document.getElementById('ma-part-picker-input');
    const results = document.getElementById('ma-part-picker-results');
    const customForm = document.getElementById('ma-custom-item-form');
    if (!input || !results) return;

    const searchUrl = {!! json_encode(route('tenant.appointments.inventory-search')) !!};
    let debounceTimer = null;
    let lastQuery = '';

    async function doSearch(q) {
      const r = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      });
      const data = await r.json();
      renderResults(data.items || [], q);
    }

    function renderResults(items, q) {
      let html = '';
      if (items.length === 0) {
        html = '<div class="ma-part-picker-empty">No matching items.</div>';
      } else {
        items.forEach(function(it) {
          html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                  '  <div class="name">' + escapeHtml(it.name) + '</div>' +
                  '  <div class="meta">' +
                  '    <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                  '    <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                  '  </div>' +
                  '</div>';
        });
      }
      html += '<div class="ma-part-picker-custom" id="ma-picker-custom-trigger">+ Add custom item' +
              (q ? ' "' + escapeHtml(q) + '"' : '') + '</div>';
      results.innerHTML = html;
      results.style.display = 'block';

      // Wire selection
      results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
        el.addEventListener('click', async function() {
          const id = el.dataset.id;
          results.style.display = 'none';
          input.value = '';
          const result = await post({ op: 'add_part', inventory_item_id: id, quantity: 1 });
          if (!result.ok) {
            if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
            else alert('Could not add: ' + result.message);
            return;
          }
          location.reload();
        });
      });

      // Wire custom trigger
      const trig = document.getElementById('ma-picker-custom-trigger');
      if (trig) trig.addEventListener('click', function() {
        results.style.display = 'none';
        customForm.style.display = 'block';
        const nameField = document.getElementById('ma-custom-item-name');
        if (q && nameField) nameField.value = q;
        if (nameField) setTimeout(function() { nameField.focus(); }, 50);
        input.value = '';
      });
    }

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    input.addEventListener('input', function() {
      const q = input.value.trim();
      if (q === lastQuery) return;
      lastQuery = q;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function() { doSearch(q); }, 180);
    });
    input.addEventListener('focus', function() {
      if (lastQuery !== input.value.trim() || results.innerHTML === '') {
        lastQuery = input.value.trim();
        doSearch(lastQuery);
      } else {
        results.style.display = 'block';
      }
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!input.contains(e.target) && !results.contains(e.target)) {
        results.style.display = 'none';
      }
    });

    // Custom item form save / cancel
    const customCancel = document.getElementById('ma-custom-item-cancel');
    const customSave   = document.getElementById('ma-custom-item-save');
    if (customCancel) customCancel.addEventListener('click', function() {
      customForm.style.display = 'none';
      document.getElementById('ma-custom-item-name').value = '';
      document.getElementById('ma-custom-item-price').value = '';
      document.getElementById('ma-custom-item-qty').value = '1';
    });
    if (customSave) customSave.addEventListener('click', async function() {
      const name  = document.getElementById('ma-custom-item-name').value.trim();
      const price = parseFloat(document.getElementById('ma-custom-item-price').value);
      const qty   = parseInt(document.getElementById('ma-custom-item-qty').value, 10) || 1;
      if (!name) { alert('Name is required.'); return; }
      if (isNaN(price) || price < 0) { alert('Enter a valid price.'); return; }
      customSave.disabled = true;
      const result = await post({
        op: 'add_custom_item',
        name: name,
        unit_price_cents: Math.round(price * 100),
        quantity: qty,
      });
      customSave.disabled = false;
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
        else alert('Could not add: ' + result.message);
        return;
      }
      location.reload();
    });
  })();

  // ---------------------- MARKER-PATCH-158-G4 — Per-asset part pickers ----------------------
  //
  // Same UI/UX as the loose picker above, but scoped to each asset card via
  // data-aa-id. Each asset gets its own input + results dropdown + custom-item
  // form. The asset id is passed to the backend as appointment_asset_id so the
  // part is pinned to that asset.
  (function() {
    const pickers = document.querySelectorAll('.ma-asset-part-picker');
    if (pickers.length === 0) return;

    const searchUrl = {!! json_encode(route('tenant.appointments.inventory-search')) !!};

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    pickers.forEach(function(input) {
      const aaId       = input.dataset.aaId;
      const results    = document.querySelector('.ma-asset-part-results[data-aa-id="' + aaId + '"]');
      const customForm = document.querySelector('.ma-asset-custom-form[data-aa-id="' + aaId + '"]');
      if (!results || !customForm) return;

      let debounceTimer = null;
      let lastQuery = '';

      async function doSearch(q) {
        const r = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        const data = await r.json();
        renderResults(data.items || [], q);
      }

      function renderResults(items, q) {
        let html = '';
        if (items.length === 0) {
          html = '<div class="ma-part-picker-empty">No matching items.</div>';
        } else {
          items.forEach(function(it) {
            html += '<div class="ma-part-picker-result" data-id="' + it.id + '">' +
                    '  <div class="name">' + escapeHtml(it.name) + '</div>' +
                    '  <div class="meta">' +
                    '    <span>' + (it.sku ? escapeHtml(it.sku) + ' · ' : '') + (it.price_display || '$0.00') + '</span>' +
                    '    <span>' + (it.stock > 0 ? it.stock + ' in stock' : (it.allow_oversell ? 'Oversell ok' : 'Out of stock')) + '</span>' +
                    '  </div>' +
                    '</div>';
          });
        }
        html += '<div class="ma-part-picker-custom ma-asset-picker-custom-trigger">+ Add custom item' +
                (q ? ' "' + escapeHtml(q) + '"' : '') + '</div>';
        results.innerHTML = html;
        results.hidden = false;

        results.querySelectorAll('.ma-part-picker-result').forEach(function(el) {
          el.addEventListener('click', async function() {
            const id = el.dataset.id;
            results.hidden = true;
            input.value = '';
            const result = await post({
              op: 'add_part',
              inventory_item_id: id,
              quantity: 1,
              appointment_asset_id: aaId, // MARKER-PATCH-158-G4
            });
            if (!result.ok) {
              if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
              else alert('Could not add: ' + result.message);
              return;
            }
            location.reload();
          });
        });

        const trig = results.querySelector('.ma-asset-picker-custom-trigger');
        if (trig) trig.addEventListener('click', function() {
          results.hidden = true;
          customForm.hidden = false;
          const nameField = customForm.querySelector('.ma-asset-custom-name');
          if (q && nameField) nameField.value = q;
          if (nameField) setTimeout(function() { nameField.focus(); }, 50);
          input.value = '';
        });
      }

      input.addEventListener('input', function() {
        const q = input.value.trim();
        if (q === lastQuery) return;
        lastQuery = q;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() { doSearch(q); }, 180);
      });
      input.addEventListener('focus', function() {
        if (lastQuery !== input.value.trim() || results.innerHTML === '') {
          lastQuery = input.value.trim();
          doSearch(lastQuery);
        } else {
          results.hidden = false;
        }
      });

      document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
          results.hidden = true;
        }
      });

      // Custom form cancel/save for this asset
      const cancelBtn = customForm.querySelector('.ma-asset-custom-cancel');
      const saveBtn   = customForm.querySelector('.ma-asset-custom-save');
      if (cancelBtn) cancelBtn.addEventListener('click', function() {
        customForm.hidden = true;
        customForm.querySelector('.ma-asset-custom-name').value = '';
        customForm.querySelector('.ma-asset-custom-price').value = '';
        customForm.querySelector('.ma-asset-custom-qty').value = '1';
      });
      if (saveBtn) saveBtn.addEventListener('click', async function() {
        const name  = customForm.querySelector('.ma-asset-custom-name').value.trim();
        const price = parseFloat(customForm.querySelector('.ma-asset-custom-price').value);
        const qty   = parseInt(customForm.querySelector('.ma-asset-custom-qty').value, 10) || 1;
        if (!name) { alert('Name is required.'); return; }
        if (isNaN(price) || price < 0) { alert('Enter a valid price.'); return; }
        saveBtn.disabled = true;
        const result = await post({
          op: 'add_custom_item',
          name: name,
          unit_price_cents: Math.round(price * 100),
          quantity: qty,
          appointment_asset_id: aaId, // MARKER-PATCH-158-G4
        });
        saveBtn.disabled = false;
        if (!result.ok) {
          if (window.IntakeToast) IntakeToast.error('Could not add: ' + result.message);
          else alert('Could not add: ' + result.message);
          return;
        }
        location.reload();
      });
    });
  })();

  // ---------------------- MARKER-PATCH-158-G5 — Per-asset work order forms ----------------------
  //
  // Each asset card has its own work-order details section. The display/edit
  // toggle and save submission are scoped to that asset via data-aa-id. The
  // form posts to save_work_order with appointment_asset_id, so the response
  // rows get pinned to that asset.
  (function() {
    document.querySelectorAll('.ma-asset-wo-edit-toggle').forEach(function(btn) {
      btn.addEventListener('click', function() {
        const aaId = btn.dataset.aaId;
        const display = document.querySelector('.ma-asset-wo-display[data-aa-id="' + aaId + '"]');
        const form    = document.querySelector('.ma-asset-wo-edit-form[data-aa-id="' + aaId + '"]');
        if (!display || !form) return;
        display.hidden = true;
        form.hidden = false;
      });
    });

    document.querySelectorAll('.ma-asset-wo-edit-cancel').forEach(function(btn) {
      btn.addEventListener('click', function() {
        const aaId = btn.dataset.aaId;
        const display = document.querySelector('.ma-asset-wo-display[data-aa-id="' + aaId + '"]');
        const form    = document.querySelector('.ma-asset-wo-edit-form[data-aa-id="' + aaId + '"]');
        if (!display || !form) return;
        form.hidden = true;
        display.hidden = false;
      });
    });

    document.querySelectorAll('.ma-asset-wo-edit-form').forEach(function(form) {
      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const aaId = form.dataset.aaId;
        const url  = form.dataset.updateUrl;

        const fd = new FormData(form);
        fd.append('_token', {!! json_encode(csrf_token()) !!});
        fd.append('_method', 'PATCH');
        fd.append('op', 'save_work_order');
        // appointment_asset_id is already included via the hidden input in the form

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
          const r = await fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
          });
          let data = null;
          try { data = await r.json(); } catch(e) {}
          if (!r.ok || !data || !data.ok) {
            if (window.IntakeToast) IntakeToast.error((data && data.message) || 'Could not save.');
            else alert((data && data.message) || 'Could not save.');
            if (submitBtn) submitBtn.disabled = false;
            return;
          }
          if (window.IntakeToast) IntakeToast.success('Work order saved');
          setTimeout(function() { location.reload(); }, 500);
        } catch (err) {
          if (window.IntakeToast) IntakeToast.error('Network error. Try again.');
          else alert('Network error. Try again.');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });
  })();

  // ---------------------- MARKER-PATCH-158-E5 — Notes + Work order ----------------------

  // Work order: Edit / display toggle
  (function() {
    const card        = document.getElementById('ma-wo-card');
    if (!card) return;
    const editToggle  = document.getElementById('ma-wo-edit-toggle');
    const displayDiv  = document.getElementById('ma-wo-display');
    const editForm    = document.getElementById('ma-wo-edit-form');
    const editCancel  = document.getElementById('ma-wo-edit-cancel');
    if (!editToggle || !displayDiv || !editForm) return;

    editToggle.addEventListener('click', function() {
      displayDiv.style.display = 'none';
      editForm.style.display = 'block';
      editToggle.style.display = 'none';
    });
    if (editCancel) editCancel.addEventListener('click', function() {
      displayDiv.style.display = '';
      editForm.style.display = 'none';
      editToggle.style.display = '';
    });
    // Form submit goes through normal PATCH redirect (full page reload after save_work_order)
  })();

  // Notes: char count
  (function() {
    const input = document.getElementById('ma-note-input');
    const counter = document.getElementById('ma-note-char-count');
    if (!input || !counter) return;
    function updateCount() {
      const remaining = 500 - input.value.length;
      counter.textContent = remaining;
      counter.style.color = remaining < 50 ? '#f87171' : '';
    }
    input.addEventListener('input', updateCount);
    updateCount();
  })();

  // Notes: add note
  (function() {
    const submitBtn  = document.getElementById('ma-note-submit');
    const input      = document.getElementById('ma-note-input');
    const visBox     = document.getElementById('ma-note-customer-visible');
    const errEl      = document.getElementById('ma-note-error');
    if (!submitBtn || !input) return;

    submitBtn.addEventListener('click', async function() {
      const note = input.value.trim();
      errEl.style.display = 'none';
      if (!note) {
        errEl.textContent = 'Note can\'t be empty.';
        errEl.style.display = 'block';
        return;
      }
      submitBtn.disabled = true;
      const result = await post({
        op: 'add_note',
        note: note,
        is_customer_visible: visBox && visBox.checked ? '1' : '0',
      });
      submitBtn.disabled = false;
      if (!result.ok) {
        errEl.textContent = 'Could not save: ' + result.message;
        errEl.style.display = 'block';
        return;
      }
      // Clear + reload to render the new note
      input.value = '';
      if (visBox) visBox.checked = false;
      location.reload();
    });
  })();

  // Notes: delete
  document.querySelectorAll('.ma-note-delete').forEach(function(btn) {
    btn.addEventListener('click', async function() {
      if (!confirm('Delete this note?')) return;
      const noteId = btn.dataset.noteId;
      const result = await post({ op: 'delete_note', note_id: noteId });
      if (!result.ok) {
        if (window.IntakeToast) IntakeToast.error('Could not delete: ' + result.message);
        else alert('Could not delete: ' + result.message);
        return;
      }
      // Remove the note element directly without reload
      const noteEl = btn.closest('.ma-note');
      if (noteEl) noteEl.remove();
    });
  });

  // Escape closes any open modal
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.ma-modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
    }
  });
})();
</script>

{{-- MARKER-PATCH-158-G2 — Shared reschedule modal partial (markup + JS) --}}
@include('tenant.appointments._reschedule_modal')

{{-- MARKER-PATCH-158-G2 — Resource picker save handler (shared with legacy view) --}}
@push('scripts')
<script src="{{ asset('js/tenant/appointment-resource.js') }}?v={{ filemtime(public_path('js/tenant/appointment-resource.js')) }}" defer></script>
<script>
// MARKER-PATCH-158-G2 — Cancel-appointment handler (mirrors legacy)
(function() {
  const cancelBtn = document.querySelector('.ma-cancel-btn');
  if (!cancelBtn) return;
  cancelBtn.addEventListener('click', async function() {
    const proceed = window.IntakeConfirm
      ? await window.IntakeConfirm.show({
          title:       'Cancel this appointment?',
          message:     "The appointment will be removed from the calendar and the customer's slot released. This stays in your records but won't show on the active schedule.",
          confirmText: 'Cancel appointment',
          cancelText:  'Keep it',
          danger:      true,
        })
      : confirm('Cancel this appointment?');
    if (!proceed) return;
    const fd = new FormData();
    fd.append('_token', {!! json_encode(csrf_token()) !!});
    fd.append('_method', 'PATCH');
    fd.append('op', 'status');
    fd.append('status', 'cancelled');
    const r = await fetch({!! json_encode(route('tenant.appointments.update', $appointment->id)) !!}, {
      method: 'POST', body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    });
    let data = null;
    try { data = await r.json(); } catch(e) {}
    if (!r.ok || !data || !data.ok) {
      if (window.IntakeToast) IntakeToast.error((data && data.message) || 'Could not cancel.');
      else alert((data && data.message) || 'Could not cancel.');
      return;
    }
    if (window.IntakeToast) IntakeToast.success('Cancelled');
    setTimeout(function() { window.location.href = {!! json_encode(route('tenant.calendar.index')) !!}; }, 600);
  });
})();
</script>
@endpush

@endsection
