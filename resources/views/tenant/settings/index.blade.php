@extends('layouts.tenant.app')
@php
  /*
   * Unified settings page. Six tabs, JS-switched (no URL params).
   * Each tab is its own form; one save button per tab in a sticky save bar.
   * Drop-off methods CRUD lives in the Business tab and uses its own
   * dedicated endpoints (tenant.receiving-methods.*) — preserved verbatim
   * from the previous settings/branding split.
   */
  $pageTitle  = 'Settings';
  $s          = $currentTenant->settings ?? [];
  $currencies = ['USD'=>'$','CAD'=>'CA$','GBP'=>'£','EUR'=>'€','AUD'=>'A$','NZD'=>'NZ$'];
  $fonts      = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];

  // Admin theme stored in settings JSON. Default to 'c' (dark).
  $adminTheme = $s['admin_theme'] ?? 'c';
  if ($adminTheme === 'a') $adminTheme = 'c';

  // Notification toggles default to ON via Tenant::notificationEnabled().
  $notifyBookingEmail = $currentTenant->notificationEnabled('booking_confirmation_email');
  $notifyBookingSms   = $currentTenant->notificationEnabled('booking_confirmation_sms');

  // MARKER-PATCH-152C — delivery scheduled toggles
  $notifyDeliveryEmail = $currentTenant->notificationEnabled('delivery_scheduled_email');
  $notifyDeliverySms   = $currentTenant->notificationEnabled('delivery_scheduled_sms');

  // MARKER-PATCH-154 — appointment reminder toggles
  $notifyApptReminderEmail = $currentTenant->notificationEnabled('appointment_reminder_email');
  $notifyApptReminderSms   = $currentTenant->notificationEnabled('appointment_reminder_sms');

  // MARKER-PATCH-155 — delivery reminder toggles
  $notifyDeliveryReminderEmail = $currentTenant->notificationEnabled('delivery_reminder_email');
  $notifyDeliveryReminderSms   = $currentTenant->notificationEnabled('delivery_reminder_sms');

  // SMS auth token: don't render the actual value back to the form. Show
  // a masked placeholder if one is set, blank if not. Controller treats
  // an empty submission as "leave unchanged."
  $hasTwilioToken = (bool) $currentTenant->twilio_auth_token;
@endphp

@push('styles')
<style>
/* -------------------------------------------------------------------------
 * Settings page chrome
 * ------------------------------------------------------------------------- */
.set-head {
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:16px; margin-bottom:18px; flex-wrap:wrap;
}
.set-booking-chip {
  display:inline-flex; align-items:center; gap:6px;
  padding:7px 12px; border-radius:99px;
  border:0.5px solid var(--ia-border);
  background:var(--ia-surface);
  font-size:12px; color:var(--ia-text);
  text-decoration:none;
  transition:background var(--ia-t), border-color var(--ia-t);
  white-space:nowrap;
}
.set-booking-chip:hover { background:var(--ia-hover); border-color:var(--ia-border-strong); }
.set-booking-chip svg { opacity:.55; }

/* Tabs */
.set-tabs {
  display:flex; gap:0;
  border-bottom:0.5px solid var(--ia-border);
  margin-bottom:20px;
  overflow-x:auto;
  scrollbar-width:none;
}
.set-tabs::-webkit-scrollbar { display:none; }
.set-tab {
  padding:10px 18px; font-size:13px; color:var(--ia-text-muted);
  cursor:pointer; border-bottom:2px solid transparent;
  background:transparent; border-left:none; border-right:none; border-top:none;
  font-family:inherit; transition:color .12s, border-color .12s;
  white-space:nowrap;
}
.set-tab:hover { color:var(--ia-text); }
.set-tab.active { color:var(--ia-text); border-bottom-color:var(--ia-accent); }

/* Panes */
.set-pane { display:none; }
.set-pane.active { display:block; }

/* MARKER-PATCH-150-POLISH-A — responsive card grid */
.set-section {
  display: block;
  max-width: 1200px;
}
/* Each card in a settings form becomes a grid cell.
   Cards default to ~half width (min 420px). Cards with .set-card--wide
   span the full row. Save bars and headers are always full-row. */
.set-section .ia-card {
  margin-bottom: 0;
}
.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  /* MARKER-PATCH-150-POLISH-C — same-row cards match heights */
  align-items: stretch;
}
.set-section--grid > .ia-card { display: flex; flex-direction: column; }
.set-section--grid .set-card--wide,
.set-section--grid .set-savebar {
  grid-column: 1 / -1;
}
@media (max-width: 880px) {
  .set-section--grid { grid-template-columns: 1fr; }
}

/* Save bar — sticky at top of pane, dims when no changes */
.set-savebar {
  position:sticky; top:0; z-index:5;
  background:var(--ia-bg);
  margin:-6px -6px 16px;
  padding:10px 6px;
  border-bottom:0.5px solid transparent;
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; flex-wrap:wrap;
  transition:border-color .15s;
}
.set-savebar.dirty { border-bottom-color:var(--ia-border); }
.set-savebar-msg {
  font-size:12px; color:var(--ia-text-dim);
  transition:color .15s;
}
.set-savebar.dirty .set-savebar-msg { color:var(--ia-text); }
.set-savebar-actions { display:flex; gap:8px; }
.set-save-btn {
  font-size:13px; padding:8px 16px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-accent);
  background:var(--ia-accent); color:var(--ia-accent-text);
  cursor:pointer; font-family:inherit; font-weight:500;
  transition:opacity .15s, filter .15s;
}
.set-save-btn:hover { filter:brightness(1.08); }
.set-save-btn:disabled,
.set-savebar:not(.dirty) .set-save-btn {
  opacity:.4; cursor:not-allowed; filter:none;
}
.set-discard-btn {
  font-size:13px; padding:8px 14px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  background:transparent; color:var(--ia-text-muted);
  cursor:pointer; font-family:inherit;
  transition:background .12s;
}
.set-discard-btn:hover { background:var(--ia-hover); color:var(--ia-text); }
.set-savebar:not(.dirty) .set-discard-btn { display:none; }

/* "Coming soon" sections (Locations, etc.) */
.set-coming-soon {
  position:relative;
  border:0.5px dashed var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:18px 20px;
  margin-bottom:20px;
  opacity:.55;
}
.set-coming-soon-pill {
  position:absolute; top:14px; right:14px;
  font-size:10px; padding:3px 9px; border-radius:99px;
  background:var(--ia-surface-2); color:var(--ia-text-dim);
  text-transform:uppercase; letter-spacing:.06em; font-weight:600;
}
.set-coming-soon-title {
  font-size:14px; font-weight:500; margin-bottom:4px;
}
.set-coming-soon-desc {
  font-size:12px; color:var(--ia-text-muted); line-height:1.5;
  max-width:520px;
}

/* Provider toggle (Stripe / PayPal) — preserved from old settings page */
.provider-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:20px; margin-bottom:16px;
  transition:border-color .12s;
}
.provider-card.enabled { border-color:var(--ia-accent); }
.provider-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:0; }
.provider-fields {
  margin-top:16px; padding-top:16px;
  border-top:0.5px solid var(--ia-border);
  display:none;
}
.provider-card.enabled .provider-fields { display:block; }
.prov-toggle-btn {
  width:38px; height:22px; background:var(--ia-border);
  border-radius:11px; position:relative;
  cursor:pointer; border:none; outline:none;
  transition:background .12s; flex-shrink:0;
}
.prov-toggle-btn.on { background:var(--ia-accent); }
.prov-toggle-btn::after {
  content:''; position:absolute; top:3px; left:3px;
  width:16px; height:16px; border-radius:50%;
  background:white; transition:transform .12s;
}
.prov-toggle-btn.on::after { transform:translateX(16px); }

