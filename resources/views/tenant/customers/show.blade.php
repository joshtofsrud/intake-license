@extends('layouts.tenant.app')
@php
  $pageTitle  = $customer->first_name . ' ' . $customer->last_name;
  $updateUrl  = route('tenant.customers.update', $customer->id);
@endphp

@push('styles')
<style>
.cust-layout { display: grid; grid-template-columns: 1fr 280px; gap: 20px; align-items: start; }
.cust-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
.cust-field-label { font-size: 11px; opacity: .4; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.cust-field-value { font-size: 13px; }
.cust-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); font-size: 13px; }
.cust-stat:last-child { border-bottom: none; }
.cust-stat-label { opacity: .5; }
.cust-stat-value { font-weight: 500; }
.appt-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 0.5px solid var(--ia-border); cursor: pointer; transition: opacity .12s; }
.appt-row:last-child { border-bottom: none; }
.appt-row:hover { opacity: .75; }
.appt-row-main { flex: 1; }
.appt-row-ra { font-size: 13px; font-weight: 500; }
.appt-row-date { font-size: 12px; opacity: .45; margin-top: 1px; }

/* Memberships & Packs card */
.cust-mp-row { display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--ia-surface-2); border-radius: 6px; border: 0.5px solid var(--ia-border); }
.cust-mp-row--history { opacity: .55; padding: 6px 10px; background: transparent; border: 0; border-bottom: 0.5px solid var(--ia-border); border-radius: 0; }
.cust-mp-row--history:last-child { border-bottom: none; }
.cust-mp-row-main { flex: 1; min-width: 0; }
.cust-mp-row-title { font-size: 13px; font-weight: 500; }
.cust-mp-row-sub { font-size: 12px; color: var(--ia-text-muted); margin-top: 2px; }
.cust-mp-bar { height: 4px; background: var(--ia-border); border-radius: 2px; margin-top: 6px; overflow: hidden; }
.cust-mp-bar-fill { height: 100%; background: var(--ia-accent); border-radius: 2px; transition: width .3s; }

/* Grant modal */
.cust-mp-modal { position: fixed; inset: 0; background: rgba(0,0,0,.65); z-index: 1000; display: none; align-items: center; justify-content: center; }
.cust-mp-modal.is-open { display: flex; }
.cust-mp-modal-inner { background: var(--ia-surface); border: 0.5px solid var(--ia-border); border-radius: 10px; padding: 20px; max-width: 480px; width: 92%; }
.cust-mp-modal-title { font-size: 16px; font-weight: 600; margin-bottom: 6px; }
.cust-mp-modal-sub { font-size: 12px; color: var(--ia-text-muted); margin-bottom: 16px; }
.cust-mp-product-list { display: flex; flex-direction: column; gap: 6px; max-height: 280px; overflow-y: auto; margin-bottom: 12px; }
.cust-mp-product { display: flex; align-items: center; padding: 10px 12px; background: var(--ia-surface-2); border: 0.5px solid var(--ia-border); border-radius: 6px; cursor: pointer; transition: all var(--ia-t); }
.cust-mp-product:hover { border-color: var(--ia-border-strong); }
.cust-mp-product.is-selected { border-color: var(--ia-accent); background: var(--ia-accent-soft); }
.cust-mp-product-main { flex: 1; }
.cust-mp-product-name { font-size: 13px; font-weight: 500; }
.cust-mp-product-meta { font-size: 11px; color: var(--ia-text-muted); margin-top: 2px; }
.cust-mp-product-price { font-size: 13px; font-weight: 500; }

@media (max-width: 900px) {
  .cust-layout { grid-template-columns: 1fr; }
  .cust-info-grid { grid-template-columns: 1fr; }
}

/* CUSTOMER-MOBILE-POLISH v1 — phone polish at ≤600px */
@media (max-width: 600px) {

  /* Hide the page-level Back; top-bar already has ‹ Customers chevron */
  .ia-page-actions .ia-btn--ghost { display: none; }

  /* "+ New appointment" goes full-width on phones */
  .ia-page-actions .ia-btn--primary {
    width: 100%;
    justify-content: center;
  }

  /* Card headers (Memberships & Packs, Activity): stack title above actions */
  .ia-card-head {
    flex-direction: column !important;
    align-items: flex-start !important;
    gap: 8px;
  }
  .ia-card-head > div[style*="display:flex"] {
    width: 100%;
    flex-wrap: wrap;
    gap: 8px !important;
  }
  .ia-card-head .ia-btn--sm {
    flex: 1;
    justify-content: center;
    min-width: 0;
  }
  /* The Activity filter select stretches to fill the row */
  .ia-card-head #activity-filter {
    flex: 1;
    min-width: 0;
  }

  /* Activity rows: reflow 5-col grid into a compact 2-row layout */
  .act-row {
    grid-template-columns: 28px 1fr auto !important;
    grid-template-rows: auto auto;
    gap: 6px 10px !important;
    padding: 12px 4px !important;
  }
  .act-icon { grid-row: 1 / 3; align-self: start; }
  .act-date {
    grid-column: 2 / 4;
    grid-row: 1;
    font-size: 10px;
    margin-bottom: -2px;
  }
  .act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .act-title { font-size: 13px; }
  .act-id { display: block; margin-left: 0; margin-top: 1px; font-size: 11px; }
  .act-sub { font-size: 11px; }
  .act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px !important;
    padding: 2px 6px !important;
  }
  .act-amount {
    grid-column: 3;
    grid-row: 1;
    text-align: right;
    align-self: center;
    font-size: 12px;
    font-weight: 500;
  }
}

