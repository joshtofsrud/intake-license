@extends('layouts.tenant.app')
@section('title', 'Schedule · Deliveries')

{{-- MARKER-PATCH-152B — Day + Week views, drawer, create/edit. --}}

@push('styles')
<style>
  /* ============================================================
     Delivery-specific tokens & components.
     Reuses base ia-* tokens for theme parity.
     ============================================================ */
  :root {
    --del-pickup:   #60A5FA;
    --del-dropoff:  #FB923C;
  }

  /* Toolbar */
  .del-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 18px; gap: 16px; flex-wrap: wrap;
  }
  .del-day-nav { display: flex; align-items: center; gap: 8px; }
  .del-day-nav .date-label { font-size: 16px; font-weight: 600; min-width: 220px; }
  .del-icon-btn {
    width: 30px; height: 30px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    color: var(--ia-text-2, rgba(255,255,255,.78));
    border-radius: 4px;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; cursor: pointer;
  }
  .del-icon-btn:hover { color: var(--ia-text); border-color: var(--ia-border-strong, rgba(255,255,255,.14)); }
  .del-today-btn {
    height: 30px; padding: 0 14px;
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    color: var(--ia-text-2, rgba(255,255,255,.78));
    border-radius: 4px;
    font-size: 12.5px;
    display: inline-flex; align-items: center;
    text-decoration: none;
  }
  .del-today-btn:hover { color: var(--ia-text); }
  .del-dw-toggle {
    display: inline-flex; padding: 3px;
    background: var(--ia-surface-2);
    border-radius: 6px;
    border: 0.5px solid var(--ia-border);
  }
  .del-dw-toggle a {
    padding: 5px 14px;
    color: var(--ia-text-muted, rgba(255,255,255,.55));
    border-radius: 4px;
    font-size: 12.5px;
    text-decoration: none;
  }
  .del-dw-toggle a.is-active {
    background: var(--ia-accent, #BEF264);
    color: var(--ia-accent-text, #0a0a0a);
    font-weight: 600;
  }
  .del-toolbar-right { display: flex; align-items: center; gap: 12px; }

  /* DAY · capacity single column */
  .del-day-timeline {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    overflow: hidden;
  }
  .del-hour-row {
    display: grid;
    grid-template-columns: 70px 1fr;
    border-bottom: 0.5px solid var(--ia-border);
    min-height: 56px;
  }
  .del-hour-row:last-child { border-bottom: 0; }
  .del-hour-label {
    padding: 10px 14px 10px 18px;
    font-size: 11.5px;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    border-right: 0.5px solid var(--ia-border);
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    text-align: right;
  }
  .del-hour-content {
    padding: 8px 14px;
    display: flex; flex-direction: column; gap: 6px;
  }

  /* DAY · time-slot multi-resource grid */
  .del-resource-grid {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    overflow: hidden;
  }
  .del-grid-head, .del-grid-row { display: grid; }
  .del-grid-head {
    border-bottom: 0.5px solid var(--ia-border-strong, rgba(255,255,255,.14));
    background: var(--ia-surface-2);
  }
  .del-grid-head > div {
    padding: 12px 14px;
    font-size: 12px;
    color: var(--ia-text);
    border-right: 0.5px solid var(--ia-border);
    font-weight: 600;
  }
  .del-grid-head > div:first-child { color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 400; }
  .del-grid-head > div:last-child { border-right: 0; }
  .del-grid-head .res-meta { display: block; font-size: 10.5px; color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 400; margin-top: 1px; }
  .del-grid-row {
    border-bottom: 0.5px solid var(--ia-border);
    min-height: 60px;
  }
  .del-grid-row > div {
    border-right: 0.5px solid var(--ia-border);
    padding: 6px;
  }
  .del-grid-row > div:first-child {
    padding: 10px 14px 10px 18px;
    font-size: 11.5px;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    text-align: right;
    background: var(--ia-surface-2);
  }
  .del-grid-row > div:last-child { border-right: 0; }

  /* Delivery card (used in capacity day) */
  .del-card {
    display: grid;
    grid-template-columns: 4px 1fr auto;
    gap: 14px;
    align-items: center;
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    border-radius: 4px;
    padding: 10px 14px 10px 0;
    overflow: hidden;
    cursor: pointer;
    transition: border-color .12s;
    color: inherit;
    text-decoration: none;
  }
  .del-card:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.14)); }
  .del-card .stripe { width: 4px; min-height: 50px; align-self: stretch; }
  .del-card.is-pickup .stripe  { background: var(--del-pickup); }
  .del-card.is-dropoff .stripe { background: var(--del-dropoff); }
  .del-card .body { display: grid; gap: 3px; min-width: 0; }
  .del-card .head-row {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 600;
  }
  .del-tag {
    font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
    padding: 2px 6px; border-radius: 3px; font-weight: 700;
  }
  .del-tag.is-pickup  { color: var(--del-pickup);  background: rgba(96,165,250,.12); }
  .del-tag.is-dropoff { color: var(--del-dropoff); background: rgba(251,146,60,.12); }
  .del-card .meta {
    font-size: 11.5px; color: var(--ia-text-muted, rgba(255,255,255,.55));
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  }
  .del-card .time-col {
    text-align: right;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 12px; color: var(--ia-text-2, rgba(255,255,255,.78));
    padding-right: 4px; min-width: 90px;
  }
  .del-card .time-col .window {
    font-size: 10.5px; color: var(--ia-text-dim, rgba(255,255,255,.42));
    display: block; margin-top: 2px;
  }

  /* Compact slot event used in time-slot grid */
  .del-slot {
    display: block;
    padding: 8px 10px;
    border-radius: 4px;
    font-size: 11.5px;
    margin-bottom: 4px;
    cursor: pointer;
    color: var(--ia-text); text-decoration: none;
  }
  .del-slot:hover { filter: brightness(1.15); }
  .del-slot.is-pickup  { background: rgba(96,165,250,.10); border-left: 3px solid var(--del-pickup); }
  .del-slot.is-dropoff { background: rgba(251,146,60,.10); border-left: 3px solid var(--del-dropoff); }
  .del-slot .name { font-weight: 600; font-size: 12px; }
  .del-slot .sub  { font-size: 10.5px; opacity: .75; margin-top: 1px; }

  /* WEEK */
  .del-week {
    background: var(--ia-surface);
    border: 0.5px solid var(--ia-border);
    border-radius: 8px;
    overflow-x: auto; /* MARKER-PATCH-345 — scroll instead of crush */
    display: grid;
    grid-template-columns: repeat(7, minmax(160px, 1fr));
  }
  .del-week-col { border-right: 0.5px solid var(--ia-border); min-height: 480px; }
  .del-week-col:last-child { border-right: 0; }
  .del-week-head {
    padding: 10px 12px;
    border-bottom: 0.5px solid var(--ia-border-strong, rgba(255,255,255,.14));
    background: var(--ia-surface-2);
  }
  .del-week-head .dow {
    font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
    color: var(--ia-text-dim, rgba(255,255,255,.42)); font-weight: 600;
  }
  .del-week-head .date {
    font-size: 18px; font-weight: 700; margin-top: 2px;
    color: var(--ia-text);
  }
  .del-week-head.is-today .date { color: var(--ia-accent, #BEF264); }
  .del-week-head .count {
    font-size: 10.5px; color: var(--ia-text-dim, rgba(255,255,255,.42));
    margin-top: 4px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
  }
  .del-week-body { padding: 8px; display: flex; flex-direction: column; gap: 5px; }
  .del-week-card {
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    border-left-width: 3px;
    border-radius: 4px;
    padding: 7px 9px;
    font-size: 11.5px;
    cursor: pointer; text-decoration: none; color: inherit; display: block;
  }
  .del-week-card.is-pickup  { border-left-color: var(--del-pickup); }
  .del-week-card.is-dropoff { border-left-color: var(--del-dropoff); }

  /* MARKER-PATCH-391 — completed deliveries: muted + green check badge, matching
     the calendar's completed treatment (.ia-cal-appt.status-completed). */
  .del-card.is-completed,
  .del-week-card.is-completed {
    opacity: .55;
    position: relative;
    padding-right: 24px;
  }
  .del-week-card.is-completed .name {
    text-decoration: line-through;
    text-decoration-thickness: 1px;
    text-decoration-color: var(--ia-text-muted, #6A6A62);
  }
  .del-card.is-completed::after,
  .del-week-card.is-completed::after {
    content: '';
    position: absolute;
    top: 6px;
    right: 6px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #3B6D11 url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none'><path d='M2 5l2 2 4-4' stroke='white' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat center / 9px 9px;
  }
  .del-week-card .time {
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    color: var(--ia-text-dim, rgba(255,255,255,.42));
    font-size: 10.5px; display: block; margin-bottom: 2px;
  }
  .del-week-card .name { font-weight: 600; font-size: 11.5px; color: var(--ia-text); }
  .del-week-card .addr {
    font-size: 10.5px; color: var(--ia-text-muted, rgba(255,255,255,.55));
    margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  /* Empty state */
  .del-empty {
    padding: 48px 24px;
    text-align: center;
    color: var(--ia-text-muted, rgba(255,255,255,.55));
    font-size: 13px;
  }
  .del-empty .icon { font-size: 32px; opacity: .35; margin-bottom: 12px; }

  /* Drawer */
  .del-drawer-bg {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 200; /* MARKER-PATCH-369 — above the bottom tab bar */
  }
  .del-drawer-bg.is-open { display: block; }
  .del-drawer {
    display: none; position: fixed; top: 0; right: 0; bottom: 0;
    width: 520px; max-width: 100vw;
    background: var(--ia-surface);
    border-left: 0.5px solid var(--ia-border-strong, rgba(255,255,255,.14));
    z-index: 201; overflow-y: auto; /* MARKER-PATCH-369 */
  }
  .del-drawer.is-open { display: block; }
  .del-drawer-head {
    padding: 20px 24px 16px;
    border-bottom: 0.5px solid var(--ia-border);
    display: flex; align-items: flex-start; justify-content: space-between;
  }
  .del-drawer-title { font-size: 18px; font-weight: 700; }
  .del-drawer-sub { font-size: 12px; color: var(--ia-text-muted, rgba(255,255,255,.55)); margin-top: 2px; }
  .del-drawer-close {
    width: 28px; height: 28px;
    background: transparent; border: 0;
    color: var(--ia-text-muted, rgba(255,255,255,.55));
    border-radius: 4px; font-size: 14px; cursor: pointer;
  }
  .del-drawer-close:hover { background: rgba(255,255,255,.06); color: var(--ia-text); }
  .del-drawer-body { padding: 18px 24px; }
  .del-row { margin-bottom: 16px; }
  .del-row.split { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .del-label {
    display: block; font-size: 11px;
    color: var(--ia-text-muted, rgba(255,255,255,.55));
    text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 6px; font-weight: 600;
  }
  .del-input, .del-select, .del-textarea {
    width: 100%;
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    color: var(--ia-text);
    padding: 9px 12px; border-radius: 4px;
    font-size: 13px; font-family: inherit;
  }
  .del-input:focus, .del-select:focus, .del-textarea:focus {
    outline: none; border-color: var(--ia-accent, #BEF264);
  }
  .del-textarea { resize: vertical; min-height: 60px; }

  /* MARKER-PATCH-153-FIX1 — match customer-search component to drawer input styling */
  .del-drawer .ia-cs,
  .del-drawer .ia-cs-input {
    width: 100%;
    box-sizing: border-box;
  }
  .del-drawer .ia-cs-input {
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    color: var(--ia-text);
    padding: 9px 12px;
    padding-right: 28px;
    border-radius: 4px;
    font-size: 13px;
    font-family: inherit;
  }
  .del-drawer .ia-cs-input:focus {
    outline: none;
    border-color: var(--ia-accent, #BEF264);
  }
  .del-type-toggle { display: flex; gap: 8px; }
  .del-type-tile {
    flex: 1; padding: 14px 12px;
    background: var(--ia-surface-2);
    border: 0.5px solid var(--ia-border);
    border-radius: 4px;
    cursor: pointer; text-align: center;
    transition: all .12s;
  }
  .del-type-tile.is-selected.is-pickup  { border-color: var(--del-pickup);  background: rgba(96,165,250,.06); }
  .del-type-tile.is-selected.is-dropoff { border-color: var(--del-dropoff); background: rgba(251,146,60,.06); }
  .del-type-tile .icon { font-size: 18px; margin-bottom: 4px; }
  .del-type-tile .label { font-size: 12px; font-weight: 600; }
  .del-type-tile .sub { font-size: 10.5px; color: var(--ia-text-muted, rgba(255,255,255,.55)); margin-top: 2px; }
  .del-help { font-size: 11px; color: var(--ia-text-dim, rgba(255,255,255,.42)); margin-top: 4px; }
  .del-error {
    font-size: 11.5px; color: #F87171;
    background: rgba(248,113,113,.06);
    border-left: 2px solid #F87171;
    padding: 6px 9px;
    margin-top: 6px;
  }
  .del-notify {
    background: rgba(190,242,100,.04);
    border: 0.5px solid rgba(190,242,100,.15);
    padding: 12px 14px;
    border-radius: 4px;
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 12px;
  }
  .del-notify strong { color: var(--ia-accent, #BEF264); }
  .del-drawer-foot {
    padding: 16px 24px;
    border-top: 0.5px solid var(--ia-border);
    display: flex; flex-direction: column; gap: 10px;
    position: sticky; bottom: 0;
    background: var(--ia-surface);
  }
  /* MARKER-PATCH-157-FIX1 — top row: cancel left, action buttons right */
  .del-drawer-foot-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
  .del-drawer-foot-right { display: flex; gap: 10px; }
  .del-btn--full { width: 100%; justify-content: center; } /* MARKER-PATCH-157-FIX1 */
  .del-btn {
    height: 32px; padding: 0 14px;
    border: 0; border-radius: 4px;
    font-size: 12.5px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    font-family: inherit;
    white-space: nowrap; /* MARKER-PATCH-157-FIX1 — prevent label wrapping */
    text-decoration: none;
  }
  .del-btn--primary { background: var(--ia-accent, #BEF264); color: var(--ia-accent-text, #0a0a0a); font-weight: 600; }
  .del-btn--primary:hover { filter: brightness(1.08); }
  .del-btn--ghost {
    background: var(--ia-surface);
    color: var(--ia-text-2, rgba(255,255,255,.78));
    border: 0.5px solid var(--ia-border);
  }
  .del-btn--ghost:hover { border-color: var(--ia-border-strong, rgba(255,255,255,.14)); color: var(--ia-text); }
  .del-btn--danger {
    background: transparent; color: #F87171;
    border: 0.5px solid rgba(248,113,113,.3);
  }
  .del-btn--danger:hover { background: rgba(248,113,113,.08); }

  /* ===================== MARKER-PATCH-352 =====================
     Deliveries WEEK -> phone route-list (matrix stays on >=768px). */
  @media (max-width: 767px) {
    .del-week { display: block; }

    .del-week-col {
      border-right: 0;
      min-height: 0;
      border-bottom: 0.5px solid var(--ia-border);
      margin-bottom: 2px;
      padding-bottom: 6px;
    }
    .del-week-col:last-child { border-bottom: 0; margin-bottom: 0; }

    /* sticky day header: Dow . date . stop count, inline */
    .del-week-head {
      position: sticky; top: 0; z-index: 3;
      display: flex; align-items: baseline; gap: 8px;
      padding: 13px 16px 9px;
      background: var(--ia-bg, #0b0b0b);
      border-bottom: 0.5px solid var(--ia-border);
    }
    .del-week-head .dow {
      font-size: 11px; font-weight: 700; letter-spacing: .6px;
      text-transform: uppercase;
      color: var(--ia-text-dim, rgba(255,255,255,.42));
    }
    .del-week-head .date { font-size: 15px; font-weight: 700; color: var(--ia-text); }
    .del-week-head .count {
      margin-left: auto; font-size: 11.5px; font-weight: 500;
      color: var(--ia-text-muted, rgba(255,255,255,.55));
    }
    .del-week-head.is-today .dow,
    .del-week-head.is-today .date { color: var(--ia-accent, #BEF264); }

    .del-week-body { padding: 8px 16px 4px; gap: 8px; }

    /* card -> route row: [time] [name / addr] [type badge] */
    .del-week-card {
      display: grid;
      grid-template-columns: auto 1fr auto;
      grid-template-areas:
        "time name tag"
        "time addr tag";
      align-items: center;
      column-gap: 12px; row-gap: 1px;
      padding: 11px 13px;
      border-radius: 9px;
      border-left-width: 3px;
    }
    .del-week-card .time {
      grid-area: time; align-self: center; display: block;
      margin-bottom: 0; line-height: 1.15;
      font-size: 12px; font-weight: 700; color: var(--ia-text);
    }
    .del-week-card .name {
      grid-area: name; font-size: 14px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .del-week-card .addr { grid-area: addr; font-size: 12px; margin-top: 0; }

    /* type badge — text derived from .is-pickup / .is-dropoff, no markup change */
    .del-week-card::after {
      grid-area: tag; align-self: center; justify-self: end;
      content: "Pickup";
      font-size: 9px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase;
      padding: 3px 8px; border-radius: 5px;
      color: var(--del-pickup); background: rgba(96,165,250,.14);
    }
    .del-week-card.is-dropoff::after {
      content: "Dropoff";
      color: var(--del-dropoff); background: rgba(251,146,60,.14);
    }

    /* empty-day placeholder: left-aligned, compact */
    .del-week-body > div { text-align: left !important; padding: 6px 2px 8px !important; }
  }
  /* =================== end patch-352 block =================== */

  /* ===================== MARKER-PATCH-353 =====================
     Deliveries toolbar -> no phone overflow (day + week share it). */
  @media (max-width: 767px) {
    /* action cluster: wrap instead of running off-screen */
    .del-toolbar-right { width: 100%; flex-wrap: wrap; gap: 8px; }
    .del-toolbar-right .del-dw-toggle { flex: 0 0 auto; }
    .del-toolbar-right .del-btn { flex: 1 1 auto; justify-content: center; }

    /* date range drops to its own line; arrows + This week stay paired */
    .del-day-nav { width: 100%; flex-wrap: wrap; row-gap: 4px; }
    .del-day-nav .date-label {
      min-width: 0;
      flex: 1 1 100%;
      order: 5;
      font-size: 14px;
    }
  }
  /* =================== end patch-353 block =================== */

  /* MARKER-PATCH-369 — pickup/dropoff drawer phone layout. Footer was
     overflowing (up to 4 action buttons in a no-wrap row) and sat under the
     bottom tab bar (drawer z-index now clears the nav). */
  @media (max-width: 600px) {
    .del-drawer-head { padding: 16px 16px 14px; }
    .del-drawer-body { padding: 16px; }
    .del-drawer-foot { padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px)); }
    .del-row.split { grid-template-columns: 1fr; }
    .del-drawer-foot-row { flex-wrap: wrap; gap: 8px; }
    #del-foot-left { width: 100%; }
    #del-foot-left > .del-btn { width: 100%; }
    .del-drawer-foot-right { flex: 1 1 100%; flex-wrap: wrap; gap: 8px; }
    .del-drawer-foot-right > .del-btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
  }

  /* ===================== MARKER-PATCH-398 =====================
     Completed stops on the phone route-list: green check in the type-tag slot,
     overriding patch-352's Pickup/Dropoff badge for both types. */
  @media (max-width: 767px) {
    .del-week-card.is-completed { padding-right: 13px; }
    .del-week-card.is-completed::after {
      grid-area: tag;
      align-self: center;
      justify-self: end;
      position: static;
      top: auto;
      right: auto;
      content: '';
      width: 18px;
      height: 18px;
      padding: 0;
      border-radius: 50%;
      background: #3B6D11 url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10' fill='none'><path d='M2 5l2 2 4-4' stroke='white' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat center / 11px 11px;
    }
  }
  /* =================== end patch-398 block =================== */
</style>
@endpush

@section('content')
@php
  $tz       = $tenant->timezone ?? config('app.timezone', 'UTC');
  $today    = \Carbon\Carbon::now($tz)->startOfDay();
  $isToday  = $date->copy()->setTimezone($tz)->startOfDay()->equalTo($today);

  // MARKER-PATCH-152C — what notification channels will fire on save?
  $notifyEmail = $tenant->notificationEnabled('delivery_scheduled_email');
  $notifySms   = $tenant->notificationEnabled('delivery_scheduled_sms');

  // MARKER-PATCH-152B-FIX3 — auto-fit day-view hour range
  // Default to 8am–6pm. If deliveries exist outside that, expand to cover them.
  $openHour  = 8;
  $closeHour = 18;
  if ($view === 'day' && $deliveries->isNotEmpty()) {
    $earliestHour = 23;
    $latestHour   = 0;
    foreach ($deliveries as $d) {
      $local = $d->scheduled_at->copy()->setTimezone($tz);
      $startH = (int) $local->format('G');
      $endH   = (int) $local->copy()->addMinutes($d->window_minutes ?: 30)->format('G');
      if ($startH < $earliestHour) $earliestHour = $startH;
      if ($endH   > $latestHour)   $latestHour   = $endH;
    }
    $openHour  = min($openHour,  $earliestHour);
    $closeHour = max($closeHour, $latestHour + 1);
    // Safety clamp 0–24
    $openHour  = max(0, min(23, $openHour));
    $closeHour = max($openHour + 1, min(24, $closeHour));
  }

  // Group day-view deliveries by hour (for capacity mode).
  $byHour = [];
  for ($h = $openHour; $h < $closeHour; $h++) $byHour[$h] = [];
  if ($view === 'day' && !$is_timeslot) {
    foreach ($deliveries as $d) {
      $h = (int) $d->scheduled_at->copy()->setTimezone($tz)->format('G');
      if (isset($byHour[$h])) $byHour[$h][] = $d;
    }
  }

  // For week view: prev/next week date strings.
  if ($view === 'week') {
    $weekStart = $date->copy()->setTimezone($tz)->startOfWeek(\Carbon\Carbon::MONDAY);
    $weekEnd   = $weekStart->copy()->addDays(6);
    $prevDate  = $weekStart->copy()->subWeek()->toDateString();
    $nextDate  = $weekStart->copy()->addWeek()->toDateString();
  } else {
    $prevDate = $date->copy()->subDay()->toDateString();
    $nextDate = $date->copy()->addDay()->toDateString();
  }
@endphp

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Schedule</h1>
    <p class="ia-page-subtitle">
      Deliveries ·
      @if($view === 'week')
        Week of {{ $weekStart->format('M j') }}
      @else
        {{ $date->format('l, F j, Y') }}
      @endif
    </p>
  </div>
</div>

<x-tenant.schedule-tabs active="deliveries" />

@if(session('success'))
  <div style="margin-bottom: 16px; padding: 10px 14px; background: rgba(134,239,172,.08); border: 0.5px solid rgba(134,239,172,.2); border-radius: 6px; color: #86EFAC; font-size: 13px;">
    {{ session('success') }}
  </div>
@endif

<div class="del-toolbar">
  <div class="del-day-nav">
    <a class="del-icon-btn" href="?view={{ $view }}&date={{ $prevDate }}" title="Previous">‹</a>
    <a class="del-today-btn" href="?view={{ $view }}">{{ $view === 'week' ? 'This week' : 'Today' }}</a>
    <a class="del-icon-btn" href="?view={{ $view }}&date={{ $nextDate }}" title="Next">›</a>
    <span class="del-day-nav date-label">
      @if($view === 'week')
        {{ $weekStart->format('M j') }} – {{ $weekEnd->format('M j, Y') }}
      @else
        {{ $date->format('l, M j') }}
      @endif
    </span>
  </div>
  <div class="del-toolbar-right">
    <div class="del-dw-toggle">
      <a href="?view=day&date={{ $date->toDateString() }}" class="{{ $view === 'day' ? 'is-active' : '' }}">Day</a>
      <a href="?view=week&date={{ $date->toDateString() }}" class="{{ $view === 'week' ? 'is-active' : '' }}">Week</a>
    </div>
    {{-- MARKER-PATCH-321 --}}
    @if($view === 'day')
      <a class="del-btn del-btn--ghost" href="{{ route('tenant.deliveries.slips') }}?date={{ $date->toDateString() }}" target="_blank" rel="noopener">&#9113; Print slips</a>
    @endif
    <button type="button" class="del-btn del-btn--ghost" onclick="delOpenCreate('pickup')">+ Pickup</button>
    <button type="button" class="del-btn del-btn--primary" onclick="delOpenCreate('dropoff')">+ Dropoff</button>
  </div>
</div>

{{-- ===========================================================
     VIEW: DAY · capacity mode (single column)
     =========================================================== --}}
@if($view === 'day' && !$is_timeslot)
  <div class="del-day-timeline">
    @for($h = $openHour; $h < $closeHour; $h++)
      @php
        $label = $h === 0 ? '12 AM' : ($h === 12 ? '12 PM' : ($h < 12 ? $h . ' AM' : ($h - 12) . ' PM'));
      @endphp
      <div class="del-hour-row">
        <div class="del-hour-label">{{ $label }}</div>
        <div class="del-hour-content">
          @foreach($byHour[$h] as $d)
            <a class="del-card is-{{ $d->type }} {{ $d->status === 'completed' ? 'is-completed' : '' }}"
               href="#" onclick="delOpenEdit({{ json_encode((string) $d->id) }}); return false;">
              <div class="stripe"></div>
              <div class="body">
                <div class="head-row">
                  <span class="del-tag is-{{ $d->type }}">{{ ucfirst($d->type) }}</span>
                  {{ trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer' }}
                </div>
                <div class="meta">
                  @if($d->address)<span>{{ $d->address }}</span>@endif
                  @if($d->notes)<span style="opacity:.7">"{{ \Illuminate\Support\Str::limit($d->notes, 60) }}"</span>@endif
                </div>
              </div>
              <div class="time-col">
                {{ $d->scheduled_at->copy()->setTimezone($tz)->format('g:i A') }}
                <span class="window">
                  {{ $d->scheduled_at->copy()->setTimezone($tz)->format('g:i') }}
                  –
                  {{ $d->scheduled_at->copy()->setTimezone($tz)->addMinutes($d->window_minutes)->format('g:i A') }}
                </span>
              </div>
            </a>
          @endforeach
        </div>
      </div>
    @endfor
  </div>

{{-- ===========================================================
     VIEW: DAY · time-slot mode (resource columns)
     =========================================================== --}}
@elseif($view === 'day' && $is_timeslot)
  @if($resources->isEmpty())
    <div class="del-empty" style="background:var(--ia-surface); border:0.5px dashed var(--ia-border); border-radius:8px;">
      <div class="icon">🚚</div>
      <div style="font-size:14px; color:var(--ia-text); font-weight:600; margin-bottom: 6px;">No delivery resources yet</div>
      <div style="max-width: 420px; margin: 0 auto;">
        Add at least one delivery resource (a vehicle, driver lane, or in-shop drop slot) to start scheduling.
      </div>
      <a href="{{ route('tenant.deliveries.resources.index') }}"
         style="display: inline-flex; margin-top: 14px; gap: 6px; padding: 9px 14px; background: rgba(190,242,100,.08); color: var(--ia-accent, #BEF264); border: 0.5px solid rgba(190,242,100,.2); border-radius: 6px; font-size: 12.5px; font-weight: 600; text-decoration: none;">
        Set up delivery resources &rarr;
      </a>
    </div>
  @else
    @php
      $cols = '70px ' . str_repeat('1fr ', $resources->count());
      // Group deliveries by (resource_id, hour)
      $byResHour = [];
      foreach ($resources as $res) {
        $byResHour[$res->id] = [];
        for ($h = $openHour; $h < $closeHour; $h++) $byResHour[$res->id][$h] = [];
      }
      $byResHour['unassigned'] = [];
      for ($h = $openHour; $h < $closeHour; $h++) $byResHour['unassigned'][$h] = [];
      foreach ($deliveries as $d) {
        $h = (int) $d->scheduled_at->copy()->setTimezone($tz)->format('G');
        $rid = $d->delivery_resource_id ?: 'unassigned';
        if (isset($byResHour[$rid][$h])) $byResHour[$rid][$h][] = $d;
      }
    @endphp
    <div class="del-resource-grid">
      <div class="del-grid-head" style="grid-template-columns: {{ $cols }};">
        <div></div>
        @foreach($resources as $res)
          <div>
            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background: {{ $res->color_hex }}; margin-right:6px; vertical-align:middle;"></span>
            {{ $res->name }}
            @if($res->subtitle)<span class="res-meta">{{ $res->subtitle }}</span>@endif
          </div>
        @endforeach
      </div>
      @for($h = $openHour; $h < $closeHour; $h++)
        @php
          $label = $h === 0 ? '12 AM' : ($h === 12 ? '12 PM' : ($h < 12 ? $h . ' AM' : ($h - 12) . ' PM'));
        @endphp
        <div class="del-grid-row" style="grid-template-columns: {{ $cols }};">
          <div>{{ $label }}</div>
          @foreach($resources as $res)
            <div>
              @foreach($byResHour[$res->id][$h] as $d)
                <a class="del-slot is-{{ $d->type }}"
                   href="#" onclick="delOpenEdit({{ json_encode((string) $d->id) }}); return false;">
                  <div class="name">
                    {{ $d->scheduled_at->copy()->setTimezone($tz)->format('g:i') }}
                    · {{ ucfirst($d->type) }}
                  </div>
                  <div class="sub">{{ trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer' }}</div>
                </a>
              @endforeach
            </div>
          @endforeach
        </div>
      @endfor
    </div>
    @if(!empty($byResHour['unassigned']) && collect($byResHour['unassigned'])->flatten()->count())
      <div style="margin-top: 14px; padding: 12px 14px; background: var(--ia-surface); border: 0.5px dashed var(--ia-border); border-radius: 6px; font-size: 12px; color: var(--ia-text-muted, rgba(255,255,255,.55));">
        <strong>{{ collect($byResHour['unassigned'])->flatten()->count() }} unassigned</strong> delivery(s) today &mdash; click them in the column above to assign.
      </div>
    @endif
  @endif

{{-- ===========================================================
     VIEW: WEEK (both modes — 7-column chronological)
     =========================================================== --}}
@else
  <div class="del-week">
    @php $i = 0; @endphp
    @foreach($days as $dayKey => $dayDeliveries)
      @php
        $dayDate = \Carbon\Carbon::parse($dayKey);
        $isThatToday = $dayDate->copy()->setTimezone($tz)->isSameDay($today);
        $i++;
      @endphp
      <div class="del-week-col">
        <div class="del-week-head {{ $isThatToday ? 'is-today' : '' }}">
          <div class="dow">{{ $dayDate->format('D') }}</div>
          <div class="date">{{ $dayDate->format('j') }}</div>
          <div class="count">{{ $dayDeliveries->count() }} {{ \Illuminate\Support\Str::plural('stop', $dayDeliveries->count()) }}</div>
        </div>
        <div class="del-week-body">
          @forelse($dayDeliveries as $d)
            <a class="del-week-card is-{{ $d->type }} {{ $d->status === 'completed' ? 'is-completed' : '' }}"
               href="#" onclick="delOpenEdit({{ json_encode((string) $d->id) }}); return false;">
              <span class="time">
                {{ $d->scheduled_at->copy()->setTimezone($tz)->format('g:i A') }}
                @if($is_timeslot && $d->deliveryResource)
                  · {{ $d->deliveryResource->name }}
                @endif
              </span>
              <span class="name">{{ trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: 'Customer' }}</span>
              @if($d->address)
                <span class="addr">{{ $d->address }}</span>
              @endif
            </a>
          @empty
            <div style="padding:14px 6px; font-size:11px; text-align:center; color: var(--ia-text-dim, rgba(255,255,255,.42));">
              &mdash;
            </div>
          @endforelse
        </div>
      </div>
    @endforeach
  </div>
@endif

{{-- ===========================================================
     DRAWER (create + edit)
     =========================================================== --}}
<div class="del-drawer-bg" id="del-drawer-bg" onclick="delCloseDrawer()"></div>
<div class="del-drawer" id="del-drawer">
  <form method="POST" id="del-form" action="{{ route('tenant.deliveries.store') }}">
    @csrf
    <input type="hidden" name="_method" value="POST" id="del-form-method">
    <input type="hidden" name="type" value="pickup" id="del-form-type">

    <div class="del-drawer-head">
      <div>
        <div class="del-drawer-title" id="del-drawer-title">New pickup</div>
        <div class="del-drawer-sub" id="del-drawer-sub">Schedule a private pickup or dropoff.</div>
      </div>
      <button type="button" class="del-drawer-close" onclick="delCloseDrawer()">✕</button>
    </div>

    <div class="del-drawer-body">

      {{-- Type --}}
      <div class="del-row">
        <label class="del-label">Type</label>
        <div class="del-type-toggle">
          <div class="del-type-tile is-pickup is-selected" id="del-tile-pickup" onclick="delSelectType('pickup')">
            <div class="icon" style="color: var(--del-pickup);">→</div>
            <div class="label">Pickup</div>
            <div class="sub">Bike to shop</div>
          </div>
          <div class="del-type-tile is-dropoff" id="del-tile-dropoff" onclick="delSelectType('dropoff')">
            <div class="icon" style="color: var(--del-dropoff);">←</div>
            <div class="label">Dropoff</div>
            <div class="sub">Shop to customer</div>
          </div>
        </div>
      </div>

      {{-- Customer — MARKER-PATCH-153 — using shared customer-search component --}}
      <div class="del-row">
        <label class="del-label">Customer</label>
        <x-tenant.customer-search name="customer_id" required />
        @error('customer_id')<div class="del-error">{{ $message }}</div>@enderror
      </div>

      {{-- Date + start time --}}
      <div class="del-row split">
        <div>
          <label class="del-label">Date</label>
          <input type="date" name="date_part" class="del-input" id="del-date" required>
        </div>
        <div>
          <label class="del-label">Start time</label>
          <input type="time" name="time_part" class="del-input" id="del-time" required>
        </div>
      </div>

      {{-- Hidden combined scheduled_at, set by JS on submit --}}
      <input type="hidden" name="scheduled_at" id="del-scheduled-at">

      {{-- Window --}}
      <div class="del-row">
        <label class="del-label">Window</label>
        <select name="window_minutes" class="del-select" id="del-window">
          <option value="15">15 min window</option>
          <option value="30" selected>30 min window</option>
          <option value="60">60 min window</option>
          <option value="120">2 hour window</option>
        </select>
      </div>

      {{-- Address — MARKER-PATCH-153 — manual entry, no autofill --}}
      <div class="del-row">
        <label class="del-label">Address</label>
        <input type="text" name="address" class="del-input" id="del-address" placeholder="123 Main St, Spokane, WA 99201">
      </div>

      {{-- Delivery resource (time-slot only) --}}
      @if($is_timeslot && $resources->isNotEmpty())
        <div class="del-row">
          <label class="del-label">Delivery resource</label>
          <select name="delivery_resource_id" class="del-select" id="del-resource">
            <option value="">Unassigned</option>
            @foreach($resources as $res)
              <option value="{{ $res->id }}">{{ $res->name }}@if($res->subtitle) — {{ $res->subtitle }}@endif</option>
            @endforeach
          </select>
          @error('delivery_resource_id')<div class="del-error">{{ $message }}</div>@enderror
        </div>
      @endif

      {{-- Notes --}}
      <div class="del-row">
        <label class="del-label">Notes</label>
        <textarea name="notes" class="del-textarea" id="del-notes" placeholder="Gate code, dog warning, where to leave the bike…"></textarea>
      </div>

      {{-- Notify banner — MARKER-PATCH-152C / MARKER-PATCH-157 --}}
      @php
        $channelLabels = [];
        if ($notifyEmail) $channelLabels[] = 'email';
        if ($notifySms)   $channelLabels[] = 'SMS';
      @endphp
      @if(count($channelLabels) > 0)
        <div class="del-notify">
          <div>
            <strong>Notify by {{ implode(' + ', $channelLabels) }}?</strong>
            <div style="color: var(--ia-text-2, rgba(255,255,255,.78)); margin-top: 2px;">
              Click <em>Save</em> to schedule silently, or <em>Save &amp; notify</em> to send the customer details now.
              <a href="{{ route('tenant.settings.index') }}#notifications" style="color: var(--ia-accent, #BEF264);">Change channels in settings</a>.
            </div>
          </div>
        </div>
      @else
        <div class="del-notify" style="background: rgba(248,113,113,.04); border-color: rgba(248,113,113,.15);">
          <div>
            <strong style="color: #F87171;">Notifications are off</strong>
            <div style="color: var(--ia-text-2, rgba(255,255,255,.78)); margin-top: 2px;">
              <em>Save &amp; notify</em> won't send anything because both channels are off.
              <a href="{{ route('tenant.settings.index') }}#notifications" style="color: var(--ia-accent, #BEF264);">Enable in settings</a>.
            </div>
          </div>
        </div>
      @endif

    </div>

    {{-- MARKER-PATCH-157-FIX1 — two-row footer for cleaner spacing --}}
    <div class="del-drawer-foot">
      <div class="del-drawer-foot-row">
        <div id="del-foot-left">
          <button type="button" class="del-btn del-btn--danger" id="del-cancel-btn" style="display:none;" onclick="delCancel()">Cancel</button>
        </div>
        <div class="del-drawer-foot-right">
          {{-- MARKER-PATCH-329 --}}
          <button type="button" class="del-btn del-btn--ghost" id="del-print-btn" style="display:none;" onclick="delPrintSlip()">&#9113; Print</button>
          <button type="button" class="del-btn del-btn--ghost" onclick="delCloseDrawer()">Close</button>
          {{-- MARKER-PATCH-157 — hidden notify flag, set by the two save buttons --}}
          <input type="hidden" name="notify" id="del-notify-flag" value="0">
          <button type="submit" class="del-btn del-btn--ghost"   id="del-save-btn"        onclick="return delPrepSubmit(false)">Save</button>
          <button type="submit" class="del-btn del-btn--primary" id="del-save-notify-btn" onclick="return delPrepSubmit(true)">Save &amp; notify</button>
        </div>
      </div>
      <button type="button" class="del-btn del-btn--ghost del-btn--full" id="del-complete-btn" style="display:none;" onclick="delComplete()">Complete</button>
    </div>

  </form>
</div>

{{-- Deliveries seed for client-side edit lookups --}}
@php
  $deliveriesForJs = [];
  $iterDeliveries = $view === 'day' ? $deliveries : collect($days)->flatten();
  foreach ($iterDeliveries as $d) {
    $deliveriesForJs[(string) $d->id] = [
      'id'                   => (string) $d->id,
      'type'                 => $d->type,
      'status'               => $d->status,
      'scheduled_at_iso'     => $d->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
      'window_minutes'       => $d->window_minutes,
      'address'              => $d->address,
      'customer_id'          => $d->customer_id,
      // MARKER-PATCH-153 — needed by drawer edit-mode preselect
      'customer_name'        => trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? '')) ?: ($d->customer->email ?? 'Customer'),
      'delivery_resource_id' => $d->delivery_resource_id,
      'notes'                => $d->notes,
    ];
  }
@endphp

<script>
  window.delDeliveries = @json($deliveriesForJs);
  window.delEditing = null;
  // MARKER-PATCH-152B-FIX1 — base URLs only; client appends /{id}/[action]
  window.delRoutes = {
    store:    @json(route('tenant.deliveries.store')),
    base:     @json(route('tenant.deliveries.index')),
  };

  function delOpenCreate(type) {
    { var _pb = document.getElementById('del-print-btn'); if (_pb) _pb.style.display = 'none'; } // MARKER-PATCH-329
    window.delEditing = null;
    document.getElementById('del-drawer-bg').classList.add('is-open');
    document.getElementById('del-drawer').classList.add('is-open');
    document.getElementById('del-drawer-title').textContent = 'New ' + type;
    document.getElementById('del-drawer-sub').textContent = 'Schedule a private pickup or dropoff.';
    document.getElementById('del-form').action = window.delRoutes.store;
    document.getElementById('del-form-method').value = 'POST';
    delSelectType(type);
    // Default date = today, time = next round hour
    var now = new Date();
    // MARKER-PATCH-152B-FIX2 — use LOCAL date components, not UTC
    var y = now.getFullYear();
    var m = String(now.getMonth() + 1).padStart(2, '0');
    var dd = String(now.getDate()).padStart(2, '0');
    document.getElementById('del-date').value = y + '-' + m + '-' + dd;
    var hh = String(now.getHours() + 1).padStart(2, '0');
    document.getElementById('del-time').value = hh + ':00';
    document.getElementById('del-window').value = '30';
    // MARKER-PATCH-153 — customer-search component reset
    delResetCustomer();
    document.getElementById('del-address').value = '';
    var rEl = document.getElementById('del-resource');
    if (rEl) rEl.value = '';
    document.getElementById('del-notes').value = '';
    document.getElementById('del-complete-btn').style.display = 'none';
    document.getElementById('del-cancel-btn').style.display = 'none';
    // MARKER-PATCH-157 — set both button labels for create mode
    // MARKER-PATCH-157-FIX1 — shorter labels
    document.getElementById('del-save-btn').textContent = 'Save';
    document.getElementById('del-save-notify-btn').textContent = 'Save & notify';
  }

  // MARKER-PATCH-329 — print this delivery's receipt via a hidden iframe.
  window.delPrintSlip = function () {
    var id = window.delEditing;
    if (!id) return;
    var url = window.location.origin + '/admin/deliveries/' + id + '/slip?embed=1';
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    f.src = url;
    f.onload = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (e) { window.open(url.replace('?embed=1',''), '_blank'); }
      setTimeout(function () { f.remove(); }, 2000);
    };
    document.body.appendChild(f);
  };

  function delOpenEdit(id) {
    var d = window.delDeliveries[id];
    if (!d) return;
    window.delEditing = id;
    { var _pb = document.getElementById('del-print-btn'); if (_pb) _pb.style.display = ''; } // MARKER-PATCH-329
    document.getElementById('del-drawer-bg').classList.add('is-open');
    document.getElementById('del-drawer').classList.add('is-open');
    document.getElementById('del-drawer-title').textContent = 'Edit ' + d.type;
    document.getElementById('del-drawer-sub').textContent = d.status === 'completed' ? 'Completed ' + (d.completed_at || '') : 'Scheduled';
    document.getElementById('del-form').action = window.delRoutes.base + '/' + id;
    document.getElementById('del-form-method').value = 'PATCH';
    delSelectType(d.type);
    var iso = d.scheduled_at_iso || '';
    var parts = iso.split('T');
    document.getElementById('del-date').value = parts[0] || '';
    document.getElementById('del-time').value = parts[1] || '';
    document.getElementById('del-window').value = String(d.window_minutes || 30);
    // MARKER-PATCH-153 — populate search box with customer name
    delSetCustomer(d.customer_id, d.customer_name || '');
    document.getElementById('del-address').value = d.address || '';
    var rEl = document.getElementById('del-resource');
    if (rEl) rEl.value = d.delivery_resource_id || '';
    document.getElementById('del-notes').value = d.notes || '';
    document.getElementById('del-complete-btn').style.display = (d.status === 'scheduled') ? '' : 'none';
    document.getElementById('del-cancel-btn').style.display = (d.status === 'scheduled') ? '' : 'none';
    // MARKER-PATCH-157 — set both button labels for edit mode
    // MARKER-PATCH-157-FIX1 — shorter labels
    document.getElementById('del-save-btn').textContent = 'Update';
    document.getElementById('del-save-notify-btn').textContent = 'Update & notify';
  }

  function delCloseDrawer() {
    document.getElementById('del-drawer-bg').classList.remove('is-open');
    document.getElementById('del-drawer').classList.remove('is-open');
  }
  function delSelectType(t) {
    document.getElementById('del-tile-pickup').classList.toggle('is-selected', t === 'pickup');
    document.getElementById('del-tile-dropoff').classList.toggle('is-selected', t === 'dropoff');
    document.getElementById('del-form-type').value = t;
  }
  // MARKER-PATCH-153 — customer-search component helpers
  // Reset the search box on create.
  function delResetCustomer() {
    var root = document.querySelector('.del-drawer [data-customer-search]');
    if (!root) return;
    var idField = root.querySelector('[data-cs-id]');
    var inField = root.querySelector('[data-cs-input]');
    var clear   = root.querySelector('[data-cs-clear]');
    if (idField) idField.value = '';
    if (inField) inField.value = '';
    if (clear)   clear.hidden = true;
  }
  // Programmatically set the customer when editing.
  function delSetCustomer(id, name) {
    var root = document.querySelector('.del-drawer [data-customer-search]');
    if (!root) return;
    var idField = root.querySelector('[data-cs-id]');
    var inField = root.querySelector('[data-cs-input]');
    var clear   = root.querySelector('[data-cs-clear]');
    if (idField) idField.value = id || '';
    if (inField) inField.value = name || '';
    if (clear)   clear.hidden = !id;
  }
  // MARKER-PATCH-157 — accepts notify flag from the clicked button
  function delPrepSubmit(notify) {
    var d = document.getElementById('del-date').value;
    var t = document.getElementById('del-time').value;
    if (!d || !t) {
      alert('Date and time are required.');
      return false;
    }
    document.getElementById('del-scheduled-at').value = d + ' ' + t + ':00';
    document.getElementById('del-notify-flag').value = notify ? '1' : '0';
    return true;
  }
  function delComplete() {
    if (!window.delEditing) return;
    if (!confirm('Mark this delivery complete?')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = window.delRoutes.base + '/' + window.delEditing + '/complete';
    f.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="PATCH">';
    document.body.appendChild(f);
    f.submit();
  }
  function delCancel() {
    if (!window.delEditing) return;
    if (!confirm('Cancel this delivery? The customer will NOT be auto-notified of the cancellation.')) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = window.delRoutes.base + '/' + window.delEditing + '/cancel';
    f.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="PATCH">';
    document.body.appendChild(f);
    f.submit();
  }

  // Auto-open drawer on validation error
  @if($errors->any())
    document.addEventListener('DOMContentLoaded', function () {
      document.getElementById('del-drawer-bg').classList.add('is-open');
      document.getElementById('del-drawer').classList.add('is-open');
    });
  @endif
</script>
@endsection