/* Domain badge (preserved) */
.domain-badge {
  font-size:11px; padding:3px 10px;
  border-radius:20px; font-weight:500; margin-left:8px;
}
.domain-badge.basic   { background:var(--ia-surface-2); color:var(--ia-text-muted); }
.domain-badge.branded { background:#EEEDFE; color:#534AB7; }
.domain-badge.scale   { background:#E1F5EE; color:#0F6E56; }
.domain-badge.custom  { background:#EAF3DE; color:#3B6D11; }

/* notif-row styles removed — patch-406 (toggles moved to Communication Center) */

/* Color swatch (branding tab) */
.color-swatch-row {
  display:flex; gap:10px; align-items:center; margin-top:6px;
}
.color-swatch {
  width:36px; height:36px;
  border-radius:var(--ia-r-md);
  border:0.5px solid var(--ia-border);
  overflow:hidden; cursor:pointer; flex-shrink:0;
}
.color-swatch input[type=color] {
  width:52px; height:52px; margin:-8px;
  border:none; cursor:pointer; background:none; padding:0;
}

/* Logo previews (branding tab) */
.logo-preview { height:40px; border-radius:6px; margin-bottom:8px; display:block; }
.logo-preview-dark {
  background:#111; padding:6px 10px; border-radius:6px;
  margin-bottom:8px; display:inline-block;
}
.logo-preview-dark img { height:32px; }

/* Theme picker (appearance tab) */
.theme-grid {
  display:grid; grid-template-columns:repeat(2,1fr);
  gap:12px; margin-top:8px; max-width:420px;
}
.theme-card {
  border:0.5px solid var(--ia-border);
  border-radius:var(--ia-r-lg);
  padding:14px; cursor:pointer; transition:all .12s;
  position:relative;
}
.theme-card:hover { border-color:var(--ia-accent); }
.theme-card.selected { border-color:var(--ia-accent); background:var(--ia-accent-soft); }
.theme-card input { position:absolute; opacity:0; width:0; height:0; }
.theme-preview {
  height:60px; border-radius:var(--ia-r-md);
  overflow:hidden; margin-bottom:8px; display:flex;
}
.theme-label { font-size:12px; font-weight:500; text-align:center; }
.preview-b-wrap { flex:1; display:flex; flex-direction:column; }
.preview-b-top  { height:12px; background:#ffffff; border-bottom:0.5px solid #e8e8e4; }
.preview-b-main { flex:1; background:#ffffff; }
.preview-c-side { width:35%; background:#0c0c0c; }
.preview-c-main { flex:1; background:#111111; }

/* SMS test status flash */
.sms-test-status {
  margin-top:10px; font-size:12px; padding:8px 12px;
  border-radius:var(--ia-r-md);
  display:none;
}
.sms-test-status.success { display:block; background:rgba(120,200,120,.10); color:#78c878; border:0.5px solid rgba(120,200,120,.25); }
.sms-test-status.error   { display:block; background:rgba(240,149,149,.10); color:#F09595; border:0.5px solid rgba(240,149,149,.25); }
</style>
@endpush

@section('content')

<div class="set-head">
  <div>
    <h1 class="ia-page-title" style="margin-bottom:4px">Settings</h1>
    <p class="ia-page-subtitle" style="margin:0">Configure your shop's operational preferences and branding.</p>
  </div>
  <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer" class="set-booking-chip">
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
      <path d="M5 9L9 5M9 5H5.5M9 5v3.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
      <rect x="2" y="2" width="10" height="10" rx="2" stroke="currentColor" stroke-width="1.2"/>
    </svg>
    Open booking page
  </a>
</div>

{{-- MARKER-PATCH-165 — success flash removed; the global layout renders it once at the top. --}}
@if($errors->any())
<div style="padding:10px 14px;margin-bottom:16px;border-radius:var(--ia-r-md);background:rgba(240,149,149,.10);border:0.5px solid rgba(240,149,149,.25);font-size:13px;color:#F09595">
  @foreach($errors->all() as $err){{ $err }}<br>@endforeach
</div>
@endif

<div class="set-tabs" role="tablist">
  <button type="button" class="set-tab active" data-tab="business"      role="tab">Business</button>
  <button type="button" class="set-tab"        data-tab="branding"      role="tab">Branding</button>
  <button type="button" class="set-tab"        data-tab="communication" role="tab">Communication</button>
  <button type="button" class="set-tab"        data-tab="account"       role="tab">Account</button>
  <button type="button" class="set-tab"        data-tab="payments"      role="tab">Payments</button>
  <button type="button" class="set-tab"        data-tab="tags"          role="tab">Print &amp; receipts</button>{{-- MARKER-PATCH-315 / 339 --}}
  <button type="button" class="set-tab"        data-tab="ordering"      role="tab">Ordering</button>{{-- MARKER-SO-AUTOVENDOR --}}
</div>

{{-- =====================================================================
     BUSINESS — currency, timezone, booking, tax, drop-off methods
     ===================================================================== --}}
<div class="set-pane active" id="pane-business" role="tabpanel">

  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="business">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save business settings</button>
      </div>
    </div>

    {{-- Currency --}}
    <div class="ia-card" style="margin-bottom:20px">
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

    {{-- Timezone --}}
    <div class="ia-card" style="margin-bottom:20px">
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

    {{-- Booking window --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Booking window</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">How far ahead can customers book?</label>
          <input type="number" name="booking_window_days" class="ia-input" min="1" max="365"
            value="{{ old('booking_window_days', $currentTenant->booking_window_days ?? 60) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">Days from today</p>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Minimum notice required</label>
          <input type="number" name="min_notice_hours" class="ia-input" min="0" max="168"
            value="{{ old('min_notice_hours', $currentTenant->min_notice_hours ?? 24) }}">
          <p style="font-size:11px;opacity:.4;margin-top:4px">0 = same-day bookings allowed</p>
        </div>
      </div>
    </div>

    {{-- Class bookings --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Class bookings</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable class bookings</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a Classes section to your admin and a customer-facing /classes page.</div>
        </div>
        <input type="hidden" name="classes_enabled" id="classes_enabled_input" value="{{ $currentTenant->classes_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->classes_enabled ? 'on' : '' }}"
          id="classes-toggle-btn"
          aria-label="Enable class bookings">
          <span class="ia-toggle-sr">{{ $currentTenant->classes_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-156 — Deliveries --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Deliveries</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Enable deliveries</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Internal pickup &amp; dropoff scheduling. Adds a Deliveries pill to your Schedule menu.</div>
        </div>
        <input type="hidden" name="deliveries_enabled" id="deliveries_enabled_input" value="{{ $currentTenant->deliveries_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->deliveries_enabled ? 'on' : '' }}"
          id="deliveries-toggle-btn"
          aria-label="Enable deliveries">
          <span class="ia-toggle-sr">{{ $currentTenant->deliveries_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
    </div>

    {{-- MARKER-PATCH-158-B — Multi-asset --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Multi-asset appointments</span></div>
      <div style="padding:6px 0;display:flex;align-items:center;justify-content:space-between;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:500">Track customer assets</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Track bikes, vehicles, pets, or other items per customer, and attach multiple to a single appointment. Useful for family drop-offs, fleet servicing, or multi-pet appointments.</div>
        </div>
        <input type="hidden" name="multi_asset_enabled" id="multi_asset_enabled_input" value="{{ $currentTenant->multi_asset_enabled ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ $currentTenant->multi_asset_enabled ? 'on' : '' }}"
          id="multi-asset-toggle-btn"
          aria-label="Enable multi-asset tracking">
          <span class="ia-toggle-sr">{{ $currentTenant->multi_asset_enabled ? 'Enabled' : 'Disabled' }}</span>
        </button>
      </div>
      {{-- MARKER-PATCH-215 — what this tenant calls its assets (drives customer booking copy) --}}
      <div class="ia-input-grid-2" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ia-border,rgba(255,255,255,.08))">
        <div class="ia-form-group">
          <label class="ia-form-label">What you call one (singular)</label>
          <input type="text" name="asset_label_singular" class="ia-input" maxlength="30"
            placeholder="item" value="{{ old('asset_label_singular', $currentTenant->asset_label_singular) }}">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Plural</label>
          <input type="text" name="asset_label_plural" class="ia-input" maxlength="30"
            placeholder="items" value="{{ old('asset_label_plural', $currentTenant->asset_label_plural) }}">
        </div>
      </div>
      <div style="font-size:12px;opacity:.5;margin-top:8px">Shown on your customer booking page — e.g. “bike”, “vehicle”, “pet”. Leave blank for “item”.</div>
    </div>

    {{-- Tax --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Sales tax</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Default tax rate (%)</label>
        <input type="number" name="default_tax_rate" class="ia-input" step="0.001" min="0" max="25"
          style="max-width:200px"
          value="{{ old('default_tax_rate', $currentTenant->default_tax_rate) }}"
          placeholder="e.g. 8.875">
        <p style="font-size:11px;opacity:.5;margin-top:6px;line-height:1.5">
          Applied to taxable items at checkout. Leave blank if you don't collect sales tax.
        </p>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border);margin-top:8px">
        <div>
          <div style="font-size:13px;font-weight:500">Services are taxable by default</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Per-service overrides available later when editing a service.</div>
        </div>
        <input type="hidden" name="tax_services_default" id="tax_services_default_input" value="{{ ($currentTenant->tax_services_default ?? true) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_services_default ?? true) ? 'on' : '' }}"
          id="tax-services-toggle-btn"
          aria-label="Services are taxable by default">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_services_default ?? true) ? 'Yes' : 'No' }}</span>
        </button>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-top:0.5px solid var(--ia-border)">
        <div>
          <div style="font-size:13px;font-weight:500">Customers can be tax-exempt</div>
          <div style="font-size:12px;opacity:.5;margin-top:2px">Adds a "tax exempt" toggle to customer records (useful for non-profits, resellers).</div>
        </div>
        <input type="hidden" name="tax_supports_exempt" id="tax_supports_exempt_input" value="{{ ($currentTenant->tax_supports_exempt ?? false) ? '1' : '0' }}">
        <button type="button"
          class="ia-toggle {{ ($currentTenant->tax_supports_exempt ?? false) ? 'on' : '' }}"
          id="tax-exempt-toggle-btn"
          aria-label="Customers can be tax-exempt">
          <span class="ia-toggle-sr">{{ ($currentTenant->tax_supports_exempt ?? false) ? 'Yes' : 'No' }}</span>
        </button>
      </div>
    </div>

    {{-- Locations (coming soon) --}}
    <div class="set-coming-soon">
      <span class="set-coming-soon-pill">Add-on</span>
      <div class="set-coming-soon-title">Locations</div>
      <div class="set-coming-soon-desc">
        Run multiple shops from one Intake account — separate calendars, staff, and reporting per location.
        Available as a paid add-on. Talk to support to enable.
      </div>
    </div>

  </form>

  {{-- Drop-off methods (separate block — own endpoints, not part of the main form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
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
  </div>
</div>

{{-- =====================================================================
     BRANDING — shop identity, logos, colors, typography
     ===================================================================== --}}
<div class="set-pane" id="pane-branding" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="branding">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save branding</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Shop identity</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Shop name <span class="ia-required">*</span></label>
        <input type="text" name="name" class="ia-input" value="{{ old('name', $currentTenant->name) }}" required>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Tagline</label>
        <input type="text" name="tagline" class="ia-input" value="{{ old('tagline', $currentTenant->tagline) }}"
          placeholder="e.g. Expert bike service since 2010">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logos</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        Upload two versions of your logo. The system automatically picks the right one based on the background color.
      </p>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default logo <span style="opacity:.4;font-weight:400">(for light backgrounds)</span></label>
          @if($currentTenant->logo_url)
            <img src="{{ $currentTenant->logo_url }}" alt="Logo" class="logo-preview">
          @endif
          <input type="file" name="logo" accept="image/*" class="ia-input" style="padding:6px">
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Light logo <span style="opacity:.4;font-weight:400">(for dark backgrounds)</span></label>
          @if($currentTenant->logo_light_url)
            <div class="logo-preview-dark">
              <img src="{{ $currentTenant->logo_light_url }}" alt="Light logo">
            </div>
          @endif
          <input type="file" name="logo_light" accept="image/*" class="ia-input" style="padding:6px">
          <div style="font-size:11px;opacity:.35;margin-top:4px">White or light-colored version for dark hero sections and dark theme booking forms.</div>
        </div>
      </div>
      <div class="ia-form-group" style="margin-top:12px">
        <label class="ia-form-label">Favicon</label>
        @if($currentTenant->favicon_url)
          <img src="{{ $currentTenant->favicon_url }}" alt="Favicon" style="height:32px;border-radius:4px;margin-bottom:8px;display:block">
        @endif
        <input type="file" name="favicon" accept="image/*" class="ia-input" style="padding:6px;max-width:300px">
      </div>
    </div>

    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Logo display size</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:18px">
        Drag the sliders to set how big the uploaded logo renders. The preview shows what it'll look like.
        Doesn't affect the file itself — re-uploading isn't needed.
      </p>

      @php
        // Pulled into PHP vars so JS init values match what's in the DB.
        $adminPx   = (int) ($currentTenant->logo_size_admin   ?? 26);
        $bookingPx = (int) ($currentTenant->logo_size_booking ?? 28);
        // Pick whichever logo will actually render in each surface.
        $adminLogo = \App\Support\ColorHelper::pickLogo($currentTenant, '#0c0c0c'); // dark sidebar
        $bookLogo  = \App\Support\ColorHelper::pickLogo($currentTenant, $currentTenant->bg_color ?? '#ffffff'); // booking bg
      @endphp

      {{-- Admin sidebar --}}
      <div style="margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Admin sidebar</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-admin-readout">{{ $adminPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_admin" id="logo-admin-slider"
               min="16" max="80" step="1" value="{{ $adminPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>80px</span>
        </div>

        {{-- Mini preview chip — mimics the sidebar logo block --}}
        <div style="margin-top:14px;background:#0c0c0c;border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:60px">
          @if($adminLogo)
            <img id="logo-admin-preview" src="{{ $adminLogo }}" alt="Admin logo preview"
                 style="height:{{ $adminPx }}px;width:auto;border-radius:4px;max-width:160px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>

      {{-- Booking page --}}
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="ia-form-label" style="margin:0">Booking page</label>
          <span style="font-size:12px;color:var(--ia-text-muted);font-variant-numeric:tabular-nums">
            <span id="logo-booking-readout">{{ $bookingPx }}</span>px
          </span>
        </div>
        <input type="range" name="logo_size_booking" id="logo-booking-slider"
               min="16" max="120" step="1" value="{{ $bookingPx }}"
               style="width:100%;margin:0">
        <div style="font-size:11px;opacity:.45;margin-top:4px;display:flex;justify-content:space-between">
          <span>16px</span><span>120px</span>
        </div>

        {{-- Mini preview chip — mimics the booking page top bar --}}
        @php $previewBg = $currentTenant->bg_color ?? '#ffffff'; @endphp
        <div style="margin-top:14px;background:{{ $previewBg }};border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:14px 16px;display:flex;align-items:center;gap:10px;min-height:80px">
          @if($bookLogo)
            <img id="logo-booking-preview" src="{{ $bookLogo }}" alt="Booking logo preview"
                 style="height:{{ $bookingPx }}px;width:auto;border-radius:4px;max-width:240px;object-fit:contain;transition:height .05s linear">
          @else
            <span style="color:#999;font-size:12px;font-style:italic">Upload a logo above to preview</span>
          @endif
        </div>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Colors</span></div>
      @foreach([
        ['accent_color', 'Accent color', $currentTenant->accent_color ?? '#BEF264', 'Used for buttons, links, and active states'],
        ['text_color',   'Text color',   $currentTenant->text_color   ?? '#111111', 'Main body text on your booking form'],
        ['bg_color',     'Background',   $currentTenant->bg_color     ?? '#ffffff', 'Page background on your booking form'],
      ] as [$name, $label, $value, $hint])
      <div class="ia-form-group">
        <label class="ia-form-label">{{ $label }}</label>
        <div class="color-swatch-row">
          <div class="color-swatch">
            <input type="color" name="{{ $name }}" value="{{ old($name, $value) }}" id="color-{{ $name }}">
          </div>
          <input type="text" class="ia-input" style="width:110px;font-family:var(--ia-font-mono);font-size:13px"
            value="{{ old($name, $value) }}" id="text-{{ $name }}"
            oninput="document.getElementById('color-{{ $name }}').value=this.value"
            pattern="^#[0-9A-Fa-f]{6}$">
          <span style="font-size:12px;opacity:.45">{{ $hint }}</span>
        </div>
      </div>
      @endforeach
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Typography</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Heading font</label>
          <select name="font_heading" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_heading', $currentTenant->font_heading) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Body font</label>
          <select name="font_body" class="ia-input">
            @foreach($fonts as $font)
              <option value="{{ $font }}" @selected(old('font_body', $currentTenant->font_body) === $font)>{{ $font }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </div>
  </form>
</div>

{{-- =====================================================================
     COMMUNICATION — email sender, SMS provider, notifications
     ===================================================================== --}}
<div class="set-pane" id="pane-communication" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="communication">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save communication settings</button>
      </div>
    </div>



    {{-- Email sender details --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Email sender details</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:16px">
        All emails to your customers will be sent from these details.
      </p>
      <div class="ia-form-group">
        <label class="ia-form-label">From name</label>
        <input type="text" name="email_from_name" class="ia-input"
          value="{{ old('email_from_name', $currentTenant->email_from_name) }}"
          placeholder="{{ $currentTenant->name }}">
      </div>
      {{-- MARKER-PATCH-143 — From address locked to <subdomain>@intake.works until custom domains land --}}
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">From email address</label>
          <input type="email" class="ia-input" readonly disabled
            value="{{ $currentTenant->subdomain }}@intake.works"
            style="opacity:.7;cursor:not-allowed">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            All your customer emails come from this address. Custom domains coming soon.
          </div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Reply-to (optional)</label>
          <input type="email" name="email_reply_to" class="ia-input"
            value="{{ old('email_reply_to', $currentTenant->email_reply_to) }}"
            placeholder="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
            Where replies go. Usually your shop's main email.
          </div>
        </div>
      </div>

      {{-- MARKER-PATCH-144 — Test send block (no nested form, uses fetch) --}}
      <div style="margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)" id="email-test-block">
        <div style="font-size:13px;font-weight:500;margin-bottom:6px">Test your email setup</div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" id="email-test-recipient" class="ia-input" style="flex:1;min-width:240px"
            placeholder="recipient@example.com"
            value="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <button type="button" id="email-test-btn" class="ia-btn ia-btn--ghost ia-btn--sm">Send test email</button>
        </div>
        <div id="email-test-result" style="margin-top:10px;font-size:12px;display:none"></div>
      </div>
      <script>
        (function() {
          const btn = document.getElementById('email-test-btn');
          const recipient = document.getElementById('email-test-recipient');
          const result = document.getElementById('email-test-result');
          if (!btn) return;
          btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const r = (recipient.value || '').trim();
            if (!r) {
              result.style.display = 'block';
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Enter a recipient email first.';
              return;
            }
            btn.disabled = true;
            btn.textContent = 'Sending…';
            result.style.display = 'block';
            result.style.color = 'var(--ia-text-dim)';
            result.textContent = 'Sending test email to ' + r + '…';
            try {
              const resp = await fetch('{{ route('tenant.settings.email.test') }}', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  'X-Requested-With': 'XMLHttpRequest',
                  'Accept': 'application/json'
                },
                body: 'recipient=' + encodeURIComponent(r)
              });
              if (resp.ok) {
                result.style.color = 'var(--ia-ok, #86EFAC)';
                result.textContent = 'Sent to ' + r + '. Check the inbox (and spam folder) within ~1 minute.';
              } else {
                const body = await resp.text();
                result.style.color = 'var(--ia-bad, #F87171)';
                result.textContent = 'Send failed (HTTP ' + resp.status + '). Check logs for details.';
              }
            } catch (err) {
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Send failed: ' + err.message;
            } finally {
              btn.disabled = false;
              btn.textContent = 'Send test email';
            }
          });
        })();
      </script>
      <div class="ia-form-group">
        <label class="ia-form-label">New booking notification email</label>
        <input type="email" name="notification_email" class="ia-input"
          value="{{ old('notification_email', $currentTenant->notification_email) }}"
          placeholder="Where to send new booking alerts">
      </div>
    </div>

    {{-- MARKER-PATCH-228B — Rentals pointer card --}}
    @if($currentTenant->rentals_enabled)
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Rentals &amp; leasing</span>
        <span class="ia-badge {{ $currentTenant->rentals_visible ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->rentals_visible ? 'On' : 'Hidden' }}{{ $currentTenant->leases_enabled ? ' · leasing' : '' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Turn rentals on or off, configure your season window, and enable season-long leasing.
      </p>
      <a href="{{ route('tenant.rentals.settings') }}" class="ia-btn ia-btn--primary">Open Rental settings</a>
    </div>
    @endif

    {{-- MARKER-PATCH-228B — Notifications/Alerts pointer card --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Notifications</span></div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Choose how you hear about new bookings, overdue rentals, payments, and more — in-app and by text.
      </p>
      <a href="{{ route('tenant.alerts.prefs') }}" class="ia-btn ia-btn--primary">Open Notification settings</a>
    </div>

    {{-- MARKER-PATCH-224 — SMS config moved to Settings -> Messaging --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
        <span class="ia-card-title">Text messaging</span>
        <span class="ia-badge {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'ia-badge--paid' : 'ia-badge--unpaid' }}">
          {{ $currentTenant->sms_enabled && $currentTenant->sms_from_number ? 'Active · ' . $currentTenant->sms_from_number : 'Not set up' }}
        </span>
      </div>
      <p style="font-size:13px;opacity:.5;margin-bottom:12px;line-height:1.55">
        Your business text number, two-way Inbox routing, and SMS sending live on the Messaging page.
      </p>
      <a href="{{ route('tenant.settings.messaging') }}" class="ia-btn ia-btn--primary">Open Messaging settings</a>
    </div>

    {{-- MARKER-PATCH-406 — customer notifications moved to Communication Center --}}
    <div class="ia-card set-card--wide" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Customer notifications</span></div>
      <p style="font-size:13px;opacity:.6;margin:0;line-height:1.55">
        Booking, delivery, reminder, and receipt messages are managed in
        <a href="{{ route('tenant.communication.index') }}" style="color:var(--ia-accent)">Communication</a>.
      </p>
    </div>
  </form>
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  {{-- MARKER-PATCH-150-POLISH-C — wrap in grid section so set-card--wide applies --}}
  <div class="set-section set-section--grid">
  <div class="ia-card set-card--wide" style="margin-bottom: 20px;">
    <div class="ia-card-head">
      <span class="ia-card-title">Web analytics</span>
    </div>
    <p style="font-size:13px;opacity:.5;margin-bottom:14px">
      Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
      Leave blank to disable.
    </p>
    <form method="POST" action="{{ route('tenant.settings.analytics.update') }}">
      @csrf
      <div class="ia-form-group">
        <label class="ia-form-label">GA-4 measurement ID</label>
        <input type="text" name="analytics_ga4_id" class="ia-input"
               value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
               placeholder="G-XXXXXXXXXX"
               style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
        <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
          Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
        </div>
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
  </div>{{-- MARKER-PATCH-150-POLISH-C close grid wrapper --}}

</div>

{{-- =====================================================================
     ACCOUNT — booking URL, custom domain, subscription
     ===================================================================== --}}
<div class="set-pane" id="pane-account" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="account">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save account</button>
      </div>
    </div>

    {{-- Booking URL (read-only) --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Your booking URL</span></div>
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">
        <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" rel="noopener noreferrer"
           style="color:var(--ia-accent);text-decoration:none;font-family:var(--ia-font-mono);font-size:13px">
          {{ $currentTenant->bookingUrl() }}
        </a>
      </div>
      <div style="font-size:12px;opacity:.5">This is where customers go to book with you.</div>
    </div>

    {{-- MARKER-PATCH-120 - Custom domains live on a dedicated page --}}
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head">
        <span class="ia-card-title">Custom domains</span>
      </div>
      <p style="font-size:13px;opacity:.6;margin-bottom:14px;line-height:1.55">
        Connect your own domain — like <code style="font-family:var(--ia-font-mono);font-size:12px">{{ $currentTenant->subdomain }}.com</code> — to your Intake site. HTTPS is automatic.
      </p>
      <a href="{{ route('tenant.domains.index', []) }}"
         class="ia-btn ia-btn-secondary"
         style="display:inline-flex;align-items:center;gap:6px">
        Manage domains →
      </a>
    </div>
  </form>

  {{-- Subscription (read-only, separate from form) --}}
  <div class="set-section set-section--grid">
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Subscription</span></div>

      @if($currentTenant->stripe_customer_id)
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:480px;font-size:13px;margin-bottom:16px">
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

        <a href="{{ route('tenant.billing.portal', []) }}"
           class="ia-btn ia-btn--primary"
           target="_blank" rel="noopener noreferrer">
          Manage billing in Stripe →
        </a>
        <p style="font-size:12px;color:var(--ia-text-muted);margin-top:8px">
          Update your card, download invoices, or cancel your subscription through Stripe's secure portal.
        </p>
      @else
        <p style="margin:0;color:var(--ia-text-muted);font-size:13px;line-height:1.55">
          No billing account is connected to this tenant. Contact support to enable billing.
        </p>
      @endif
    </div>
  </div>
</div>

{{-- =====================================================================
     PAYMENTS — Stripe + PayPal (preserved verbatim)
     ===================================================================== --}}
<div class="set-pane" id="pane-payments" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="payments">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span><!-- MARKER-PATCH-165 — populated by JS -->
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save payment settings</button>
      </div>
    </div>

    {{-- MARKER-PATCH-169 — Direct Payments bridge feature.
         Only renders when master admin flipped direct_payments_enabled on for this tenant.
         Tenant pastes their own Stripe keys here for register card-sales. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    {{-- MARKER-PATCH-618 — toggle-able (default on). Off hides card + payment-link tenders at the register; refunds of past charges still work. --}}
    <div class="provider-card {{ ($s['stripe_register_enabled'] ?? true) ? 'enabled' : '' }}" id="register-payments-card">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">
            Register card payments
          </div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Hand-key card numbers and send payment links from the register. Paste your own Stripe keys below.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['stripe_register_enabled'] ?? true) ? 'on' : '' }}"
          id="register-payments-toggle" onclick="toggleProvider('register-payments')"></button>
        <input type="hidden" name="stripe_register_enabled" id="register-payments-enabled-val" value="{{ ($s['stripe_register_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="register-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="register_payments_mode" class="ia-input" style="width:auto">
            <option value="test" @selected(($s['register_payments_mode'] ?? 'test') === 'test')>Test</option>
            <option value="live" @selected(($s['register_payments_mode'] ?? 'test') === 'live')>Live</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Start in test mode. Switch to live only after you've verified end-to-end flows with test cards.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Test publishable key</label>
            <input type="text" name="register_payments_test_pk" value="{{ $s['register_payments_test_pk'] ?? '' }}" class="ia-input" placeholder="pk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Test secret key</label>
            <input type="password" name="register_payments_test_sk" value="{{ $s['register_payments_test_sk'] ?? '' }}" class="ia-input" placeholder="sk_test_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live publishable key</label>
            <input type="text" name="register_payments_live_pk" value="{{ $s['register_payments_live_pk'] ?? '' }}" class="ia-input" placeholder="pk_live_…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Live secret key</label>
            <input type="password" name="register_payments_live_sk" value="{{ $s['register_payments_live_sk'] ?? '' }}" class="ia-input" placeholder="sk_live_…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signing secret</label>
          <input type="password" name="register_payments_webhook_secret" value="{{ $s['register_payments_webhook_secret'] ?? '' }}" class="ia-input" placeholder="whsec_…" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Stripe Dashboard -> Developers -> Webhooks. Point a new endpoint at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/stripe-direct/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment_intent.succeeded</code>, <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">checkout.session.completed</code>, and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">charge.refunded</code>.
          </div>
        </div>

      </div>
    </div>
    @endif

    {{-- MARKER-PATCH-473 — Square (tenant-connected, paste-token). Same master-admin gate as Stripe. --}}
    @if($currentTenant->direct_payments_enabled ?? false)
    <div class="provider-card {{ ($s['square_enabled'] ?? true) ? 'enabled' : '' }}" id="square-payments-card" style="margin-top:16px">
      <div class="provider-header">
        <div>
          <div style="font-size:15px;font-weight:500;display:flex;align-items:center;gap:8px">Square card payments</div>
          <div style="font-size:12px;opacity:.6;margin-top:2px">Connect your own Square account as an alternative to Stripe. Paste the credentials from your Square app, save, then test the connection.</div>
        </div>
        <button type="button" class="prov-toggle-btn {{ ($s['square_enabled'] ?? true) ? 'on' : '' }}"
          id="square-payments-toggle" onclick="toggleProvider('square-payments')"></button>
        <input type="hidden" name="square_enabled" id="square-payments-enabled-val" value="{{ ($s['square_enabled'] ?? true) ? '1' : '0' }}">
      </div>
      <div class="provider-fields" id="square-payments-fields">
        <div class="ia-form-group">
          <label class="ia-form-label">Mode</label>
          <select name="square_payments_mode" class="ia-input" style="width:auto">
            <option value="sandbox" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'sandbox')>Sandbox</option>
            <option value="production" @selected(($s['square_payments_mode'] ?? 'sandbox') === 'production')>Production</option>
          </select>
          <div style="font-size:11px;opacity:.55;margin-top:6px">Sandbox and production are separate Square apps with their own credentials. Verify in sandbox first.</div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Sandbox credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_sandbox_app_id" value="{{ $s['square_sandbox_app_id'] ?? '' }}" class="ia-input" placeholder="sandbox-sq0idb-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_sandbox_location_id" value="{{ $s['square_sandbox_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_sandbox_access_token" value="{{ $s['square_sandbox_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="font-size:11px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Production credentials</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="ia-form-group">
            <label class="ia-form-label">Application ID</label>
            <input type="text" name="square_production_app_id" value="{{ $s['square_production_app_id'] ?? '' }}" class="ia-input" placeholder="sq0idp-…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group">
            <label class="ia-form-label">Location ID</label>
            <input type="text" name="square_production_location_id" value="{{ $s['square_production_location_id'] ?? '' }}" class="ia-input" placeholder="L…" autocomplete="off" spellcheck="false">
          </div>
          <div class="ia-form-group" style="grid-column:1 / -1">
            <label class="ia-form-label">Access token</label>
            <input type="password" name="square_production_access_token" value="{{ $s['square_production_access_token'] ?? '' }}" class="ia-input" placeholder="EAAAl…" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div class="ia-form-group">
          <label class="ia-form-label">Webhook signature key</label>
          <input type="password" name="square_webhook_signature_key" value="{{ $s['square_webhook_signature_key'] ?? '' }}" class="ia-input" placeholder="webhook signature key" autocomplete="off" spellcheck="false">
          <div style="font-size:11px;opacity:.55;margin-top:6px">
            From Square Developer Console -> your app -> Webhooks. Point a subscription at <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">{{ url('/webhooks/square/' . $currentTenant->id) }}</code> and subscribe to <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">payment.updated</code> and <code style="background:var(--ia-input-bg);padding:1px 5px;border-radius:3px;font-size:11px">refund.updated</code>.
          </div>
        </div>

        <div style="height:1px;background:var(--ia-border);margin:18px 0"></div>

        <div style="display:flex;align-items:center;gap:12px">
          <button type="button" class="ia-btn ia-btn--ghost" onclick="squareTestConnection(this)">Test connection</button>
          <span id="square-test-result" style="font-size:12px;opacity:.85"></span>
        </div>
        <div style="font-size:11px;opacity:.55;margin-top:8px">Save your credentials first, then test. This calls Square with your saved access token to confirm the location is reachable.</div>
      </div>
    </div>
    <script>
      window.squareTestConnection = function (btn) {
        var out = document.getElementById('square-test-result');
        btn.disabled = true; out.textContent = 'Testing…'; out.style.color = '';
        fetch({!! json_encode(route('tenant.settings.square.verify')) !!}, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': {!! json_encode(csrf_token()) !!}, 'Accept': 'application/json' },
          body: '{}'
        }).then(function (r) { return r.json(); }).then(function (d) {
          btn.disabled = false;
          if (d && d.ok) { out.textContent = '\u2713 ' + (d.message || 'Connected'); out.style.color = 'var(--ia-accent)'; }
          else { out.textContent = '\u2715 ' + ((d && d.message) || 'Failed'); out.style.color = '#f87171'; }
        }).catch(function () { btn.disabled = false; out.textContent = '\u2715 Request failed'; out.style.color = '#f87171'; });
      };
    </script>
    @endif

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

  </form>

  {{-- MARKER-PATCH-629 — unified payment methods list (replaces the 618 Venmo/Cash App cards) --}}
  @include('tenant.settings._payment-methods')
</div>
{{-- MARKER-PATCH-315 — Work-order tag settings --}}
{{-- =====================================================================
     ORDERING — how special orders pick a vendor      MARKER-SO-AUTOVENDOR
     ===================================================================== --}}
@php $soAuto = $s['special_orders']['auto_assign_vendor'] ?? 'preferred'; @endphp
<div class="set-pane" id="pane-ordering" role="tabpanel">
  <form method="POST" action="{{ route('tenant.settings.update') }}" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH')
    <input type="hidden" name="tab" value="ordering">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save ordering settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Special orders — vendor assignment</span></div>
      <p style="font-size:13px;opacity:.55;margin-bottom:16px">
        When a special order is created, Intake can pick the vendor for you from the
        vendors already linked to that item. You can always change it before placing the order.
      </p>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="preferred" @checked($soAuto === 'preferred')>
        <span>
          <strong style="display:block;font-size:13.5px">Preferred vendor</strong>
          <span style="font-size:12px;opacity:.6">Uses the vendor marked preferred on the item, falling back to whoever you ordered from most recently.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:8px;cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="lowest_price" @checked($soAuto === 'lowest_price')>
        <span>
          <strong style="display:block;font-size:13.5px">Lowest price</strong>
          <span style="font-size:12px;opacity:.6">Cheapest cost among vendors that carry it, preferring vendors that actually show stock. Falls back to the preferred vendor when no cost is known.</span>
        </span>
      </label>

      <label class="set-radio-row" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md);cursor:pointer">
        <input type="radio" name="so_auto_assign_vendor" value="off" @checked($soAuto === 'off')>
        <span>
          <strong style="display:block;font-size:13.5px">Don't assign automatically</strong>
          <span style="font-size:12px;opacity:.6">Leave the vendor blank and choose it yourself on the special orders screen.</span>
        </span>
      </label>
    </div>

    {{-- MARKER-BIZ-SETTINGS — defaults for new business customers, so
         payment terms and PO-required are not fields you have to remember
         to set one customer at a time. --}}
    @php $custDefaults = $s['customers'] ?? []; @endphp
    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Business customers — defaults</span></div>
      <p style="font-size:13px;opacity:.55;margin-bottom:16px">
        Applied when a new business customer is created. Each customer can still be changed individually.
      </p>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default payment terms</label>
          <select name="cust_default_payment_terms" class="ia-input">
            <option value="">Due at service</option>
            <option value="net_15" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_15')>Net 15</option>
            <option value="net_30" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_30')>Net 30</option>
            <option value="net_60" @selected(($custDefaults['default_payment_terms'] ?? '') === 'net_60')>Net 60</option>
          </select>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Purchase orders</label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:10px 0;cursor:pointer">
            <input type="checkbox" name="cust_default_po_required" value="1" @checked($custDefaults['default_po_required'] ?? false)>
            <span>New business customers require a PO by default</span>
          </label>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="set-pane" id="pane-tags" role="tabpanel">
  @php
    $wot      = $s['work_order_tag'] ?? [];
    $wotOn    = fn($k) => array_key_exists($k, $wot) ? (bool) $wot[$k] : true;
    $wotLead  = $wot['lead_days'] ?? 3;
    $wotPaper = ($wot['paper'] ?? '80mm') === '58mm' ? '58mm' : '80mm';
    $wotLogo  = $wot['logo_path'] ?? null;
    $wotFeed  = (int) ($wot['feed_mm'] ?? 0);
    $wotHeader = (string) ($wot['header_text'] ?? ''); // MARKER-PATCH-330
    $wotFooter = (string) ($wot['footer_text'] ?? ''); // MARKER-PATCH-330
  @endphp
  <style>
    .wot-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:0.5px solid var(--ia-border);cursor:pointer}
    .wot-row:last-child{border-bottom:none}
    .wot-row-l .t{font-size:13px;color:var(--ia-text)}
    .wot-row-l .d{font-size:11.5px;color:var(--ia-muted);margin-top:2px}
    .wot-switch{appearance:none;-webkit-appearance:none;width:38px;height:22px;border-radius:99px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);position:relative;cursor:pointer;flex-shrink:0;transition:background .15s;margin:0}
    .wot-switch::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:var(--ia-muted);transition:all .15s}
    .wot-switch:checked{background:var(--ia-accent);border-color:var(--ia-accent)}
    .wot-switch:checked::after{left:18px;background:#0a0a0a}
    .wot-seg{display:flex;gap:6px;background:var(--ia-input-bg);border:0.5px solid var(--ia-border);border-radius:8px;padding:4px;max-width:240px}
    .wot-seg label{flex:1;text-align:center;padding:8px;border-radius:5px;font-size:13px;cursor:pointer;color:var(--ia-muted);position:relative}
    .wot-seg input{position:absolute;opacity:0;pointer-events:none}
    .wot-seg label:has(input:checked){background:var(--ia-accent);color:#0a0a0a;font-weight:600}
    .wot-logo-preview{background:#fff;padding:10px 12px;border-radius:8px;display:inline-block;margin-bottom:10px}
    .wot-logo-preview img{max-height:42px;max-width:200px;display:block}
  </style>

  <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data" class="set-section set-section--grid" data-dirty-form>
    @csrf @method('PATCH') {{-- MARKER-PATCH-316 --}}
    <input type="hidden" name="tab" value="tags">

    <div class="set-savebar" data-savebar>
      <span class="set-savebar-msg"></span>
      <div class="set-savebar-actions">
        <button type="button" class="set-discard-btn" data-discard>Discard</button>
        <button type="submit" class="set-save-btn">Save tag settings</button>
      </div>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <label class="wot-row" style="border:none;padding:2px 0">
        <span class="wot-row-l">
          <span class="t">Print service tags</span>
          <span class="d">Hang a tag on each item at drop-off. Prints to your 80mm receipt printer.</span>
        </span>
        <input type="checkbox" name="wot_enabled" value="1" {{ $wotOn('enabled') ? 'checked' : '' }} class="wot-switch">
      </label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">What prints on the tag</span></div>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Shop name / logo header</span></span><input type="checkbox" name="wot_show_header" value="1" {{ $wotOn('show_header') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Customer phone</span></span><input type="checkbox" name="wot_show_phone" value="1" {{ $wotOn('show_phone') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Item / asset description</span></span><input type="checkbox" name="wot_show_bike" value="1" {{ $wotOn('show_bike') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Requested services</span></span><input type="checkbox" name="wot_show_services" value="1" {{ $wotOn('show_services') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Intake note</span></span><input type="checkbox" name="wot_show_note" value="1" {{ $wotOn('show_note') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">QR code (links to the job)</span></span><input type="checkbox" name="wot_show_qr" value="1" {{ $wotOn('show_qr') ? 'checked' : '' }} class="wot-switch"></label>
      <label class="wot-row"><span class="wot-row-l"><span class="t">Tear-off customer claim stub</span></span><input type="checkbox" name="wot_show_stub" value="1" {{ $wotOn('show_stub') ? 'checked' : '' }} class="wot-switch"></label>
    </div>

    <div class="ia-card" style="margin-bottom:20px">
      <div class="ia-card-head"><span class="ia-card-title">Defaults</span></div>
      <div class="ia-input-grid-2">
        <div class="ia-form-group">
          <label class="ia-form-label">Default &ldquo;promised by&rdquo;</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_lead_days" value="{{ $wotLead }}" min="0" max="30" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">business days after drop-off</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Prefilled on new jobs; editable per work order.</div>
        </div>
        <div class="ia-form-group">
          <label class="ia-form-label">Paper width</label>
          <div class="wot-seg">
            <label><input type="radio" name="wot_paper" value="80mm" {{ $wotPaper === '80mm' ? 'checked' : '' }}><span>80mm</span></label>
            <label><input type="radio" name="wot_paper" value="58mm" {{ $wotPaper === '58mm' ? 'checked' : '' }}><span>58mm</span></label>
          </div>
        </div>
        {{-- MARKER-PATCH-320 --}}
        <div class="ia-form-group">
          <label class="ia-form-label">Extra paper after cut</label>
          <div style="display:flex;align-items:center;gap:8px">
            <input type="number" name="wot_feed_mm" value="{{ $wotFeed }}" min="0" max="40" class="ia-input" style="width:84px">
            <span style="font-size:13px;color:var(--ia-muted)">mm of feed so it clears the cutter</span>
          </div>
          <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Try 10&ndash;15mm if the last line cuts too close.</div>
        </div>
      </div>
    </div>

    {{-- MARKER-PATCH-330 --}}
    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Header &amp; footer</span></div>
      <div class="ia-form-group">
        <label class="ia-form-label">Header lines</label>
        <textarea name="wot_header_text" rows="2" class="ia-input" placeholder="e.g. 509-555-1234&#10;Mon–Fri 9–6" style="resize:vertical">{{ $wotHeader }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Shown under your logo on tags, receipts &amp; slips. One per line.</div>
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Footer message</label>
        <textarea name="wot_footer_text" rows="2" class="ia-input" placeholder="e.g. Thanks for riding with us!" style="resize:vertical">{{ $wotFooter }}</textarea>
        <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">Printed at the bottom. Leave blank for the default.</div>
      </div>
    </div>

    <div class="ia-card">
      <div class="ia-card-head"><span class="ia-card-title">Logo</span></div>
      @if($wotLogo)
        <div class="wot-logo-preview"><img src="{{ asset('storage/' . ltrim($wotLogo, '/')) }}" alt="Tag logo"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ia-muted);margin-bottom:12px;cursor:pointer">
          <input type="checkbox" name="wot_logo_remove" value="1"> Remove current logo
        </label>
      @endif
      {{-- MARKER-PATCH-317 --}}
      <div class="ia-form-group" style="margin-bottom:12px;max-width:240px">
        <label class="ia-form-label">Logo size on tag</label>
        @php $wls = $wot['logo_size'] ?? 'medium'; @endphp
        <select name="wot_logo_size" class="ia-input">
          <option value="small"  {{ $wls === 'small'  ? 'selected' : '' }}>Small</option>
          <option value="medium" {{ $wls === 'medium' ? 'selected' : '' }}>Medium</option>
          <option value="large"  {{ $wls === 'large'  ? 'selected' : '' }}>Large</option>
          <option value="xl"     {{ $wls === 'xl'     ? 'selected' : '' }}>Extra large</option>
        </select>
      </div>
      <input type="file" name="wot_logo" accept="image/png,image/jpeg,image/webp" class="ia-input">
      <div class="ia-form-hint" style="font-size:11.5px;color:var(--ia-muted);margin-top:6px">High-contrast black-on-white prints best on thermal. Shown at the top of each tag in place of the shop name.</div>
    </div>

  </form>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
(function() {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  /* -----------------------------------------------------------------------
   * Tab switching (no URL params)
   * ----------------------------------------------------------------------- */
  function switchTab(name) {
    document.querySelectorAll('.set-tab').forEach(function(t) {
      t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.set-pane').forEach(function(p) {
      p.classList.toggle('active', p.id === 'pane-' + name);
    });
    // Reset window scroll so a long pane doesn't start mid-page
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
  document.querySelectorAll('.set-tab').forEach(function(t) {
    t.addEventListener('click', function() { switchTab(t.dataset.tab); });
  });

  /* -----------------------------------------------------------------------
   * Dirty tracking — per form, save bar dims when no changes
   * ----------------------------------------------------------------------- */
  // MARKER-PATCH-166 — savebar shows ONLY the unsaved-changes warning.
  // Save confirmation lives in the top flash banner (one source of truth).
  document.querySelectorAll('[data-dirty-form]').forEach(function(form) {
    var savebar = form.querySelector('[data-savebar]');
    var msg     = savebar ? savebar.querySelector('.set-savebar-msg') : null;
    var initial = serialize(form);

    function serialize(f) {
      // For dirty tracking we build a stable string from the form's editable
      // values. File inputs and password fields with placeholder dots can't
      // be reliably serialized, so we only mark dirty on text/select/hidden
      // changes — any user interaction is enough to flip the bar.
      var parts = [];
      Array.from(f.elements).forEach(function(el) {
        if (!el.name) return;
        if (el.type === 'file') {
          if (el.files && el.files.length) parts.push(el.name + '=FILE');
          return;
        }
        if (el.type === 'checkbox' || el.type === 'radio') {
          parts.push(el.name + '=' + (el.checked ? '1' : '0') + '|' + (el.value || ''));
          return;
        }
        parts.push(el.name + '=' + (el.value || ''));
      });
      return parts.join('&');
    }

    function checkDirty() {
      var nowSerialized = serialize(form);
      var dirty = nowSerialized !== initial;
      if (savebar) {
        savebar.classList.toggle('dirty', dirty);
        // MARKER-PATCH-166 — savebar shows the warning only.
        // Save confirmation is handled by the global flash banner at the top
        // (layouts/tenant/app.blade.php). Dual confirmation was confusing.
        if (msg) {
          msg.textContent = dirty ? 'You have unsaved changes.' : '';
        }
      }
    }

    // Initial paint
    checkDirty();

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);

    // Discard: reload the page (server-rendered, so this resets to saved state)
    var discardBtn = form.querySelector('[data-discard]');
    if (discardBtn) {
      discardBtn.addEventListener('click', function() {
        if (confirm('Discard your unsaved changes?')) {
          window.location.reload();
        }
      });
    }
  });

  /* -----------------------------------------------------------------------
   * Generic "ia-toggle bound to hidden input" pattern. Used on:
   *   - Business: classes_enabled, tax_services_default, tax_supports_exempt
   *   - Communication: sms_enabled, notify_booking_confirmation_email/sms
   *
   * Clicking the toggle flips both the visual class and the hidden input's
   * value, then dispatches a 'change' on the input so dirty tracking runs.
   * ----------------------------------------------------------------------- */
  function bindToggle(btnId, inputId) {
    var btn   = document.getElementById(btnId);
    var input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', function() {
      if (btn.disabled) return;
      var on = !btn.classList.contains('on');
      btn.classList.toggle('on', on);
      input.value = on ? '1' : '0';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }
  bindToggle('classes-toggle-btn',          'classes_enabled_input');
  // MARKER-PATCH-156
  bindToggle('deliveries-toggle-btn',       'deliveries_enabled_input');
  // MARKER-PATCH-158-B
  bindToggle('multi-asset-toggle-btn',      'multi_asset_enabled_input');
  bindToggle('tax-services-toggle-btn',     'tax_services_default_input');
  bindToggle('tax-exempt-toggle-btn',       'tax_supports_exempt_input');
  // notify toggles removed — patch-406 (moved to Communication Center)

  /* -----------------------------------------------------------------------
   * Branding: color picker text/swatch sync
   * ----------------------------------------------------------------------- */
  document.querySelectorAll('input[type=color]').forEach(function(picker) {
    var textId = picker.id.replace('color-', 'text-');
    var text   = document.getElementById(textId);
    if (text) picker.addEventListener('input', function() { text.value = picker.value; });
  });

  /* -----------------------------------------------------------------------
   * Drop-off methods CRUD (preserved verbatim from the previous settings
   * page — endpoints unchanged, just wrapped in the new tab structure).
   * ----------------------------------------------------------------------- */
  var list = document.getElementById('method-list');

  // Add new method
  var addForm = document.getElementById('add-method-form');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
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
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
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
      onEnd: function() {
        var ids = Array.from(list.querySelectorAll('.method-row'))
                       .map(function(r) { return r.getAttribute('data-method-id'); });
        fetch("{{ route('tenant.receiving-methods.reorder') }}", {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          body: JSON.stringify({ order: ids }),
        }).then(function(r) {
          // MARKER-PATCH-248
          if (r.ok) { if (window.IntakeToast) IntakeToast.success('Order saved'); }
          else { if (window.IntakeToast) IntakeToast.error('Could not save the new order'); }
        }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save the new order — check your connection'); });
      }
    });
  }

  // Inline edit on blur (text) / change (checkbox)
  document.querySelectorAll('.method-edit, .method-edit-toggle').forEach(function(el) {
    var evt = el.type === 'checkbox' ? 'change' : 'blur';
    el.addEventListener(evt, function() {
      var row = el.closest('.method-row');
      var id  = row.getAttribute('data-method-id');
      var field = el.getAttribute('data-field');
      var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      var body = {};
      body[field] = value;
      fetch("{{ url('admin/receiving-methods') }}/" + id, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        // MARKER-PATCH-248 — saves speak.
        if (r.ok) { if (window.IntakeToast) IntakeToast.success('Saved'); }
        else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not save — try again');
        }
      }).catch(function() { if (window.IntakeToast) IntakeToast.error('Could not save — check your connection'); });
    });
  });

  // Active toggle
  document.querySelectorAll('.method-row-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
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
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      }).then(function(r) {
        btn.classList.remove('is-busy');
        if (r.ok) {
          btn.classList.toggle('on', newVal);
          row.style.opacity = newVal ? '' : '.45';
          btn.setAttribute('title', newVal ? 'Click to deactivate' : 'Click to activate');
          btn.querySelector('.ia-toggle-sr').textContent = newVal ? 'Active' : 'Inactive';
        } else {
          row.style.outline = '1px solid #d04444';
          setTimeout(function() { row.style.outline = ''; }, 1500);
          if (window.IntakeToast) IntakeToast.error('Could not update — try again'); // MARKER-PATCH-248
        }
      }).catch(function() {
        btn.classList.remove('is-busy');
        if (window.IntakeToast) IntakeToast.error('Could not update — check your connection'); // MARKER-PATCH-248
      });
    });
  });

  /* -----------------------------------------------------------------------
   * SMS test send
   * ----------------------------------------------------------------------- */
  var smsTestBtn    = document.getElementById('sms-test-btn');
  var smsTestTo     = document.getElementById('sms_test_to');
  var smsTestStatus = document.getElementById('sms-test-status');

  if (smsTestBtn && smsTestTo && smsTestStatus) {
    smsTestBtn.addEventListener('click', function() {
      var to = smsTestTo.value.trim();
      if (!to) {
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Enter a phone number first.';
        return;
      }
      smsTestStatus.className = 'sms-test-status';
      smsTestStatus.textContent = '';
      smsTestBtn.disabled = true;
      smsTestBtn.textContent = 'Sending…';

      fetch("{{ route('tenant.settings.test-sms') }}", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ to: to }),
      }).then(function(r) {
        return r.json().then(function(d) { return { ok: r.ok, body: d }; });
      }).then(function(res) {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        if (res.ok && res.body.ok) {
          smsTestStatus.className = 'sms-test-status success';
          smsTestStatus.textContent = res.body.message || 'Test sent.';
        } else {
          smsTestStatus.className = 'sms-test-status error';
          smsTestStatus.textContent = (res.body && res.body.error) || 'Send failed.';
        }
      }).catch(function() {
        smsTestBtn.disabled = false;
        smsTestBtn.textContent = 'Send test';
        smsTestStatus.className = 'sms-test-status error';
        smsTestStatus.textContent = 'Network error.';
      });
    });
  }

  /* -----------------------------------------------------------------------
   * Logo size sliders — live preview chip resize
   *
   * Slider input dispatches 'input' on every drag tick. We mutate the
   * preview img's height directly. The slider itself is a normal form input
   * so dirty tracking + save bar fire automatically.
   * ----------------------------------------------------------------------- */
  function bindLogoSlider(sliderId, readoutId, previewId) {
    var slider  = document.getElementById(sliderId);
    var readout = document.getElementById(readoutId);
    var preview = document.getElementById(previewId);
    if (!slider) return;
    slider.addEventListener('input', function() {
      var v = parseInt(slider.value, 10) || 16;
      if (readout) readout.textContent = v;
      if (preview) preview.style.height = v + 'px';
    });
  }
  bindLogoSlider('logo-admin-slider',   'logo-admin-readout',   'logo-admin-preview');
  bindLogoSlider('logo-booking-slider', 'logo-booking-readout', 'logo-booking-preview');

})();

/* -----------------------------------------------------------------------
 * Provider toggle (Stripe / PayPal) — needs to be global because the
 * onclick attribute references it from inline. Preserved from old page.
 * ----------------------------------------------------------------------- */
function toggleProvider(name) {
  var card     = document.getElementById(name + '-card');
  var toggle   = document.getElementById(name + '-toggle');
  var valInput = document.getElementById(name + '-enabled-val');
  var enabled  = toggle.classList.toggle('on');
  card.classList.toggle('enabled', enabled);
  valInput.value = enabled ? '1' : '0';
  // Trigger dirty tracking on the parent form
  valInput.dispatchEvent(new Event('change', { bubbles: true }));
}
</script>
@endpush