/* Activity timeline (unified customer history). */
.act-month { margin-bottom: 4px; }
.act-month-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 4px; cursor: pointer;
  border-bottom: 0.5px solid var(--ia-border);
  font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
  color: var(--ia-text-muted); font-weight: 500;
  transition: color var(--ia-t);
}
.act-month-head:hover { color: var(--ia-text); }
.act-month-label { display: flex; align-items: center; gap: 4px; }
.act-chevron { font-size: 12px; opacity: .6; }
.act-month-count { color: var(--ia-text-dim); font-weight: 400; text-transform: none; letter-spacing: 0; margin-left: 4px; }
.act-month-total { font-variant-numeric: tabular-nums; color: var(--ia-text); }
.act-row {
  display: grid;
  grid-template-columns: 28px 60px 1fr auto auto;
  gap: 10px; align-items: center;
  padding: 10px 4px;
  border-bottom: 0.5px solid var(--ia-border);
  transition: background var(--ia-t);
}
.act-row:hover { background: var(--ia-hover); }
.act-row:last-child { border-bottom: none; }
.act-icon {
  width: 24px; height: 24px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  background: var(--ia-surface-2);
  color: var(--ia-text-muted);
}
.act-icon--sale               { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-icon--appointment        { background: rgba(250,180,106,.18); color: #FAB46A; }
.act-icon--class_registration { background: rgba(117,168,224,.18); color: #75A8E0; }
.act-icon--pack_grant         { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-icon--membership_grant   { background: rgba(244,115,115,.15); color: #F47373; }
.act-date {
  font-size: 11px; color: var(--ia-text-muted);
  font-variant-numeric: tabular-nums; white-space: nowrap;
}
.act-main { min-width: 0; }
.act-title { font-size: 13px; font-weight: 500; color: var(--ia-text); }
.act-id { color: var(--ia-text-muted); font-weight: 400; margin-left: 4px; }
.act-sub {
  font-size: 11.5px; color: var(--ia-text-muted); margin-top: 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.act-pill {
  font-size: 10px; padding: 2px 7px; border-radius: 20px;
  white-space: nowrap;
}
.act-pill--success  { background: rgba(190,242,100,.15); color: var(--ia-accent); }
.act-pill--warning  { background: rgba(250,180,106,.15); color: #FAB46A; }
.act-pill--danger   { background: rgba(244,115,115,.15); color: #F47373; }
.act-pill--neutral  { background: var(--ia-surface-2); color: var(--ia-text-muted); }
.act-amount {
  font-size: 13px; font-weight: 500; min-width: 65px; text-align: right;
  font-variant-numeric: tabular-nums; color: var(--ia-text);
}
.act-amount.is-refunded { text-decoration: line-through; color: var(--ia-text-muted); }


/* CUSTOMER-MOBILE-REBUILD-CSS v1 — full mobile detail page styles + VIP. */

/* VIP — desktop badge + toggle */
.cust-vip-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 99px;
  background: rgba(245,158,11,.10);
  color: #F59E0B;
  border: 0.5px solid rgba(245,158,11,.30);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .04em;
  vertical-align: middle;
  margin-left: 8px;
}
.cust-vip-badge svg { color: #F59E0B; }

.cust-vip-toggle-desktop {
  display: inline-flex !important;
  align-items: center;
  gap: 5px;
}
.cust-vip-toggle-desktop.is-on {
  color: #F59E0B !important;
  border-color: rgba(245,158,11,.30) !important;
  background: rgba(245,158,11,.06) !important;
}

/* Customer list — small ★ next to VIP customer names */
.vip-list-star {
  color: #F59E0B;
  margin-left: 6px;
  font-size: 12px;
  vertical-align: middle;
}

/* Mobile-only visibility helpers */
.cust-mobile-only { display: none; }

@media (max-width: 600px) {
  .cust-desktop-only { display: none !important; }
  .cust-mobile-only { display: block; }

  /* Container */
  .cust-mobile { padding: 0; }

  /* HERO */
  .cmd-hero { margin-bottom: 16px; }
  .cmd-hero-top {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px;
  }
  .cmd-hero-name {
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -.02em;
    line-height: 1.15;
    color: var(--ia-text);
    margin: 0;
    flex: 1;
    min-width: 0;
    word-break: break-word;
  }
  .cmd-hero-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
  }
  .cmd-vip-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    height: 36px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-vip-btn.is-on {
    color: #F59E0B;
    border-color: rgba(245,158,11,.30);
    background: rgba(245,158,11,.08);
  }
  .cmd-vip-btn:active { transform: scale(0.95); }
  .cmd-edit-btn {
    background: transparent;
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    color: var(--ia-text-muted);
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-edit-btn:active { transform: scale(0.95); }

  /* Status pills */
  .cmd-status {
    display: flex;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .cmd-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 500;
  }
  .cmd-pill-dot {
    width: 6px; height: 6px; border-radius: 50%;
  }
  .cmd-pill--member {
    background: rgba(190,242,100,.10);
    color: var(--ia-accent);
    border: 0.5px solid rgba(190,242,100,.25);
  }
  .cmd-pill--member .cmd-pill-dot { background: var(--ia-accent); }
  .cmd-pill--neutral {
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    border: 0.5px solid var(--ia-border);
  }

  /* Contact tiles */
  .cmd-contact-tiles {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin-top: 14px;
  }
  .cmd-tile {
    display: flex; flex-direction: column; align-items: center;
    gap: 4px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 12px 6px;
    color: var(--ia-text);
    text-decoration: none;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-tile svg { color: var(--ia-accent); }
  .cmd-tile:active { transform: scale(0.97); }
  .cmd-tile-label {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-weight: 500;
  }
  .cmd-tile.is-disabled {
    opacity: .35;
    pointer-events: none;
  }
  .cmd-tile.is-disabled svg { color: var(--ia-text-muted); }

  /* CTA */
  .cmd-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    margin-top: 12px;
    padding: 14px;
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
  }
  .cmd-cta:active { transform: scale(0.99); }

  /* Stats */
  .cmd-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 20px;
  }
  .cmd-stat {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
  }
  .cmd-stat-value {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -.01em;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
  }
  .cmd-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--ia-text-muted);
    margin-top: 3px;
    font-weight: 500;
  }

  /* Sections */
  .cmd-section { margin-bottom: 24px; }
  .cmd-section-head {
    padding: 0 4px 10px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-muted);
    font-weight: 500;
  }

  /* Membership card */
  .cmd-mb-card {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-left: 3px solid var(--ia-accent);
    border-radius: 10px;
    padding: 14px;
  }
  .cmd-mb-card-top {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 10px;
    margin-bottom: 4px;
  }
  .cmd-mb-card-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--ia-text);
  }
  .cmd-mb-card-renew {
    font-size: 11px;
    color: var(--ia-text-muted);
    font-variant-numeric: tabular-nums;
  }
  .cmd-mb-card-meta {
    font-size: 12px;
    color: var(--ia-text-muted);
  }

  /* Activity */
  .cmd-act-month-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-weight: 500;
    padding: 12px 4px 6px;
  }
  .cmd-act-row {
    display: grid;
    grid-template-columns: 28px 1fr auto;
    grid-template-rows: auto auto;
    gap: 4px 12px;
    padding: 12px 4px;
    border-bottom: 0.5px solid var(--ia-border);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .cmd-act-row:last-child { border-bottom: none; }
  .cmd-act-icon {
    grid-row: 1 / 3;
    width: 28px; height: 28px;
    border-radius: 50%;
    margin-top: 2px;
    background: var(--ia-surface-2);
  }
  .cmd-act-icon--appt { background: rgba(250,180,106,.18); }
  .cmd-act-icon--sale { background: rgba(190,242,100,.15); }
  .cmd-act-icon--class { background: rgba(117,168,224,.18); }
  .cmd-act-icon--member { background: rgba(244,115,115,.15); }
  .cmd-act-date {
    grid-column: 2;
    grid-row: 1;
    font-size: 10px;
    color: var(--ia-text-dim, rgba(255,255,255,.4));
    font-variant-numeric: tabular-nums;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .cmd-act-amount {
    grid-column: 3;
    grid-row: 1;
    font-size: 13px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    color: var(--ia-text);
    text-align: right;
  }
  .cmd-act-main {
    grid-column: 2;
    grid-row: 2;
    min-width: 0;
  }
  .cmd-act-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--ia-text);
    margin-bottom: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-sub {
    font-size: 11px;
    color: var(--ia-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cmd-act-pill {
    grid-column: 3;
    grid-row: 2;
    align-self: center;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 99px;
    background: var(--ia-surface-2);
    color: var(--ia-text-muted);
    white-space: nowrap;
    justify-self: end;
  }
  .cmd-act-pill--success { background: rgba(190,242,100,.15); color: var(--ia-accent); }
  .cmd-act-pill--warning { background: rgba(245,158,11,.15); color: #F59E0B; }
  .cmd-act-pill--danger { background: rgba(244,115,115,.15); color: #F47373; }

  /* Notes */
  .cmd-note {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
  }
  .cmd-note-head {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11px;
    color: var(--ia-text-muted);
    margin-bottom: 4px;
  }
  .cmd-note-author { font-weight: 500; color: var(--ia-text); }
  .cmd-note-body { font-size: 13px; line-height: 1.4; color: var(--ia-text); }
  .cmd-note-empty { font-size: 13px; color: var(--ia-text-muted); padding: 4px; }
  .cmd-note-add {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 4px;
  }
  .cmd-note-add input {
    flex: 1;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    padding: 10px 12px;
    color: var(--ia-text);
    font-size: 13px;
    font-family: inherit;
  }
  .cmd-note-add-btn {
    background: var(--ia-accent);
    color: var(--ia-bg, #0a0a0a);
    border: none;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
  }
}


/* CUST-EDIT-SHEET-CSS v1 */
.cust-edit-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 200;
  opacity: 0;
  pointer-events: none;
  transition: opacity 180ms ease;
}
.cust-edit-backdrop.is-open { opacity: 1; pointer-events: auto; }

.cust-edit-sheet {
  position: fixed;
  left: 0; right: 0; bottom: 0;
  background: var(--ia-surface);
  border-radius: 18px 18px 0 0;
  z-index: 201;
  border: 0.5px solid var(--ia-border);
  border-bottom: 0;
  transform: translateY(100%);
  transition: transform 220ms cubic-bezier(.2, .8, .2, 1);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
}
.cust-edit-sheet.is-open { transform: translateY(0); }

.cust-edit-handle {
  width: 36px; height: 4px;
  background: rgba(255,255,255,.18);
  border-radius: 2px;
  margin: 12px auto 8px;
  flex-shrink: 0;
}
body.ia-theme-b .cust-edit-handle { background: rgba(0,0,0,.18); }

.cust-edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 20px 14px;
  border-bottom: 0.5px solid var(--ia-border);
  flex-shrink: 0;
}
.cust-edit-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--ia-text);
}
.cust-edit-close {
  background: transparent;
  border: none;
  color: var(--ia-text-muted);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}

.cust-edit-body {
  padding: 16px 20px calc(20px + env(safe-area-inset-bottom, 0px));
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  flex: 1;
}

.cust-edit-field {
  margin-bottom: 14px;
}
.cust-edit-label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .07em;
  color: var(--ia-text-muted);
  font-weight: 500;
  margin-bottom: 5px;
}
.cust-edit-input {
  width: 100%;
  background: var(--ia-input-bg, var(--ia-surface-2));
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 10px 12px;
  color: var(--ia-text);
  font-size: 15px;
  font-family: inherit;
  -webkit-appearance: none;
  appearance: none;
}
.cust-edit-input:focus {
  outline: none;
  border-color: var(--ia-accent);
}

.cust-edit-row-2 {
  display: grid;
  grid-template-columns: 1.4fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.cust-edit-row-2 .cust-edit-field {
  margin-bottom: 0;
}

.cust-edit-actions {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 8px;
  margin-top: 8px;
  padding-top: 16px;
  border-top: 0.5px solid var(--ia-border);
}
.cust-edit-btn-cancel {
  background: transparent;
  border: 0.5px solid var(--ia-border);
  border-radius: 8px;
  padding: 12px;
  color: var(--ia-text);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save {
  background: var(--ia-accent);
  color: var(--ia-bg, #0a0a0a);
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  -webkit-tap-highlight-color: transparent;
}
.cust-edit-btn-save:disabled {
  opacity: .5;
  cursor: wait;
}
.cust-edit-error {
  margin-top: 10px;
  padding: 8px 12px;
  background: rgba(244,115,115,.10);
  border: 0.5px solid rgba(244,115,115,.30);
  border-radius: 8px;
  color: #F47373;
  font-size: 13px;
}

/* Hide the edit sheet entirely on desktop — unreachable. */
@media (min-width: 601px) {
  .cust-edit-sheet,
  .cust-edit-backdrop { display: none !important; }
}
</style>
@endpush

@section('mobile-back', 'Customers|' . route('tenant.customers.index'))

@section('content')

<x-tenant.sale-detail-modal />

{{-- Header — VIP-DESKTOP-INTEGRATION v1 --}}
<div class="ia-page-head">
  <div class="ia-page-head-left">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.4;margin-bottom:4px">Customer</div>
    <h1 class="ia-page-title">
      {{ $customer->first_name }} {{ $customer->last_name }}
      <span class="cust-vip-badge" data-vip-badge style="display:{{ $customer->is_vip ? 'inline-flex' : 'none' }}">
        <svg viewBox="0 0 24 24" fill="currentColor" stroke="none" width="12" height="12" aria-hidden="true">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        VIP
      </span>
    </h1>
    <p class="ia-page-subtitle">
      {{ $customer->email }}
      @if($customer->phone) · {{ $customer->phone }} @endif
      · Added {{ $customer->created_at->format('M j, Y') }}
    </p>
  </div>
  <div class="ia-page-actions">
    <a href="{{ route('tenant.customers.index') }}" class="ia-btn ia-btn--ghost cust-desktop-only">← Back</a>
    <button type="button" class="ia-btn ia-btn--ghost cust-vip-toggle-desktop {{ $customer->is_vip ? 'is-on' : '' }}"
            data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">
      <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      </svg>
      <span data-vip-label>{{ $customer->is_vip ? 'VIP' : 'Mark VIP' }}</span>
    </button>
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}"
       class="ia-btn ia-btn--primary">+ New appointment</a>
  </div>
</div>

{{-- ============================================================
     MOBILE LAYOUT (hidden on desktop via CSS)
     ============================================================ --}}
@php
  // Compute mobile hero data
  $mobActiveMembership = isset($customerMemberships) ? $customerMemberships->where('status','active')->first() : null;
  $mobActivePacks = isset($customerPacks) ? $customerPacks->where('status','active') : collect();
  $mobLastVisit = $lastService ? \Carbon\Carbon::parse($lastService) : null;
  $mobMonthsSince = $customer->created_at->diffInMonths(now());
  $mobSinceLabel = $mobMonthsSince < 1 ? '<1 mo' : ($mobMonthsSince < 12 ? $mobMonthsSince . ' mo' : floor($mobMonthsSince / 12) . ' yr');
  $mobVisitCount = $appointments->whereIn('status', ['completed','confirmed','in_progress'])->count();
@endphp

<div class="cust-mobile-only cust-mobile">

  {{-- HERO BAND --}}
  <div class="cmd-hero">
    <div class="cmd-hero-top">
      <h1 class="cmd-hero-name">{{ $customer->first_name }} {{ $customer->last_name }}</h1>
      <div class="cmd-hero-actions">
        <button type="button" class="cmd-vip-btn {{ $customer->is_vip ? 'is-on' : '' }}"
                data-vip-toggle data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}"
                aria-label="{{ $customer->is_vip ? 'Remove VIP status' : 'Mark as VIP' }}">
          <svg viewBox="0 0 24 24" fill="{{ $customer->is_vip ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          VIP
        </button>
        <button type="button" class="cmd-edit-btn" onclick="CustEditSheet.open()" aria-label="Edit customer info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>
      </div>
    </div>

    {{-- Status pills --}}
    @if($mobActiveMembership || $mobLastVisit)
      <div class="cmd-status">
        @if($mobActiveMembership)
          <span class="cmd-pill cmd-pill--member">
            <span class="cmd-pill-dot"></span>
            Active member
          </span>
        @endif
        @if($mobLastVisit)
          <span class="cmd-pill cmd-pill--neutral">Last visit · {{ $mobLastVisit->format('M j') }}</span>
        @endif
      </div>
    @endif

    {{-- Contact tiles --}}
    <div class="cmd-contact-tiles">
      <a href="{{ $customer->phone ? 'tel:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
        </svg>
        <span class="cmd-tile-label">Call</span>
      </a>
      <a href="{{ $customer->phone ? 'sms:' . preg_replace('/[^0-9+]/', '', $customer->phone) : '#' }}"
         class="cmd-tile {{ $customer->phone ? '' : 'is-disabled' }}"
         {{ $customer->phone ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span class="cmd-tile-label">Text</span>
      </a>
      <a href="{{ $customer->email ? 'mailto:' . $customer->email : '#' }}"
         class="cmd-tile {{ $customer->email ? '' : 'is-disabled' }}"
         {{ $customer->email ? '' : 'aria-disabled=true' }}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        <span class="cmd-tile-label">Email</span>
      </a>
    </div>

    {{-- Primary CTA --}}
    <a href="{{ route('tenant.calendar.index', ['customer_id' => $customer->id]) }}" class="cmd-cta">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      New appointment
    </a>
  </div>

  {{-- STATS ROW --}}
  <div class="cmd-stats">
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobVisitCount }}</div>
      <div class="cmd-stat-label">Visits</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ format_money((int)$totalSpend) }}</div>
      <div class="cmd-stat-label">Lifetime</div>
    </div>
    <div class="cmd-stat">
      <div class="cmd-stat-value">{{ $mobSinceLabel }}</div>
      <div class="cmd-stat-label">Since</div>
    </div>
  </div>

  {{-- MEMBERSHIP CARD (conditional) --}}
  @if($mobActiveMembership)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Membership</span>
      </div>
      <div class="cmd-mb-card">
        <div class="cmd-mb-card-top">
          <span class="cmd-mb-card-title">{{ $mobActiveMembership->product?->name ?? 'Membership' }}</span>
          @if($mobActiveMembership->renews_at)
            <span class="cmd-mb-card-renew">Renews {{ \Carbon\Carbon::parse($mobActiveMembership->renews_at)->format('M j') }}</span>
          @endif
        </div>
        <div class="cmd-mb-card-meta">
          Started {{ \Carbon\Carbon::parse($mobActiveMembership->granted_at ?? $mobActiveMembership->created_at)->format('M j') }}
          @if($mobActiveMembership->product?->price_cents)
            · {{ format_money($mobActiveMembership->product->price_cents) }}/mo
          @endif
        </div>
      </div>
    </div>
  @endif

  {{-- ACTIVITY — reuse existing $timelineMonths data --}}
  @if($timelineCount > 0)
    <div class="cmd-section">
      <div class="cmd-section-head">
        <span>Activity · {{ $timelineCount }} events</span>
      </div>
      <div class="cmd-activity">
        @foreach($timelineMonths as $monthKey => $month)
          <div class="cmd-act-month-label">{{ $month['label'] }}</div>
          @foreach($month['events'] as $e)
            @php
              $iconClass = match($e['kind']) {
                'appointment' => 'cmd-act-icon--appt',
                'sale' => 'cmd-act-icon--sale',
                'class_registration' => 'cmd-act-icon--class',
                'pack_grant', 'membership_grant' => 'cmd-act-icon--member',
                default => '',
              };
            @endphp
            <div class="cmd-act-row"
                 @if(!empty($e['sale_id'])) onclick="window.openSaleModal && window.openSaleModal('{{ $e['sale_id'] }}')"
                 @elseif($e['href']) onclick="window.location='{{ $e['href'] }}'"
                 @endif>
              <div class="cmd-act-icon {{ $iconClass }}"></div>
              <div class="cmd-act-date">{{ $e['date']->format('M j') }}</div>
              <div class="cmd-act-amount">
                @if($e['amount_cents'] !== null){{ format_money($e['amount_cents']) }}@endif
              </div>
              <div class="cmd-act-main">
                <div class="cmd-act-title">{{ $e['title'] }}</div>
                <div class="cmd-act-sub">{{ $e['subtitle'] }}</div>
              </div>
              <span class="cmd-act-pill cmd-act-pill--{{ $e['status_tone'] }}">{{ $e['status'] }}</span>
            </div>
          @endforeach
        @endforeach
      </div>
    </div>
  @endif

  {{-- NOTES — mobile version. Reuses existing add-note infrastructure. --}}
  <div class="cmd-section">
    <div class="cmd-section-head">
      <span>Notes</span>
    </div>
    @forelse($notes as $note)
      <div class="cmd-note">
        <div class="cmd-note-head">
          <span class="cmd-note-author">{{ $note->user?->name ?? 'Staff' }}</span>
          <span>{{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}</span>
        </div>
        <div class="cmd-note-body">{{ $note->note }}</div>
      </div>
    @empty
      <p class="cmd-note-empty">No notes yet.</p>
    @endforelse
    <div class="cmd-note-add">
      <input type="text" id="cmd-note-input" placeholder="Add a note..." maxlength="200">
      <button type="button" class="cmd-note-add-btn" data-url="{{ $updateUrl }}" data-csrf="{{ csrf_token() }}">Add</button>
    </div>
  </div>

</div>

{{-- CUST-EDIT-SHEET v1 — mobile-only bottom sheet for editing customer info.
     Posts to the same PATCH endpoint as the desktop form (op=update_info).
     Hidden on desktop via CSS @media (min-width: 601px). --}}
<div class="cust-edit-backdrop" id="cust-edit-backdrop" onclick="CustEditSheet.close()" aria-hidden="true"></div>
<div class="cust-edit-sheet" id="cust-edit-sheet" role="dialog" aria-modal="true" aria-label="Edit customer" aria-hidden="true">
  <div class="cust-edit-handle" aria-hidden="true"></div>
  <div class="cust-edit-header">
    <span class="cust-edit-title">Edit customer</span>
    <button type="button" class="cust-edit-close" onclick="CustEditSheet.close()" aria-label="Close">
      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
        <path d="M4 4l10 10M14 4L4 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
      </svg>
    </button>
  </div>

  <form method="POST" action="{{ $updateUrl }}" id="cust-edit-form" class="cust-edit-body">
    @csrf @method('PATCH')
    <input type="hidden" name="op" value="update_info">

    <div class="cust-edit-field">
      <label class="cust-edit-label">First name <span style="color:#F47373">*</span></label>
      <input type="text" name="first_name" class="cust-edit-input" required value="{{ $customer->first_name }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Last name <span style="color:#F47373">*</span></label>
      <input type="text" name="last_name" class="cust-edit-input" required value="{{ $customer->last_name }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Email</label>
      <input type="email" name="email" class="cust-edit-input" value="{{ $customer->email }}" inputmode="email" autocapitalize="none" autocorrect="off">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Phone</label>
      <input type="tel" name="phone" class="cust-edit-input" value="{{ $customer->phone }}" inputmode="tel">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">Street address</label>
      <input type="text" name="address_line1" class="cust-edit-input" value="{{ $customer->address_line1 }}">
    </div>
    <div class="cust-edit-field">
      <label class="cust-edit-label">City</label>
      <input type="text" name="city" class="cust-edit-input" value="{{ $customer->city }}">
    </div>
    <div class="cust-edit-row-2">
      <div class="cust-edit-field">
        <label class="cust-edit-label">State</label>
        <input type="text" name="state" class="cust-edit-input" value="{{ $customer->state }}">
      </div>
      <div class="cust-edit-field">
        <label class="cust-edit-label">ZIP</label>
        <input type="text" name="postcode" class="cust-edit-input" value="{{ $customer->postcode }}" inputmode="numeric">
      </div>
    </div>

    <div class="cust-edit-actions">
      <button type="button" class="cust-edit-btn-cancel" onclick="CustEditSheet.close()">Cancel</button>
      <button type="submit" class="cust-edit-btn-save">Save</button>
    </div>
    <p id="cust-edit-error" class="cust-edit-error" style="display:none"></p>
  </form>
</div>

{{-- ============================================================
     CUSTOMER-DETAIL-MOBILE-REBUILD v1 — parallel mobile render below.
     Desktop layout (this .cust-layout grid) is hidden on phones via CSS.
     ============================================================ --}}
<div class="cust-layout cust-desktop-only">

  {{-- ============================================================
       Left: info card + work orders
       ============================================================ --}}
  <div style="display:flex;flex-direction:column;gap:20px">

    {{-- Info card --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Customer info</span>
        <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="edit-toggle">Edit</button>
      </div>

      {{-- View mode --}}
      <div id="info-view">
        <div class="cust-info-grid">
          <div>
            <div class="cust-field-label">Name</div>
            <div class="cust-field-value">{{ $customer->first_name }} {{ $customer->last_name }}</div>
          </div>
          <div>
            <div class="cust-field-label">Email</div>
            <div class="cust-field-value">{{ $customer->email }}</div>
          </div>
          <div>
            <div class="cust-field-label">Phone</div>
            <div class="cust-field-value">{{ $customer->phone ?: '—' }}</div>
          </div>
          <div>
            <div class="cust-field-label">Address</div>
            <div class="cust-field-value">
              @php
                $addr = array_filter([$customer->address_line1, $customer->city, $customer->state, $customer->postcode]);
              @endphp
              {{ $addr ? implode(', ', $addr) : '—' }}
            </div>
          </div>
        </div>
      </div>

      {{-- Edit mode --}}
      <form method="POST" action="{{ $updateUrl }}" id="info-edit" style="display:none">
        @csrf @method('PATCH')
        <input type="hidden" name="op" value="update_info">

        <div class="ia-input-grid-2" style="margin-bottom:12px">
          <div class="ia-form-group">
            <label class="ia-form-label">First name <span class="ia-required">*</span></label>
            <input type="text" name="first_name" class="ia-input" required value="{{ $customer->first_name }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Last name <span class="ia-required">*</span></label>
            <input type="text" name="last_name" class="ia-input" required value="{{ $customer->last_name }}">
          </div>
        </div>
        <div class="ia-input-grid-2" style="margin-bottom:12px">
          <div class="ia-form-group">
            <label class="ia-form-label">Email</label>
            <input type="email" name="email" class="ia-input" value="{{ $customer->email }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Phone</label>
            <input type="tel" name="phone" class="ia-input" value="{{ $customer->phone }}">
          </div>
        </div>
        <div class="ia-form-group" style="margin-bottom:12px">
          <label class="ia-form-label">Street address</label>
          <input type="text" name="address_line1" class="ia-input" value="{{ $customer->address_line1 }}">
        </div>
        <div class="ia-input-grid-3" style="margin-bottom:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">City</label>
            <input type="text" name="city" class="ia-input" value="{{ $customer->city }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">State</label>
            <input type="text" name="state" class="ia-input" value="{{ $customer->state }}">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">ZIP</label>
            <input type="text" name="postcode" class="ia-input" value="{{ $customer->postcode }}">
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="ia-btn ia-btn--primary ia-btn--sm">Save changes</button>
          <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="edit-cancel">Cancel</button>
        </div>
      </form>
    </div>

    {{-- Memberships & Packs (classes-enabled tenants only) --}}
    @if($currentTenant->classes_enabled)
      @php
        $activeMembership = $customerMemberships->where('status', 'active')->first();
        $activePacks      = $customerPacks->where('status', 'active');
        $historyMemberships = $customerMemberships->where('status', '!=', 'active');
        $historyPacks       = $customerPacks->where('status', '!=', 'active');
      @endphp
      <div class="ia-card" id="cust-mp-card">
        <div class="ia-card-head">
          <span class="ia-card-title">Memberships &amp; Packs</span>
          <div style="display:flex;gap:6px">
            @if(!$activeMembership && $membershipProducts->isNotEmpty())
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="openGrantModal('membership')">+ Grant membership</button>
            @endif
            @if($packProducts->isNotEmpty())
              <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="openGrantModal('pack')">+ Grant pack</button>
            @endif
          </div>
        </div>

        @if(!$activeMembership && $activePacks->isEmpty() && $historyMemberships->isEmpty() && $historyPacks->isEmpty())
          <p style="font-size:13px;opacity:.5">No memberships or packs yet.</p>
        @else
          {{-- Active items --}}
          <div style="display:flex;flex-direction:column;gap:8px">
            @if($activeMembership)
              <div class="cust-mp-row" data-mp-id="{{ $activeMembership->id }}" data-mp-kind="membership">
                <div class="cust-mp-row-main">
                  <div class="cust-mp-row-title">{{ $activeMembership->product?->name ?? 'Membership' }}</div>
                  <div class="cust-mp-row-sub">
                    @if($activeMembership->product?->type === 'unlimited')
                      Unlimited · used {{ $activeMembership->classes_used_this_period }} this period
                    @else
                      {{ $activeMembership->classes_used_this_period }} / {{ $activeMembership->product?->monthly_limit ?? '?' }} used this period
                    @endif
                    · renews {{ $activeMembership->current_period_end?->format('M j, Y') }}
                  </div>
                </div>
                <span class="ia-badge ia-badge--confirmed">Active</span>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="revokeMP('membership','{{ $activeMembership->id }}')">Cancel</button>
              </div>
            @endif

            @foreach($activePacks as $pack)
              @php
                $pct = $pack->credits_total > 0 ? round(($pack->credits_remaining / $pack->credits_total) * 100) : 0;
              @endphp
              <div class="cust-mp-row" data-mp-id="{{ $pack->id }}" data-mp-kind="pack">
                <div class="cust-mp-row-main">
                  <div class="cust-mp-row-title">{{ $pack->product?->name ?? 'Pack' }}</div>
                  <div class="cust-mp-row-sub">
                    {{ $pack->credits_remaining }} of {{ $pack->credits_total }} credits left ·
                    expires {{ $pack->expires_at?->format('M j, Y') }}
                  </div>
                  <div class="cust-mp-bar"><div class="cust-mp-bar-fill" style="width:{{ $pct }}%"></div></div>
                </div>
                <span class="ia-badge ia-badge--confirmed">Active</span>
                <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" onclick="revokeMP('pack','{{ $pack->id }}')">Cancel</button>
              </div>
            @endforeach
          </div>

          {{-- History --}}
          @if($historyMemberships->isNotEmpty() || $historyPacks->isNotEmpty())
            <details style="margin-top:12px">
              <summary style="cursor:pointer;font-size:12px;color:var(--ia-text-muted)">History</summary>
              <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                @foreach($historyMemberships as $m)
                  <div class="cust-mp-row cust-mp-row--history">
                    <div class="cust-mp-row-main">
                      <div class="cust-mp-row-title">{{ $m->product?->name ?? 'Membership' }}</div>
                      <div class="cust-mp-row-sub">
                        Status: {{ ucfirst($m->status) }}
                        @if($m->current_period_end) · ended {{ $m->current_period_end->format('M j, Y') }} @endif
                      </div>
                    </div>
                  </div>
                @endforeach
                @foreach($historyPacks as $p)
                  <div class="cust-mp-row cust-mp-row--history">
                    <div class="cust-mp-row-main">
                      <div class="cust-mp-row-title">{{ $p->product?->name ?? 'Pack' }}</div>
                      <div class="cust-mp-row-sub">
                        Status: {{ ucfirst($p->status) }} · {{ $p->credits_remaining }} credits remained
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>
            </details>
          @endif
        @endif
      </div>
    @endif

    {{-- Activity — unified timeline of all customer events.
         Powered by CustomerTimelineService. Replaces the previous
         appointments-only section. Groups by month, current+previous
         expanded by default, older months collapsible. Filterable
         via single dropdown at top. --}}
    <div class="ia-card">
      <div class="ia-card-head">
        <span class="ia-card-title">Activity</span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:12px;opacity:.4">{{ $timelineCount }} events</span>
          <select id="activity-filter" style="background:var(--ia-input-bg);border:0.5px solid var(--ia-border);color:var(--ia-text);font-size:12px;padding:4px 22px 4px 8px;border-radius:4px;appearance:none;cursor:pointer;background-image:url(&quot;data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none' stroke='rgba(255,255,255,.45)'><path d='M2 4l3 3 3-3' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>&quot;);background-repeat:no-repeat;background-position:right 6px center;font-family:inherit">
            <option value="all">All activity</option>
            <option value="appointment">Appointments</option>
            <option value="sale">Sales</option>
            <option value="class_registration">Class registrations</option>
            <option value="grant">Memberships &amp; packs</option>
          </select>
        </div>
      </div>

      @if($timelineCount === 0)
        <p style="font-size:13px;opacity:.4;padding:8px 0">No activity yet.</p>
      @else
        @foreach($timelineMonths as $monthKey => $month)
          <div class="act-month" data-act-month="{{ $monthKey }}" data-expanded="{{ $month['expanded'] ? '1' : '0' }}">
            <div class="act-month-head" onclick="toggleActMonth(this)">
              <span class="act-month-label">
                <i class="act-chevron ti ti-chevron-down" style="display:{{ $month['expanded'] ? 'inline-block' : 'none' }}"></i>
                <i class="act-chevron ti ti-chevron-right" style="display:{{ $month['expanded'] ? 'none' : 'inline-block' }}"></i>
                {{ $month['label'] }}
                @if(!$month['expanded'])
                  <span class="act-month-count">· {{ $month['events']->count() }} events</span>
                @endif
              </span>
              <span class="act-month-total">{{ format_money($month['total_cents']) }}</span>
            </div>
            <div class="act-month-body" style="display:{{ $month['expanded'] ? 'block' : 'none' }}">
              @foreach($month['events'] as $e)
                @php
                  $kindClass = $e['kind'] === 'pack_grant' || $e['kind'] === 'membership_grant'
                    ? 'grant' : $e['kind'];
                  $iconMap = [
                    'sale'              => 'ti-cash',
                    'appointment'       => 'ti-calendar',
                    'class_registration'=> 'ti-users',
                    'pack_grant'        => 'ti-ticket',
                    'membership_grant'  => 'ti-id-badge',
                  ];
                  $icon = $iconMap[$e['kind']] ?? 'ti-circle';
                @endphp
                <div class="act-row" data-act-kind="{{ $kindClass }}"
                     @if(!empty($e['sale_id']))
                       onclick="window.openSaleModal && window.openSaleModal('{{ $e['sale_id'] }}')" style="cursor:pointer"
                     @elseif($e['href'])
                       onclick="window.location='{{ $e['href'] }}'" style="cursor:pointer"
                     @endif>
                  <div class="act-icon act-icon--{{ $e['kind'] }}"><i class="ti {{ $icon }}"></i></div>
                  <div class="act-date">{{ $e['date']->format('M j') }}</div>
                  <div class="act-main">
                    <div class="act-title">
                      {{ $e['title'] }}
                      @if($e['identifier'])
                        <span class="act-id">{{ $e['identifier'] }}</span>
                      @endif
                    </div>
                    <div class="act-sub">{{ $e['subtitle'] }}</div>
                  </div>
                  <span class="act-pill act-pill--{{ $e['status_tone'] }}">{{ $e['status'] }}</span>
                  <div class="act-amount {{ $e['is_refunded'] ? 'is-refunded' : '' }}">
                    @if($e['amount_cents'] !== null)
                      {{ format_money($e['amount_cents']) }}
                    @else
                      <span style="opacity:.4">—</span>
                    @endif
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @endforeach
      @endif
    </div>

  </div>

  {{-- ============================================================
       Right: stats + notes
       ============================================================ --}}
  <div style="display:flex;flex-direction:column;gap:16px">

    {{-- Stats --}}
    <div class="ia-card ia-card--tight">
      <div class="cust-stat">
        <span class="cust-stat-label">Total spend</span>
        <span class="cust-stat-value">{{ format_money((int)$totalSpend) }}</span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Appointments</span>
        <span class="cust-stat-value">{{ $appointments->count() }}</span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Last service</span>
        <span class="cust-stat-value">
          {{ $lastService ? \Carbon\Carbon::parse($lastService)->format('M j, Y') : '—' }}
        </span>
      </div>
      <div class="cust-stat">
        <span class="cust-stat-label">Customer since</span>
        <span class="cust-stat-value">{{ $customer->created_at->format('M j, Y') }}</span>
      </div>
    </div>

    {{-- Notes --}}
    <div class="ia-card ia-card--tight">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:12px">
        Notes
      </div>

      {{-- Add note --}}
      <div class="ia-note-add">
        <textarea id="cust-note-input" rows="3" maxlength="200"
          data-maxlength="200" data-counter="cust-note-chars"
          placeholder="Add a note… (200 chars max)"
          style="width:100%;border-radius:var(--ia-r-md);border:0.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);padding:8px 10px;font-size:13px;resize:none;font-family:var(--ia-font)"></textarea>
        <div class="ia-note-add-footer">
          <span class="ia-char-count" id="cust-note-chars">200</span>
          <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="cust-note-submit"
            data-url="{{ $updateUrl }}">
            Add note
          </button>
        </div>
        <p id="cust-note-error" style="font-size:12px;color:#E24B4A;margin-top:4px;display:none"></p>
      </div>

      {{-- Notes list --}}
      <div class="ia-notes" id="cust-notes-list">
        @forelse($notes as $note)
          <div class="ia-note" data-note-id="{{ $note->id }}">
            <div class="ia-note-head">
              <span class="ia-note-author">{{ $note->user?->name ?? 'Staff' }}</span>
              <span class="ia-note-time">
                {{ \Carbon\Carbon::parse($note->created_at)->format('M j, g:i a') }}
              </span>
              <button type="button" class="ia-note-delete"
                data-note-id="{{ $note->id }}" title="Delete">&#x2715;</button>
            </div>
            <div class="ia-note-body">{{ $note->note }}</div>
          </div>
        @empty
          <p class="ia-notes-empty" style="font-size:13px;opacity:.4">No notes yet.</p>
        @endforelse
      </div>
    </div>

  </div>

</div>

@if($currentTenant->classes_enabled)
  <div class="cust-mp-modal" id="cust-mp-modal"
       data-grant-membership-url="{{ route('tenant.customers.memberships.grant', ['subdomain' => $currentTenant->subdomain, 'customerId' => $customer->id]) }}"
       data-grant-pack-url="{{ route('tenant.customers.packs.grant', ['subdomain' => $currentTenant->subdomain, 'customerId' => $customer->id]) }}"
       data-revoke-membership-url-tpl="{{ route('tenant.customers.memberships.revoke', ['subdomain' => $currentTenant->subdomain, 'customerId' => $customer->id, 'id' => '__ID__']) }}"
       data-revoke-pack-url-tpl="{{ route('tenant.customers.packs.revoke', ['subdomain' => $currentTenant->subdomain, 'customerId' => $customer->id, 'id' => '__ID__']) }}">
    <div class="cust-mp-modal-inner">
      <div class="cust-mp-modal-title" id="cust-mp-modal-title">Grant membership</div>
      <div class="cust-mp-modal-sub" id="cust-mp-modal-sub">Pick a product to assign to this customer.</div>

      <div class="cust-mp-product-list" id="cust-mp-product-list">
        {{-- Membership options --}}
        @foreach($membershipProducts as $p)
          <div class="cust-mp-product" data-kind="membership" data-id="{{ $p->id }}">
            <div class="cust-mp-product-main">
              <div class="cust-mp-product-name">{{ $p->name }}</div>
              <div class="cust-mp-product-meta">
                @if($p->type === 'unlimited')
                  Unlimited classes / month
                @else
                  {{ $p->monthly_limit }} classes / month
                @endif
              </div>
            </div>
            <div class="cust-mp-product-price">{{ format_money($p->price_cents) }}/mo</div>
          </div>
        @endforeach
        {{-- Pack options --}}
        @foreach($packProducts as $p)
          <div class="cust-mp-product" data-kind="pack" data-id="{{ $p->id }}" hidden>
            <div class="cust-mp-product-main">
              <div class="cust-mp-product-name">{{ $p->name }}</div>
              <div class="cust-mp-product-meta">
                {{ $p->credit_count }} credits · expires after {{ $p->expiry_days }} days
              </div>
            </div>
            <div class="cust-mp-product-price">{{ format_money($p->price_cents) }}</div>
          </div>
        @endforeach
      </div>

      <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;color:var(--ia-text-muted);margin-bottom:4px">Note (optional)</label>
        <input type="text" id="cust-mp-modal-note" class="ia-input" placeholder="e.g. Comp for referral, manager comp, etc.">
      </div>

      <div id="cust-mp-modal-error" style="display:none;color:#EF4444;font-size:12px;margin-bottom:10px"></div>

      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="closeGrantModal()">Cancel</button>
        <button type="button" class="ia-btn ia-btn--primary" id="cust-mp-modal-grant" onclick="confirmGrant()" disabled>Grant</button>
      </div>
    </div>
  </div>
@endif



@push('scripts')
<script>
// VIP-TOGGLE-JS v1 — handles both desktop + mobile VIP toggle buttons.
(function () {
  function setupVipToggle() {
    document.querySelectorAll('[data-vip-toggle]').forEach(function (btn) {
      if (btn.__vipBound) return;
      btn.__vipBound = true;
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url');
        var csrf = btn.getAttribute('data-csrf');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('_token', csrf);
        fd.append('op', 'toggle_vip');

        fetch(url, {
          method: 'POST',
          body: fd,
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (!data || !data.ok) {
            if (window.IntakeConfirm && IntakeConfirm.alert) {
              IntakeConfirm.alert({ title: 'Couldn\'t toggle VIP', message: 'Please try again.' });
            } else {
              alert('Could not toggle VIP. Please try again.');
            }
            return;
          }
          // Update all VIP UI on the page (desktop + mobile both visible in DOM)
          var isOn = !!data.is_vip;
          document.querySelectorAll('[data-vip-toggle]').forEach(function (b) {
            b.classList.toggle('is-on', isOn);
            var svg = b.querySelector('svg');
            if (svg) svg.setAttribute('fill', isOn ? 'currentColor' : 'none');
            var lbl = b.querySelector('[data-vip-label]');
            if (lbl) lbl.textContent = isOn ? 'VIP' : 'Mark VIP';
          });
          // Desktop badge under name
          document.querySelectorAll('[data-vip-badge]').forEach(function (badge) {
            badge.style.display = isOn ? 'inline-flex' : 'none';
          });
        })
        .catch(function () {
          btn.disabled = false;
          alert('Could not toggle VIP. Please try again.');
        });
      });
    });
  }

  // Mobile note-add handler (mirrors desktop, smaller surface)
  function setupMobileNoteAdd() {
    var btn = document.querySelector('.cmd-note-add-btn');
    var input = document.getElementById('cmd-note-input');
    if (!btn || !input || btn.__bound) return;
    btn.__bound = true;
    btn.addEventListener('click', function () {
      var note = input.value.trim();
      if (!note) return;
      var url = btn.getAttribute('data-url');
      var csrf = btn.getAttribute('data-csrf');
      btn.disabled = true;
      var fd = new FormData();
      fd.append('_method', 'PATCH');
      fd.append('_token', csrf);
      fd.append('op', 'add_note');
      fd.append('note', note);
      fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          if (data && data.ok) {
            // Soft reload to reflect new note. Could splice in DOM but page is short.
            window.location.reload();
          } else {
            alert(data && data.message ? data.message : 'Could not add note.');
          }
        })
        .catch(function () { btn.disabled = false; alert('Could not add note.'); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setupVipToggle(); setupMobileNoteAdd(); });
  } else {
    setupVipToggle(); setupMobileNoteAdd();
  }
})();
</script>
@endpush



@push('scripts')
<script>
// CUST-EDIT-SHEET-JS v1 — mobile bottom-sheet edit form.
(function () {
  window.CustEditSheet = {
    open: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.add('is-open');
      s.classList.add('is-open');
      b.setAttribute('aria-hidden', 'false');
      s.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      // Focus first input after the slide-up settles
      setTimeout(function () {
        var firstInput = s.querySelector('.cust-edit-input');
        if (firstInput) firstInput.focus();
      }, 240);
    },
    close: function () {
      var b = document.getElementById('cust-edit-backdrop');
      var s = document.getElementById('cust-edit-sheet');
      if (!b || !s) return;
      b.classList.remove('is-open');
      s.classList.remove('is-open');
      b.setAttribute('aria-hidden', 'true');
      s.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      var err = document.getElementById('cust-edit-error');
      if (err) err.style.display = 'none';
    },
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') CustEditSheet.close();
  });

  // Submit handler — submit via fetch, reload on success
  var form = document.getElementById('cust-edit-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var saveBtn = form.querySelector('.cust-edit-btn-save');
      var errEl = document.getElementById('cust-edit-error');
      if (errEl) errEl.style.display = 'none';
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving…';

      var fd = new FormData(form);
      fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
      .then(function (r) {
        return r.json().then(function (data) {
          return { ok: r.ok && data && data.ok !== false, status: r.status, data: data };
        });
      })
      .then(function (result) {
        if (result.ok) {
          // Reload to reflect the new values across hero name, contact tiles, page-head, etc.
          window.location.reload();
        } else {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save';
          var msg = (result.data && (result.data.message || (result.data.errors && Object.values(result.data.errors)[0]))) || 'Could not save. Please try again.';
          if (Array.isArray(msg)) msg = msg[0];
          if (errEl) {
            errEl.textContent = msg;
            errEl.style.display = 'block';
          }
        }
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (errEl) {
          errEl.textContent = 'Network error. Please try again.';
          errEl.style.display = 'block';
        }
      });
    });
  }
})();
</script>
@endpush

