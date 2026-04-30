@extends('layouts.tenant.app')
@php
  $pageTitle  = 'Settings';
  $activeTab  = request('tab', 'general');
  $s          = $currentTenant->settings ?? [];
  $currencies = ['USD'=>'$','CAD'=>'CA$','GBP'=>'£','EUR'=>'€','AUD'=>'A$','NZD'=>'NZ$'];
@endphp

@push('styles')
<style>
.set-tabs{display:flex;gap:0;border-bottom:0.5px solid var(--ia-border);margin-bottom:28px}
.set-tab{padding:10px 20px;font-size:13px;color:var(--ia-text-muted);cursor:pointer;border-bottom:2px solid transparent;text-decoration:none;transition:all .12s}
.set-tab:hover{color:var(--ia-text)}
.set-tab.active{color:var(--ia-text);border-bottom-color:var(--ia-accent)}
.set-section{max-width:640px}
.provider-card{border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:20px;margin-bottom:16px;transition:border-color .12s}
.provider-card.enabled{border-color:var(--ia-accent)}
.provider-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:0}
.provider-toggle{display:none}
.provider-fields{margin-top:16px;padding-top:16px;border-top:0.5px solid var(--ia-border);display:none}
.provider-card.enabled .provider-fields{display:block}
.prov-toggle-btn{width:38px;height:22px;background:var(--ia-border);border-radius:11px;position:relative;cursor:pointer;border:none;outline:none;transition:background .12s;flex-shrink:0}
.prov-toggle-btn.on{background:var(--ia-accent)}
.prov-toggle-btn::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:white;transition:transform .12s}
.prov-toggle-btn.on::after{transform:translateX(16px)}
.domain-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:500;margin-left:8px}
.domain-badge.basic{background:var(--ia-surface-2);color:var(--ia-text-muted)}
.domain-badge.branded{background:#EEEDFE;color:#534AB7}
.domain-badge.custom{background:#EAF3DE;color:#3B6D11}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Settings</h1>
    <p class="ia-page-subtitle">Configure your shop's operational preferences.</p>
  </div>
</div>

<div class="set-tabs">
  @foreach(['general'=>'General','booking'=>'Booking','payments'=>'Payments','billing'=>'Billing','domain'=>'Domain'] as $tab => $label)
    <a href="?tab={{ $tab }}" class="set-tab {{ $activeTab === $tab ? 'active' : '' }}">{{ $label }}</a>
  @endforeach
</div>

{{-- ============================================================ General ============================================================ --}}
@if($activeTab === 'general')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="general">

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Currency</span></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">Currency code</label>
        <select name="currency" class="ia-input">
          @foreach($currencies as $code => $sym)
            <option value="{{ $code }}" @selected($currentTenant->currency === $code)>{{ $code }} ({{ $sym }})</option>
          @endforeach
        </select>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Currency symbol</label>
        <input type="text" name="currency_symbol" class="ia-input"
          value="{{ old('currency_symbol', $currentTenant->currency_symbol) }}" maxlength="5">
      </div>
    </div>
  </div>

  <div class="ia-card">
    <div class="ia-card-head"><span class="ia-card-title">Timezone</span></div>
    <div class="ia-form-group">
      <label class="ia-form-label">Your local timezone</label>
      <select name="timezone" class="ia-input">
        @php
          $tzGroups = [
            'United States' => [
              'America/Los_Angeles' => 'Pacific (Los Angeles)',
              'America/Denver'      => 'Mountain (Denver)',
              'America/Phoenix'     => 'Mountain — no DST (Phoenix)',
              'America/Chicago'     => 'Central (Chicago)',
              'America/New_York'    => 'Eastern (New York)',
              'America/Anchorage'   => 'Alaska (Anchorage)',
              'Pacific/Honolulu'    => 'Hawaii (Honolulu)',
            ],
            'Canada' => [
              'America/Vancouver' => 'Pacific (Vancouver)',
              'America/Edmonton'  => 'Mountain (Edmonton)',
              'America/Winnipeg'  => 'Central (Winnipeg)',
              'America/Toronto'   => 'Eastern (Toronto)',
              'America/Halifax'   => 'Atlantic (Halifax)',
            ],
            'Other' => [
              'UTC'              => 'UTC',
              'Europe/London'    => 'London',
              'Europe/Paris'     => 'Paris',
              'Australia/Sydney' => 'Sydney',
            ],
          ];
          $currentTz = old('timezone', $currentTenant->timezone ?? 'America/Los_Angeles');
        @endphp
        @foreach($tzGroups as $groupName => $zones)
          <optgroup label="{{ $groupName }}">
            @foreach($zones as $tz => $label)
              <option value="{{ $tz }}" @selected($currentTz === $tz)>{{ $label }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
      <p style="font-size:12px;opacity:.5;margin-top:6px">
        Determines what counts as "today" on your calendar and dashboard. Stored timestamps are unaffected.
      </p>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save general settings</button>
</form>
@endif

{{-- ============================================================ Booking ============================================================ --}}
@if($activeTab === 'booking')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="booking">

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Booking window</span></div>
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">How far ahead can customers book? (days)</label>
        <input type="number" name="booking_window_days" class="ia-input" min="1" max="365"
          value="{{ old('booking_window_days', $currentTenant->booking_window_days ?? 60) }}">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Minimum notice required (hours)</label>
        <input type="number" name="min_notice_hours" class="ia-input" min="0" max="168"
          value="{{ old('min_notice_hours', $currentTenant->min_notice_hours ?? 24) }}">
        <p style="font-size:11px;opacity:.4;margin-top:4px">0 = same-day bookings allowed</p>
      </div>
    </div>
  </div>

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head"><span class="ia-card-title">Class bookings</span></div>
    <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px">
      <div>
        <div style="font-size:14px;font-weight:500">Enable class bookings</div>
        <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a Classes section to your admin and a customer-facing /classes page.</div>
      </div>
      <button type="button"
        class="ia-toggle {{ $currentTenant->classes_enabled ? 'on' : '' }}"
        id="classes-toggle"
        title="{{ $currentTenant->classes_enabled ? 'Click to disable' : 'Click to enable' }}">
        <span class="ia-toggle-sr">{{ $currentTenant->classes_enabled ? 'Enabled' : 'Disabled' }}</span>
      </button>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary">Save booking settings</button>
</form>

{{-- Drop-off methods (separate block — each row has its own endpoint, not part of the form above) --}}
<div class="ia-card" style="margin-bottom:24px">
  <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
    <span class="ia-card-title">Drop-off methods</span>
    <span style="font-size:11px;opacity:.45">Shown on the booking page so customers tell you how they're getting items to you</span>
  </div>

  <div style="padding:14px 16px">
    <form id="add-method-form" style="display:grid;grid-template-columns:1.2fr 1.6fr auto;gap:10px;align-items:end">
      @csrf
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Walk-in" class="ia-input" style="width:100%">
      </div>
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Description (optional)</label>
        <input type="text" name="description" maxlength="500" placeholder="e.g. Stop by during business hours" class="ia-input" style="width:100%">
      </div>
      <div>
        <button type="submit" class="ia-btn ia-btn--primary">Add</button>
      </div>
    </form>
    <div style="display:flex;gap:18px;margin-top:10px;font-size:12px">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" form="add-method-form" name="ask_for_time" value="1"> Ask for arrival time
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" form="add-method-form" name="ask_for_tracking" value="1"> Ask for shipment tracking number
      </label>
    </div>
  </div>

  @if($receivingMethods->isEmpty())
    <div style="padding:24px;text-align:center;border-top:0.5px solid var(--ia-border)">
      <div style="font-size:13px;opacity:.55">No drop-off methods yet. Add your first one above.</div>
    </div>
  @else
    <div id="method-list" style="border-top:0.5px solid var(--ia-border)">
      @foreach($receivingMethods as $m)
        <div class="method-row" data-method-id="{{ $m->id }}"
             style="display:grid;grid-template-columns:auto 1.2fr 1.6fr auto auto auto;gap:12px;align-items:center;padding:10px 16px;border-bottom:0.5px solid var(--ia-border);{{ $m->is_active ? '' : 'opacity:.45' }}">
          <div class="drag-handle" style="cursor:grab;opacity:.4;font-size:14px;user-select:none">⋮⋮</div>

          <input type="text" data-field="name" value="{{ $m->name }}" maxlength="120" class="ia-input method-edit" style="width:100%">

          <input type="text" data-field="description" value="{{ $m->description }}" maxlength="500" placeholder="—" class="ia-input method-edit" style="width:100%">

          <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a time field on the booking page when this method is selected">
            <input type="checkbox" data-field="ask_for_time" {{ $m->ask_for_time ? 'checked' : '' }} class="method-edit-toggle">
            <span>Time</span>
          </label>

          <label style="display:flex;align-items:center;gap:5px;font-size:11px;cursor:pointer;white-space:nowrap" title="Show a tracking-number field on the booking page when this method is selected">
            <input type="checkbox" data-field="ask_for_tracking" {{ $m->ask_for_tracking ? 'checked' : '' }} class="method-edit-toggle">
            <span>Tracking</span>
          </label>

          <button type="button" class="ia-toggle method-row-toggle {{ $m->is_active ? 'on' : '' }}" data-field="is_active" title="{{ $m->is_active ? 'Click to deactivate' : 'Click to activate' }}">
            <span class="ia-toggle-sr">{{ $m->is_active ? 'Active' : 'Inactive' }}</span>
          </button>
        </div>
      @endforeach
    </div>
  @endif
</div>

@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <script>
    (function () {
      'use strict';
      var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      var list = document.getElementById('method-list');

      // classes_enabled toggle
      var classesToggle = document.getElementById('classes-toggle');
      if(classesToggle) {
        classesToggle.addEventListener('click', function(){
          if(classesToggle.classList.contains('is-busy')) return;
          classesToggle.classList.add('is-busy');
          var newVal = !classesToggle.classList.contains('on');
          fetch("{{ route('tenant.settings.update') }}", {
            method: 'PATCH',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body: JSON.stringify({tab:'booking', classes_enabled: newVal ? 1 : 0, booking_window_days: {{ $currentTenant->booking_window_days ?? 60 }}, min_notice_hours: {{ $currentTenant->min_notice_hours ?? 24 }}})
          }).then(function(r){
            classesToggle.classList.remove('is-busy');
            if(r.ok){
              classesToggle.classList.toggle('on', newVal);
              classesToggle.setAttribute('title', newVal ? 'Click to disable' : 'Click to enable');
              classesToggle.querySelector('.ia-toggle-sr').textContent = newVal ? 'Enabled' : 'Disabled';
            }
          }).catch(function(){ classesToggle.classList.remove('is-busy'); });
        });
      }

      // Add new method
      var addForm = document.getElementById('add-method-form');
      if (addForm) {
        addForm.addEventListener('submit', function (e) {
          e.preventDefault();
          var fd = new FormData(addForm);
          var body = {
            name:             fd.get('name'),
            description:      fd.get('description'),
            ask_for_time:     fd.get('ask_for_time') ? 1 : 0,
            ask_for_tracking: fd.get('ask_for_tracking') ? 1 : 0,
          };
          fetch("{{ route('tenant.receiving-methods.store') }}", {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json',
            },
            body: JSON.stringify(body),
          }).then(function (r) {
            if (r.ok) window.location.reload();
            else alert('Could not add method.');
          });
        });
      }

      // Drag-to-reorder
      if (list && window.Sortable) {
        Sortable.create(list, {
          handle: '.drag-handle',
          animation: 150,
          onEnd: function () {
            var ids = Array.from(list.querySelectorAll('.method-row'))
                          .map(function (r) { return r.getAttribute('data-method-id'); });
            fetch("{{ route('tenant.receiving-methods.reorder') }}", {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
              },
              body: JSON.stringify({ order: ids }),
            });
          }
        });
      }

      // Inline edit on blur (text) / change (checkbox)
      document.querySelectorAll('.method-edit, .method-edit-toggle').forEach(function (el) {
        var evt = el.type === 'checkbox' ? 'change' : 'blur';
        el.addEventListener(evt, function () {
          var row = el.closest('.method-row');
          var id  = row.getAttribute('data-method-id');
          var field = el.getAttribute('data-field');
          var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
          var body = {};
          body[field] = value;
          fetch("{{ url('admin/receiving-methods') }}/" + id, {
            method: 'PATCH',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json',
            },
            body: JSON.stringify(body),
          }).then(function (r) {
            if (!r.ok) {
              row.style.outline = '1px solid #d04444';
              setTimeout(function () { row.style.outline = ''; }, 1500);
            }
          });
        });
      });

      // ia-toggle: active/inactive switch on each row. PATCHes the same
      // is_active field the previous checkbox + Deactivate button hit.
      document.querySelectorAll('.method-row-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (btn.classList.contains('is-busy')) return;
          var row    = btn.closest('.method-row');
          var id     = row.getAttribute('data-method-id');
          var field  = btn.getAttribute('data-field');
          var newVal = !btn.classList.contains('on');
          btn.classList.add('is-busy');
          var body = {};
          body[field] = newVal ? 1 : 0;
          fetch("{{ url('admin/receiving-methods') }}/" + id, {
            method: 'PATCH',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
              'Accept': 'application/json',
            },
            body: JSON.stringify(body),
          }).then(function (r) {
            btn.classList.remove('is-busy');
            if (r.ok) {
              btn.classList.toggle('on', newVal);
              row.style.opacity = newVal ? '' : '.45';
              btn.setAttribute('title', newVal ? 'Click to deactivate' : 'Click to activate');
              btn.querySelector('.ia-toggle-sr').textContent = newVal ? 'Active' : 'Inactive';
            } else {
              row.style.outline = '1px solid #d04444';
              setTimeout(function () { row.style.outline = ''; }, 1500);
            }
          }).catch(function () {
            btn.classList.remove('is-busy');
          });
        });
      });

      window.deactivateMethod = function (id) {
        if (!confirm('Deactivate this drop-off method? Past bookings keep their snapshot. New bookings cannot use it.')) return;
        fetch("{{ url('admin/receiving-methods') }}/" + id, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': csrf,
            'Accept': 'application/json',
          },
        }).then(function (r) {
          if (r.ok) window.location.reload();
        });
      };
    })();
  </script>
