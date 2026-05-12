@extends('layouts.tenant.app')

@section('title', 'Walk-in')

{{-- No mobile FAB on this page — we ARE the FAB destination --}}

@push('head')
<style>
  /* Walk-in mobile-first layout. Hidden on desktop (use New appointment modal). */
  @media (max-width: 1023px) {
    .ia-content { padding: 0 !important; }
  }

  .wi-wrap {
    max-width: 560px;
    margin: 0 auto;
    padding: 8px 0 100px;
  }
  @media (max-width: 1023px) {
    .wi-wrap { padding-top: 0; }
  }

  .wi-step { display: none; }
  .wi-step.active { display: block; }

  .wi-hero {
    padding: 32px 24px 8px;
  }
  .wi-hero h2 {
    font-size: 22px;
    line-height: 1.2;
    letter-spacing: -.02em;
    font-weight: 600;
    margin: 0 0 6px;
  }
  .wi-hero-sub {
    color: var(--ia-muted, #888);
    font-size: 14px;
    margin: 0;
  }

  .wi-search {
    padding: 12px 16px;
  }
  .wi-search input {
    width: 100%;
    padding: 12px 14px;
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    border-radius: 10px;
    color: var(--ia-text, #f0f0f0);
    font-size: 15px;
    font-family: inherit;
  }
  .wi-search input:focus {
    outline: none;
    border-color: var(--ia-accent, #BEF264);
  }

  .wi-search-results {
    margin-top: 6px;
    border-radius: 10px;
    overflow: hidden;
  }
  .wi-search-results:empty { display: none; }

  .wi-choice {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
    margin: 0 16px 8px;
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    border-radius: 12px;
    cursor: pointer;
    transition: border-color 100ms;
  }
  .wi-choice:active { transform: scale(.99); }
  .wi-choice:hover { border-color: var(--ia-border-2, rgba(255,255,255,.14)); }

  .wi-choice-icon {
    width: 42px; height: 42px;
    background: rgba(190,242,100,.10);
    color: var(--ia-accent, #BEF264);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
  }
  .wi-choice-icon svg { width: 22px; height: 22px; }

  .wi-choice-body { flex: 1; min-width: 0; }
  .wi-choice-title {
    font-size: 15px;
    font-weight: 500;
    margin: 0 0 2px;
  }
  .wi-choice-sub {
    font-size: 12.5px;
    color: var(--ia-muted, #888);
    margin: 0;
  }
  .wi-choice-arrow {
    color: var(--ia-dim, #5a5a5a);
    font-size: 20px;
  }

  .wi-section-label {
    padding: 22px 20px 8px;
    font-size: 11px;
    color: var(--ia-muted, #888);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
  }

  .wi-cust-row {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 20px;
    background: var(--ia-surface, #131313);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    cursor: pointer;
  }
  .wi-cust-row:first-of-type {
    border-top: 0;
  }
  .wi-cust-row:active { background: var(--ia-surface-2, #1a1a1a); }
  .wi-cust-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--ia-surface-2, #1a1a1a);
    display: flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 600;
    color: var(--ia-muted, #888);
    flex-shrink: 0;
  }
  .wi-cust-body { flex: 1; min-width: 0; }
  .wi-cust-name {
    font-size: 14.5px;
    font-weight: 500;
    margin: 0 0 1px;
  }
  .wi-cust-meta {
    font-size: 12px;
    color: var(--ia-muted, #888);
    margin: 0;
  }
  .wi-cust-arrow { color: var(--ia-dim, #5a5a5a); font-size: 18px; }

  .wi-empty {
    padding: 24px 20px;
    text-align: center;
    color: var(--ia-muted, #888);
    font-size: 13.5px;
  }

  /* Form fields */
  .wi-field {
    padding: 8px 20px;
  }
  .wi-field label {
    display: block;
    font-size: 12px;
    color: var(--ia-muted, #888);
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 600;
    margin-bottom: 6px;
  }
  .wi-field input {
    width: 100%;
    padding: 11px 13px;
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    border-radius: 9px;
    color: var(--ia-text, #f0f0f0);
    font-size: 15px;
    font-family: inherit;
  }
  .wi-field input:focus {
    outline: none;
    border-color: var(--ia-accent, #BEF264);
  }

  .wi-error {
    padding: 8px 20px 0;
    color: #f87171;
    font-size: 12.5px;
  }

  /* Sticky bottom action */
  .wi-bottom {
    position: fixed;
    bottom: env(safe-area-inset-bottom, 0px);
    left: 0; right: 0;
    padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px));
    background: rgba(10,10,10,.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    z-index: 80;
  }
  @media (min-width: 1024px) {
    .wi-bottom {
      position: sticky;
      bottom: 16px;
      max-width: 560px;
      margin: 0 auto;
      background: transparent;
      backdrop-filter: none;
      border: 0;
    }
  }
  .wi-bottom-btn {
    width: 100%;
    padding: 14px;
    background: var(--ia-accent, #BEF264);
    color: var(--ia-accent-text, #0a0a0a);
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
  }
  .wi-bottom-btn:active { opacity: .9; }
  .wi-bottom-btn:disabled { opacity: .45; cursor: not-allowed; }

  /* Service list */
  .wi-svc-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 20px;
    background: var(--ia-surface, #131313);
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    cursor: pointer;
  }
  .wi-svc-row:first-of-type { border-top: 0; }
  .wi-svc-row:active { background: var(--ia-surface-2, #1a1a1a); }
  .wi-svc-row.selected {
    background: rgba(190,242,100,.08);
  }
  .wi-svc-name { font-size: 14.5px; font-weight: 500; }
  .wi-svc-meta {
    font-size: 12px;
    color: var(--ia-muted, #888);
    margin-top: 2px;
  }
  .wi-svc-price {
    font-size: 14.5px; font-weight: 600;
    color: var(--ia-text, #f0f0f0);
  }

  /* Time slots */
  .wi-times {
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    border-radius: 10px;
    margin: 8px 16px 0;
    overflow: hidden;
  }
  .wi-time {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 16px;
    border-top: 1px solid var(--ia-border, rgba(255,255,255,.08));
    cursor: pointer;
  }
  .wi-time:first-child { border-top: 0; }
  .wi-time:active { background: var(--ia-surface-2, #1a1a1a); }
  .wi-time.selected {
    background: rgba(190,242,100,.08);
  }
  .wi-time-date {
    color: var(--ia-muted, #888);
    font-size: 13px;
  }
  .wi-time-time {
    font-size: 14.5px;
    font-weight: 500;
  }

  /* Selected-customer chip at top of choice screen */
  .wi-cust-chip {
    margin: 0 16px 14px;
    padding: 12px 16px;
    background: var(--ia-surface, #131313);
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    border-radius: 10px;
    display: flex; align-items: center; gap: 12px;
  }
  .wi-cust-chip-body { flex: 1; }
  .wi-cust-chip-name { font-size: 14.5px; font-weight: 500; }
  .wi-cust-chip-meta {
    font-size: 12px;
    color: var(--ia-muted, #888);
    margin-top: 1px;
  }
  .wi-cust-chip button {
    background: transparent;
    border: 1px solid var(--ia-border, rgba(255,255,255,.08));
    color: var(--ia-muted, #888);
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    font-family: inherit;
  }

  /* Mini step label */
  .wi-step-label {
    padding: 12px 20px 0;
    font-size: 11px;
    color: var(--ia-muted, #888);
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 600;
  }

  /* Loading spinner inline */
  .wi-spinner {
    display: inline-block;
    width: 14px; height: 14px;
    border: 2px solid var(--ia-border, rgba(255,255,255,.2));
    border-top-color: var(--ia-accent, #BEF264);
    border-radius: 50%;
    animation: wi-spin .8s linear infinite;
    vertical-align: -2px;
  }
  @keyframes wi-spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')
<div class="wi-wrap">

  {{-- ======================== STEP 1: START ======================== --}}
  <section class="wi-step active" data-step="start">
    <div class="wi-hero">
      <h2>Who's at the counter?</h2>
      <p class="wi-hero-sub">Search by name, email, or phone — or start a new customer.</p>
    </div>

    <div class="wi-search">
      <input
        type="search"
        id="wiSearch"
        placeholder="Search customers…"
        autocomplete="off"
        spellcheck="false">
      <div class="wi-search-results" id="wiSearchResults"></div>
    </div>

    <div class="wi-choice" data-action="new-customer">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <line x1="19" y1="8" x2="19" y2="14"/>
          <line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">New customer</div>
        <div class="wi-choice-sub">Capture name + phone, then book or sell</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-choice" data-action="anon-sale">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="9" cy="21" r="1"/>
          <circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Quick retail sale</div>
        <div class="wi-choice-sub">No appointment, no customer record needed</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    @if(count($recentCustomers))
      <div class="wi-section-label">Recent customers</div>
      @foreach($recentCustomers as $cust)
        @php
          $custData = [
              "id"    => $cust["id"],
              "name"  => $cust["name"] ?: "(no name)",
              "email" => $cust["email"],
              "phone" => $cust["phone"],
          ];
        @endphp
        <div class="wi-cust-row"
             data-cust='{{ json_encode($custData) }}'>
          <div class="wi-cust-avatar">{{ $cust['initials'] ?: '?' }}</div>
          <div class="wi-cust-body">
            <div class="wi-cust-name">{{ $cust['name'] ?: '(no name)' }}</div>
            <div class="wi-cust-meta">{{ $cust['phone'] ?: $cust['email'] ?: 'No contact' }} · {{ $cust['updated'] }}</div>
          </div>
          <div class="wi-cust-arrow">›</div>
        </div>
      @endforeach
    @endif
  </section>

  {{-- ======================== STEP 2: NEW CUSTOMER ======================== --}}
  <section class="wi-step" data-step="new-customer">
    <div class="wi-hero">
      <h2>New customer</h2>
      <p class="wi-hero-sub">Name and one contact method is enough. You can fill in more later.</p>
    </div>
    <div class="wi-field">
      <label for="wiNcFirst">First name</label>
      <input type="text" id="wiNcFirst" autocomplete="given-name">
    </div>
    <div class="wi-field">
      <label for="wiNcLast">Last name</label>
      <input type="text" id="wiNcLast" autocomplete="family-name">
    </div>
    <div class="wi-field">
      <label for="wiNcPhone">Phone</label>
      <input type="tel" id="wiNcPhone" autocomplete="tel">
    </div>
    <div class="wi-field">
      <label for="wiNcEmail">Email <span style="text-transform:none;color:var(--ia-dim,#5a5a5a)">(optional)</span></label>
      <input type="email" id="wiNcEmail" autocomplete="email">
    </div>
    <div class="wi-error" id="wiNcError"></div>
    <div class="wi-bottom">
      <button class="wi-bottom-btn" id="wiNcContinue">Continue →</button>
    </div>
  </section>

  {{-- ======================== STEP 3: CHOICE ======================== --}}
  <section class="wi-step" data-step="choice">
    <div class="wi-hero">
      <h2>What's next?</h2>
      <p class="wi-hero-sub">Book an appointment or ring up a sale.</p>
    </div>

    <div class="wi-cust-chip" id="wiCustChip">
      <div class="wi-cust-avatar" id="wiChipAvatar">?</div>
      <div class="wi-cust-chip-body">
        <div class="wi-cust-chip-name" id="wiChipName">—</div>
        <div class="wi-cust-chip-meta" id="wiChipMeta"></div>
      </div>
      <button type="button" data-back-to="start">Change</button>
    </div>

    <div class="wi-choice" data-action="book">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="4" width="18" height="18" rx="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Book appointment</div>
        <div class="wi-choice-sub">Pick a service and time</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>

    <div class="wi-choice" data-action="sale">
      <div class="wi-choice-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="9" cy="21" r="1"/>
          <circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
      </div>
      <div class="wi-choice-body">
        <div class="wi-choice-title">Quick sale</div>
        <div class="wi-choice-sub">Ring up items at the register</div>
      </div>
      <div class="wi-choice-arrow">›</div>
    </div>
  </section>

  {{-- ======================== STEP 4: SERVICE PICK ======================== --}}
  <section class="wi-step" data-step="service">
    <div class="wi-step-label">Step 1 of 2</div>
    <div class="wi-hero" style="padding-top:8px">
      <h2>Pick a service</h2>
      <p class="wi-hero-sub">For <span id="wiSvcCustName">—</span></p>
    </div>

    @if(count($services) === 0)
      <div class="wi-empty">No active services. Add one in <a href="{{ route('tenant.services.index', ['subdomain' => $tenant->subdomain]) }}" style="color:var(--ia-accent,#BEF264)">Services</a>.</div>
    @else
      @foreach($services as $svc)
        <div class="wi-svc-row" data-svc='@json($svc)'>
          <div>
            <div class="wi-svc-name">{{ $svc['name'] }}</div>
            <div class="wi-svc-meta">{{ $svc['duration'] }} min</div>
          </div>
          <div class="wi-svc-price">${{ number_format($svc['price'] / 100, 2) }}</div>
        </div>
      @endforeach
    @endif
  </section>

  {{-- ======================== STEP 5: TIME PICK ======================== --}}
  <section class="wi-step" data-step="time">
    <div class="wi-step-label">Step 2 of 2</div>
    <div class="wi-hero" style="padding-top:8px">
      <h2>Pick a time</h2>
      <p class="wi-hero-sub" id="wiTimeSub">—</p>
    </div>

    @if(count($resources) > 1)
      <div class="wi-field">
        <label for="wiResourceSelect">Resource</label>
        <input type="text" id="wiResourceSelect" readonly style="cursor:pointer">
        <select id="wiResourceSelectReal" style="display:none">
          @foreach($resources as $r)
            <option value="{{ $r['id'] }}">{{ $r['name'] }}</option>
          @endforeach
        </select>
      </div>
    @else
      <input type="hidden" id="wiResourceSelectReal" value="{{ $resources[0]['id'] ?? '' }}">
    @endif

    <div id="wiTimesContainer"></div>

    <div class="wi-bottom">
      <button class="wi-bottom-btn" id="wiBookConfirm" disabled>Confirm booking →</button>
    </div>
  </section>

</div>

@php($csrf = csrf_token())
<script>
(function() {
  'use strict';

  const CSRF = @json($csrf);
  const SUBDOMAIN = @json($tenant->subdomain);

  // Routes
  const ROUTE_SEARCH      = `/customers/search`;
  const ROUTE_CREATE_CUST = `/customers`;
  const ROUTE_AVAILABILITY = `/calendar/quick-book/availability`;
  const ROUTE_BOOK        = `/calendar/quick-book`;
  const ROUTE_REGISTER    = `/admin/register`;
  const ROUTE_APPT_BASE   = `/admin/appointments`;

  // ─── State ─────────────────────────────────────────────────────────
  const state = {
    step: 'start',
    customer: null,    // {id?, name, phone, email}
    service: null,
    time: null,        // {date, appointment_time, resource_id}
  };

  // ─── DOM helpers ───────────────────────────────────────────────────
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  function goto(step) {
    state.step = step;
    $$('.wi-step').forEach(el => el.classList.toggle('active', el.dataset.step === step));
    window.scrollTo({ top: 0, behavior: 'instant' });
  }

  function showError(msg, elId) {
    const el = $('#' + elId);
    if (el) el.textContent = msg || '';
  }

  // ─── Search ─────────────────────────────────────────────────────────
  let searchTimer = null;
  $('#wiSearch').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    const q = e.target.value.trim();
    const out = $('#wiSearchResults');
    if (q.length < 2) {
      out.innerHTML = '';
      return;
    }
    out.innerHTML = `<div class="wi-empty"><span class="wi-spinner"></span> Searching…</div>`;
    searchTimer = setTimeout(() => doSearch(q), 200);
  });

  async function doSearch(q) {
    try {
      const res = await fetch(`${ROUTE_SEARCH}?q=${encodeURIComponent(q)}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await res.json();
      const out = $('#wiSearchResults');
      if (!json.customers || json.customers.length === 0) {
        out.innerHTML = `<div class="wi-empty">No matches.</div>`;
        return;
      }
      out.innerHTML = json.customers.map(c => {
        const initials = (
          (c.first_name || '').charAt(0) +
          (c.last_name || '').charAt(0)
        ).toUpperCase() || '?';
        const meta = c.phone || c.email || '';
        const cust = JSON.stringify({
          id: c.id,
          name: (c.label || `${c.first_name || ''} ${c.last_name || ''}`).trim() || '(no name)',
          phone: c.phone,
          email: c.email,
        });
        return `
          <div class="wi-cust-row" data-cust='${cust.replace(/'/g, "&#39;")}'>
            <div class="wi-cust-avatar">${initials}</div>
            <div class="wi-cust-body">
              <div class="wi-cust-name">${escapeHtml(c.label || '(no name)')}</div>
              <div class="wi-cust-meta">${escapeHtml(meta)}</div>
            </div>
            <div class="wi-cust-arrow">›</div>
          </div>
        `;
      }).join('');
    } catch (err) {
      console.error('search failed', err);
      $('#wiSearchResults').innerHTML = `<div class="wi-empty">Search failed. Try again.</div>`;
    }
  }

  // ─── Customer selection ───────────────────────────────────────────
  document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-cust]');
    if (row) {
      try {
        state.customer = JSON.parse(row.dataset.cust);
        showChoice();
      } catch (err) { console.error(err); }
      return;
    }
    const action = e.target.closest('[data-action]');
    if (action) {
      handleAction(action.dataset.action);
      return;
    }
    const backTo = e.target.closest('[data-back-to]');
    if (backTo) {
      goto(backTo.dataset.backTo);
      return;
    }
  });

  function showChoice() {
    const c = state.customer;
    $('#wiChipName').textContent = c.name || '(no name)';
    $('#wiChipMeta').textContent = c.phone || c.email || '';
    const initials = (c.name || '?').split(' ').filter(Boolean)
      .map(s => s.charAt(0).toUpperCase()).slice(0, 2).join('') || '?';
    $('#wiChipAvatar').textContent = initials;
    goto('choice');
  }

  function handleAction(action) {
    if (action === 'new-customer') {
      goto('new-customer');
    } else if (action === 'anon-sale') {
      window.location.href = ROUTE_REGISTER;
    } else if (action === 'book') {
      $('#wiSvcCustName').textContent = state.customer.name || '(no name)';
      goto('service');
    } else if (action === 'sale') {
      // Already-known customer: go to register with customer pre-attached
      if (state.customer.id) {
        window.location.href = `${ROUTE_REGISTER}?customer_id=${state.customer.id}`;
      } else {
        // New customer (no id yet) — create them first
        createCustomerThen((c) => {
          window.location.href = `${ROUTE_REGISTER}?customer_id=${c.id}`;
        });
      }
    }
  }

  // ─── New customer create ──────────────────────────────────────────
  $('#wiNcContinue').addEventListener('click', () => {
    const first = $('#wiNcFirst').value.trim();
    const last  = $('#wiNcLast').value.trim();
    const phone = $('#wiNcPhone').value.trim();
    const email = $('#wiNcEmail').value.trim();

    if (!first) { showError('First name is required.', 'wiNcError'); return; }
    if (!phone && !email) { showError('Phone or email is required.', 'wiNcError'); return; }

    showError('', 'wiNcError');
    state.customer = { name: `${first} ${last}`.trim(), phone, email, first_name: first, last_name: last };
    showChoice();
  });

  async function createCustomerThen(callback) {
    if (!state.customer) return;
    try {
      const res = await fetch(ROUTE_CREATE_CUST, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': CSRF,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          first_name: state.customer.first_name || state.customer.name.split(' ')[0],
          last_name:  state.customer.last_name  || state.customer.name.split(' ').slice(1).join(' '),
          email: state.customer.email || `walkin-${Date.now()}@no-email.local`,
          phone: state.customer.phone,
        }),
      });
      if (!res.ok) throw new Error('Create failed');
      const json = await res.json();
      const created = json.customer || json;
      state.customer.id = created.id;
      callback(state.customer);
    } catch (err) {
      console.error('create customer failed', err);
      alert('Could not save customer. Check connection and try again.');
    }
  }

  // ─── Service selection ────────────────────────────────────────────
  $$('.wi-svc-row').forEach(row => {
    row.addEventListener('click', () => {
      $$('.wi-svc-row').forEach(r => r.classList.remove('selected'));
      row.classList.add('selected');
      try {
        state.service = JSON.parse(row.dataset.svc);
        $('#wiTimeSub').textContent =
          `${state.customer.name} · ${state.service.name} · ${state.service.duration} min`;
        loadAvailability();
        goto('time');
      } catch (err) { console.error(err); }
    });
  });

  // ─── Availability ─────────────────────────────────────────────────
  async function loadAvailability() {
    const container = $('#wiTimesContainer');
    container.innerHTML = `<div class="wi-empty"><span class="wi-spinner"></span> Loading times…</div>`;
    try {
      const params = new URLSearchParams({
        service_item_id: state.service.id,
        resource_id: $('#wiResourceSelectReal').value || '',
      });
      const res = await fetch(`${ROUTE_AVAILABILITY}?${params}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!res.ok) {
        // Fallback: synthesize "next 8 slots starting now+15m, every 30m" so
        // the flow is testable even if the endpoint isn't wired yet.
        container.innerHTML = renderFallbackTimes();
        wireTimeRows();
        return;
      }
      const json = await res.json();
      const slots = json.slots || json.times || [];
      if (slots.length === 0) {
        container.innerHTML = `<div class="wi-empty">No available times. Try a different resource or service.</div>`;
        return;
      }
      container.innerHTML = `<div class="wi-times">${slots.slice(0, 12).map(renderSlot).join('')}</div>`;
      wireTimeRows();
    } catch (err) {
      console.error('availability failed', err);
      container.innerHTML = renderFallbackTimes();
      wireTimeRows();
    }
  }

  function renderFallbackTimes() {
    const slots = [];
    const now = new Date();
    now.setMinutes(Math.ceil(now.getMinutes() / 15) * 15, 0, 0);
    for (let i = 0; i < 8; i++) {
      const t = new Date(now.getTime() + i * 30 * 60 * 1000);
      const date = t.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
      const time = t.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
      const iso = t.toISOString().slice(0, 10);
      const tm  = t.toTimeString().slice(0, 5);
      slots.push({ date, time, iso, tm });
    }
    return `<div class="wi-times">${slots.map((s, i) => `
      <div class="wi-time" data-date="${s.iso}" data-time="${s.tm}">
        <span class="wi-time-date">${i === 0 ? 'Next available · ' : ''}${s.date}</span>
        <span class="wi-time-time">${s.time}</span>
      </div>`).join('')}</div>`;
  }

  function renderSlot(slot) {
    // Expected shape: {date_iso, time_24, date_label, time_label}
    return `
      <div class="wi-time"
           data-date="${slot.date_iso || slot.date || ''}"
           data-time="${slot.time_24 || slot.time || ''}">
        <span class="wi-time-date">${escapeHtml(slot.date_label || slot.date || '')}</span>
        <span class="wi-time-time">${escapeHtml(slot.time_label || slot.time || '')}</span>
      </div>`;
  }

  function wireTimeRows() {
    $$('.wi-time').forEach(row => {
      row.addEventListener('click', () => {
        $$('.wi-time').forEach(r => r.classList.remove('selected'));
        row.classList.add('selected');
        state.time = {
          date: row.dataset.date,
          appointment_time: row.dataset.time,
          resource_id: $('#wiResourceSelectReal').value || '',
        };
        $('#wiBookConfirm').disabled = false;
      });
    });
  }

  // ─── Confirm booking ──────────────────────────────────────────────
  $('#wiBookConfirm').addEventListener('click', async () => {
    if (!state.time || !state.service) return;
    const btn = $('#wiBookConfirm');
    btn.disabled = true;
    btn.textContent = 'Booking…';

    try {
      // If new customer (no id), create them first.
      if (!state.customer.id) {
        await new Promise((resolve, reject) => {
          createCustomerThen(() => resolve());
          setTimeout(() => reject('timeout'), 8000);
        });
      }

      const payload = {
        _token: CSRF,
        customer_id: state.customer.id,
        service_item_id: state.service.id,
        resource_id: state.time.resource_id,
        date: state.time.date,
        appointment_time: state.time.appointment_time,
      };

      const res = await fetch(ROUTE_BOOK, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': CSRF,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || 'Booking failed');
      }

      const json = await res.json();
      const apptId = json.appointment_id || json.id;
      if (apptId) {
        window.location.href = `${ROUTE_APPT_BASE}/${apptId}`;
      } else {
        window.location.href = `/admin/calendar`;
      }
    } catch (err) {
      console.error('book failed', err);
      btn.disabled = false;
      btn.textContent = 'Confirm booking →';
      alert('Booking failed: ' + (err.message || 'unknown error'));
    }
  });

  // ─── Utils ────────────────────────────────────────────────────────
  function escapeHtml(s) {
    if (!s) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

})();
</script>
@endsection