@endsection

@push('scripts')
<script>
(function () {
  var updateUrl = '{{ $updateUrl }}';
  var csrf      = window.IntakeAdmin.csrfToken;

  // Edit toggle
  var editToggle  = document.getElementById('edit-toggle');
  var editCancel  = document.getElementById('edit-cancel');
  var infoView    = document.getElementById('info-view');
  var infoEdit    = document.getElementById('info-edit');

  if (editToggle) editToggle.addEventListener('click', function () {
    infoView.style.display = 'none';
    infoEdit.style.display = '';
    editToggle.style.display = 'none';
  });
  if (editCancel) editCancel.addEventListener('click', function () {
    infoEdit.style.display = 'none';
    infoView.style.display = '';
    editToggle.style.display = '';
  });

  // AJAX-ify the info edit form so the browser doesn't navigate to JSON.
  if (infoEdit) {
    infoEdit.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(infoEdit);
      var submitBtn = infoEdit.querySelector('button[type="submit"]');
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }

      fetch(infoEdit.action, { method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Save changes'; }
          if (res.ok && res.body && (res.body.ok || res.body.success)) {
            window.IntakeToast.success('Customer updated');
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            window.IntakeToast.error((res.body && res.body.message) || 'Could not save.');
          }
        })
        .catch(function () {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Save changes'; }
          window.IntakeToast.error('Network error. Try again.');
        });
    });
  }

  // Note add
  var noteInput  = document.getElementById('cust-note-input');
  var noteSubmit = document.getElementById('cust-note-submit');
  var noteError  = document.getElementById('cust-note-error');
  var notesList  = document.getElementById('cust-notes-list');
  var noteChars  = document.getElementById('cust-note-chars');

  if (noteInput && noteChars) {
    noteInput.addEventListener('input', function () {
      var rem = 200 - noteInput.value.length;
      noteChars.textContent = rem;
      noteChars.classList.toggle('warn', rem <= 20);
    });
  }

  if (noteSubmit) noteSubmit.addEventListener('click', function () {
    var note = noteInput.value.trim();
    if (!note) { show(noteError, 'Please enter a note.'); return; }
    noteSubmit.disabled = true; noteSubmit.textContent = 'Saving…';

    post({ op: 'add_note', note: note }, function (resp) {
      noteSubmit.disabled = false; noteSubmit.textContent = 'Add note';
      if (!resp.ok) { show(noteError, resp.message || 'Error.'); return; }
      hide(noteError);
      var empty = notesList.querySelector('.ia-notes-empty');
      if (empty) empty.remove();
      var el = document.createElement('div');
      el.className = 'ia-note'; el.setAttribute('data-note-id', resp.id);
      el.innerHTML =
        '<div class="ia-note-head">' +
          '<span class="ia-note-author">' + esc(resp.author) + '</span>' +
          '<span class="ia-note-time">' + esc(resp.created_at) + '</span>' +
          '<button type="button" class="ia-note-delete" data-note-id="' + resp.id + '" title="Delete">&#x2715;</button>' +
        '</div><div class="ia-note-body">' + esc(resp.note) + '</div>';
      notesList.insertBefore(el, notesList.firstChild);
      bindDel(el.querySelector('.ia-note-delete'));
      noteInput.value = '';
      if (noteChars) { noteChars.textContent = '200'; noteChars.classList.remove('warn'); }
    });
  });

  // Note delete
  document.querySelectorAll('.ia-note-delete').forEach(bindDel);

  function bindDel(btn) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!confirm('Delete this note?')) return;
      var noteId = btn.getAttribute('data-note-id');
      post({ op: 'delete_note', note_id: noteId }, function (resp) {
        if (!resp.ok) return;
        var el = document.querySelector('[data-note-id="' + noteId + '"]');
        if (el) el.remove();
        if (!notesList.querySelector('.ia-note')) {
          var p = document.createElement('p');
          p.className = 'ia-notes-empty';
          p.style.cssText = 'font-size:13px;opacity:.4';
          p.textContent = 'No notes yet.';
          notesList.appendChild(p);
        }
      });
    });
  }

  function post(data, cb) {
    var fd = new FormData();
    fd.append('_token', csrf); fd.append('_method', 'PATCH');
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fetch(updateUrl, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(cb)
      .catch(function () { show(noteError, 'Network error.'); });
  }
  function show(el, msg) { if (el) { el.textContent = msg; el.style.display = ''; } }
  function hide(el)       { if (el) el.style.display = 'none'; }
  function esc(s)         { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
}());