@endpush
@endif

{{-- ============================================================ Payments ============================================================ --}}
@if($activeTab === 'payments')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="payments">

  {{-- Stripe --}}
  <div class="provider-card {{ ($s['stripe_enabled'] ?? false) ? 'enabled' : '' }}" id="stripe-card">
    <div class="provider-header">
      <div>
        <div style="font-size:15px;font-weight:500">Stripe</div>
        <div style="font-size:12px;opacity:.5;margin-top:2px">Credit and debit cards</div>
      </div>
      <button type="button" class="prov-toggle-btn {{ ($s['stripe_enabled'] ?? false) ? 'on' : '' }}"
        id="stripe-toggle" onclick="toggleProvider('stripe')"></button>
      <input type="hidden" name="stripe_enabled" id="stripe-enabled-val" value="{{ ($s['stripe_enabled'] ?? false) ? '1' : '0' }}">
    </div>
    <div class="provider-fields" id="stripe-fields">
      <div class="ia-form-group">
        <label class="ia-form-label">Mode</label>
        <select name="stripe_mode" class="ia-input" style="width:auto">
          <option value="test" @selected(($s['stripe_mode'] ?? 'test') === 'test')>Test</option>
          <option value="live" @selected(($s['stripe_mode'] ?? 'test') === 'live')>Live</option>
        </select>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Test publishable key</label>
          <input type="text" name="stripe_test_pk" class="ia-input ia-mono" value="{{ $s['stripe_test_pk'] ?? '' }}" placeholder="pk_test_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Test secret key</label>
          <input type="password" name="stripe_test_sk" class="ia-input ia-mono" value="{{ $s['stripe_test_sk'] ?? '' }}" placeholder="sk_test_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live publishable key</label>
          <input type="text" name="stripe_live_pk" class="ia-input ia-mono" value="{{ $s['stripe_live_pk'] ?? '' }}" placeholder="pk_live_…">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live secret key</label>
          <input type="password" name="stripe_live_sk" class="ia-input ia-mono" value="{{ $s['stripe_live_sk'] ?? '' }}" placeholder="sk_live_…">
        </div>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Webhook secret</label>
        <input type="password" name="stripe_webhook_secret" class="ia-input ia-mono" value="{{ $s['stripe_webhook_secret'] ?? '' }}" placeholder="whsec_…">
      </div>
    </div>
  </div>

  {{-- PayPal --}}
  <div class="provider-card {{ ($s['paypal_enabled'] ?? false) ? 'enabled' : '' }}" id="paypal-card">
    <div class="provider-header">
      <div>
        <div style="font-size:15px;font-weight:500">PayPal</div>
        <div style="font-size:12px;opacity:.5;margin-top:2px">PayPal, Venmo, Pay Later</div>
      </div>
      <button type="button" class="prov-toggle-btn {{ ($s['paypal_enabled'] ?? false) ? 'on' : '' }}"
        id="paypal-toggle" onclick="toggleProvider('paypal')"></button>
      <input type="hidden" name="paypal_enabled" id="paypal-enabled-val" value="{{ ($s['paypal_enabled'] ?? false) ? '1' : '0' }}">
    </div>
    <div class="provider-fields" id="paypal-fields">
      <div class="ia-form-group">
        <label class="ia-form-label">Mode</label>
        <select name="paypal_mode" class="ia-input" style="width:auto">
          <option value="sandbox" @selected(($s['paypal_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
          <option value="live"    @selected(($s['paypal_mode'] ?? 'sandbox') === 'live')>Live</option>
        </select>
      </div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Sandbox client ID</label>
          <input type="text" name="paypal_test_client_id" class="ia-input ia-mono" value="{{ $s['paypal_test_client_id'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Sandbox secret</label>
          <input type="password" name="paypal_test_secret" class="ia-input ia-mono" value="{{ $s['paypal_test_secret'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live client ID</label>
          <input type="text" name="paypal_live_client_id" class="ia-input ia-mono" value="{{ $s['paypal_live_client_id'] ?? '' }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Live secret</label>
          <input type="password" name="paypal_live_secret" class="ia-input ia-mono" value="{{ $s['paypal_live_secret'] ?? '' }}">
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="ia-btn ia-btn--primary" style="margin-top:8px">Save payment settings</button>
</form>
@endif

{{-- ============================================================ Domain ============================================================ --}}
@if($activeTab === 'domain')
<form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section">
  @csrf @method('PATCH')
  <input type="hidden" name="tab" value="domain">

  <div class="ia-card" style="margin-bottom:20px">
    <div class="ia-card-head"><span class="ia-card-title">Your booking URL</span></div>
    <div style="font-size:13px;margin-bottom:4px">
      <strong>{{ $currentTenant->publicUrl() }}</strong>
    </div>
    <div style="font-size:12px;opacity:.5">This is where customers go to book with you.</div>
  </div>

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head">
      <span class="ia-card-title">
        Custom domain
        <span class="domain-badge {{ $currentTenant->plan_tier }}">{{ ucfirst($currentTenant->plan_tier) }}</span>
      </span>
    </div>

    @if(in_array($currentTenant->plan_tier, ['branded', 'scale', 'custom']))
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        Point a CNAME record from your domain to <code style="font-family:var(--ia-font-mono);font-size:12px">intake.works</code>,
        then enter it here.
      </p>
      <div class="ia-form-group">
        <label class="ia-form-label">Custom domain</label>
        <input type="text" name="custom_domain" class="ia-input"
          value="{{ old('custom_domain', $currentTenant->custom_domain) }}"
          placeholder="book.yourshop.com">
      </div>
      <button type="submit" class="ia-btn ia-btn--primary">Save domain</button>
    @else
      <p style="font-size:13px;opacity:.5">
        Custom domains are available on the Branded, Scale, and Custom plans.
        <a href="{{ route('tenant.settings.index') }}?tab=billing" style="color:var(--ia-accent)">Upgrade →</a>
      </p>
    @endif
  </div>
</form>
@endif


{{-- ============================================================ Billing ============================================================ --}}
@if($activeTab === 'billing')
<div class="set-section">

  <div class="ia-card" style="margin-bottom:24px">
    <div class="ia-card-head">
      <span class="ia-card-title">Manage billing</span>
    </div>

    @if($currentTenant->stripe_customer_id)
      <p style="margin:0 0 16px;color:var(--ia-text-muted);font-size:13px;line-height:1.55">
        Update your card, download past invoices, or cancel your subscription through
        Stripe's secure billing portal.
      </p>

      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <a href="{{ route('tenant.billing.portal', ['subdomain' => request()->route('subdomain')]) }}"
           class="ia-btn ia-btn--primary"
           target="_blank"
           rel="noopener noreferrer">
          Manage billing in Stripe →
        </a>
        <span style="font-size:12px;color:var(--ia-text-muted)">
          Opens Stripe's hosted portal. Plan changes happen in-app.
        </span>
      </div>

      <div style="margin-top:24px;padding-top:20px;border-top:0.5px solid var(--ia-border);display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:480px;font-size:13px">
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Current plan</div>
          <div style="font-weight:500">{{ ucfirst($currentTenant->plan_tier ?? 'Starter') }}</div>
        </div>
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Status</div>
          <div style="font-weight:500">{{ ucfirst($currentTenant->subscription_status ?? 'unknown') }}</div>
        </div>
        @if($currentTenant->trial_ends_at)
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Trial ends</div>
          <div style="font-weight:500">{{ $currentTenant->trial_ends_at->format('M j, Y') }}</div>
        </div>
        @endif
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-text-muted);margin-bottom:4px;font-weight:500">Billing</div>
          <div style="font-weight:500">{{ ucfirst($currentTenant->stripe_subscription_cadence ?? '') ?: '—' }}</div>
        </div>
      </div>
    @else
      <p style="margin:0;color:var(--ia-text-muted);font-size:13px;line-height:1.55">
        No billing account is connected to this tenant. Contact support to enable billing.
      </p>
    @endif
  </div>

</div>
@endif

@endsection

@push('scripts')
<script>
function toggleProvider(name) {
  var card     = document.getElementById(name + '-card');
  var toggle   = document.getElementById(name + '-toggle');
  var valInput = document.getElementById(name + '-enabled-val');
  var enabled  = toggle.classList.toggle('on');
  card.classList.toggle('enabled', enabled);
  valInput.value = enabled ? '1' : '0';
}
</script>
@endpush