/**
 * Grant/revoke membership and pack flow. Lives outside the IIFE above so the
 * inline onclick handlers in the blade can reach these globals. Modal toggles
 * which kind (membership/pack) is selectable.
 */
(function () {
  var modal = document.getElementById('cust-mp-modal');
  if (!modal) return; // tenant doesn't have classes enabled

  var titleEl   = document.getElementById('cust-mp-modal-title');
  var subEl     = document.getElementById('cust-mp-modal-sub');
  var listEl    = document.getElementById('cust-mp-product-list');
  var noteEl    = document.getElementById('cust-mp-modal-note');
  var errEl     = document.getElementById('cust-mp-modal-error');
  var grantBtn  = document.getElementById('cust-mp-modal-grant');
  var grantMembershipUrl  = modal.dataset.grantMembershipUrl;
  var grantPackUrl        = modal.dataset.grantPackUrl;
  var revokeMembershipTpl = modal.dataset.revokeMembershipUrlTpl;
  var revokePackTpl       = modal.dataset.revokePackUrlTpl;
  var csrf       = window.IntakeAdmin.csrfToken;

  var currentKind = null;
  var selectedId  = null;

  window.openGrantModal = function (kind) {
    currentKind = kind;
    selectedId  = null;
    titleEl.textContent = kind === 'membership' ? 'Grant membership' : 'Grant pack';
    subEl.textContent   = kind === 'membership'
      ? 'Pick a membership tier to assign. Period starts today.'
      : 'Pick a pack to assign. Credits available immediately, expiry counts from today.';
    noteEl.value = '';
    errEl.style.display = 'none';
    grantBtn.disabled = true;

    // Show only the relevant kind in the product list
    listEl.querySelectorAll('.cust-mp-product').forEach(function (row) {
      var match = row.dataset.kind === kind;
      row.hidden = !match;
      row.classList.remove('is-selected');
    });
    modal.classList.add('is-open');
  };

  window.closeGrantModal = function () {
    modal.classList.remove('is-open');
  };

  // Click product → select
  listEl.addEventListener('click', function (e) {
    var row = e.target.closest('.cust-mp-product');
    if (!row || row.dataset.kind !== currentKind) return;
    listEl.querySelectorAll('.cust-mp-product').forEach(function (r) { r.classList.remove('is-selected'); });
    row.classList.add('is-selected');
    selectedId = row.dataset.id;
    grantBtn.disabled = false;
  });

  // Click outside / Esc closes
  modal.addEventListener('click', function (e) {
    if (e.target === modal) window.closeGrantModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) window.closeGrantModal();
  });

  window.confirmGrant = function () {
    if (!selectedId || !currentKind) return;
    grantBtn.disabled = true;
    grantBtn.textContent = 'Granting…';
    errEl.style.display = 'none';

    var path = currentKind === 'membership' ? 'memberships' : 'packs';
    var url = currentKind === 'membership' ? grantMembershipUrl : grantPackUrl;

    var fd = new FormData();
    fd.append('_token', csrf);
    fd.append('product_id', selectedId);
    fd.append('note', noteEl.value || '');

    fetch(url, {
      method: 'POST',
      body: fd,
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
      })
      .then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          // Reload to reflect the new state. Cheaper than rebuilding card client-side.
          window.location.reload();
        } else {
          errEl.textContent = (res.body && res.body.message) || 'Grant failed.';
          errEl.style.display = '';
          grantBtn.disabled = false;
          grantBtn.textContent = 'Grant';
        }
      })
      .catch(function () {
        errEl.textContent = 'Network error. Try again.';
        errEl.style.display = '';
        grantBtn.disabled = false;
        grantBtn.textContent = 'Grant';
      });
  };

  /**
   * Revoke flow — uses the app's confirm modal, then DELETEs. Audit note is
   * written server-side automatically. Reloads page on success to show the
   * updated state (history entry appears, active row removed).
   */
  window.revokeMP = function (kind, id) {
    var label = kind === 'membership' ? 'membership' : 'pack';
    var title = kind === 'membership' ? 'Cancel membership?' : 'Cancel pack?';
    var message = kind === 'membership'
      ? 'This will deactivate the membership immediately. The customer loses access to their classes-included tier. An audit note is added to the customer record.'
      : 'This will deactivate the pack and forfeit any remaining credits. An audit note is added to the customer record.';

    window.IntakeConfirm.show({
      title: title,
      message: message,
      confirmText: 'Cancel ' + label,
      cancelText: 'Keep it',
      danger: true,
    }).then(function (ok) {
      if (!ok) return;

      var tpl  = kind === 'membership' ? revokeMembershipTpl : revokePackTpl;
      var url  = tpl.replace('__ID__', id);

      var fd = new FormData();
      fd.append('_token', csrf);
      fd.append('_method', 'DELETE');

      fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
        })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            window.location.reload();
          } else {
            window.IntakeConfirm.show({
              title: 'Cancel failed',
              message: (res.body && res.body.message) || 'Something went wrong. Please try again.',
              confirmText: 'OK',
              cancelText: '',
            });
          }
        })
        .catch(function () {
          window.IntakeConfirm.show({
            title: 'Network error',
            message: 'Could not reach the server. Try again.',
            confirmText: 'OK',
            cancelText: '',
          });
        });
    });
  };
})();
</script>
@endpush

@push('scripts')
<script>
  // Activity timeline — month collapse and dropdown filter.
  // Both behaviors are local-only state (refresh resets) — keeps the
  // implementation small and avoids per-customer preference storage.
  function toggleActMonth(headEl) {
    const monthEl = headEl.parentElement;
    const body = monthEl.querySelector('.act-month-body');
    const chevDown = monthEl.querySelector('.ti-chevron-down');
    const chevRight = monthEl.querySelector('.ti-chevron-right');
    const isExpanded = body.style.display !== 'none';

    body.style.display = isExpanded ? 'none' : 'block';
    chevDown.style.display = isExpanded ? 'none' : 'inline-block';
    chevRight.style.display = isExpanded ? 'inline-block' : 'none';
  }

  (function bindActivityFilter() {
    const sel = document.getElementById('activity-filter');
    if (!sel) return;
    sel.addEventListener('change', () => {
      const value = sel.value;
      document.querySelectorAll('.act-row').forEach(row => {
        const kind = row.dataset.actKind;
        const show = value === 'all' || kind === value;
        row.style.display = show ? 'grid' : 'none';
      });
      // Hide month headers for months with zero matching events.
      // Empty months are noise; collapse them out of view entirely.
      document.querySelectorAll('.act-month').forEach(month => {
        const visible = month.querySelectorAll('.act-row:not([style*="display: none"])').length > 0;
        month.style.display = visible ? 'block' : 'none';
      });
    });
  })();
</script>
@endpush
