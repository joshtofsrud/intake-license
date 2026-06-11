@extends('layouts.tenant.app')
@php $pageTitle = 'Messaging'; @endphp

{{-- MARKER-PATCH-224 — Settings -> Messaging: Intake-managed numbers first,
     BYO Twilio as the advanced path. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Messaging</h1>
    <p class="ia-page-subtitle">Your business text number — confirmations, reminders, and two-way conversations in the Inbox.</p>
  </div>
  <a href="{{ route('tenant.settings.index') }}" class="ia-btn">All settings</a>
</div>

@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>
@endif
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

{{-- ============================================================ status --}}
<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head" style="display:flex;align-items:center;justify-content:space-between">
    <span class="ia-card-title">Status</span>
    @if($mode === 'managed')
      <span class="ia-badge ia-badge--paid">Active · Intake-managed</span>
    @elseif($mode === 'byo')
      <span class="ia-badge ia-badge--confirmed">Active · Your Twilio account</span>
    @else
      <span class="ia-badge ia-badge--unpaid">No number yet</span>
    @endif
  </div>
  @if($mode !== 'none')
    <div style="font-size:20px;font-weight:700;font-variant-numeric:tabular-nums">{{ tenant()->sms_from_number }}</div>
    <p style="font-size:12.5px;opacity:.55;margin-top:6px">
      Customers can text this number — replies land in your <a href="{{ route('tenant.inbox.index') }}">Inbox</a>.
      {{ tenant()->sms_enabled ? '' : 'Sending is currently toggled OFF.' }}
    </p>

    <div style="margin-top:14px;padding-top:14px;border-top:.5px solid var(--ia-border)">
      <div style="font-size:13px;font-weight:500;margin-bottom:8px">Send a test message</div>
      <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <input type="tel" id="msg-test-to" class="ia-input" placeholder="+15555551234" style="flex:1;min-width:200px">
        <button type="button" id="msg-test-btn" class="ia-btn">Send test</button>
      </div>
      <div id="msg-test-status" style="display:none;margin-top:8px;font-size:12.5px;padding:8px 10px;border-radius:8px"></div>
    </div>
  @else
    <p style="font-size:13px;opacity:.6;line-height:1.55">
      Claim a number below and Intake handles the rest — the number, the carrier plumbing, and routing replies
      into your Inbox. No Twilio account needed.
    </p>
  @endif
</div>

{{-- ============================================================ claim --}}
@if($mode === 'none')
<div class="ia-card" style="margin-bottom:20px">
  <div class="ia-card-head"><span class="ia-card-title">Get your text number</span></div>

  @if(!$hasSmsAddon)
    <p style="font-size:13px;opacity:.6;line-height:1.55">
      Text messaging requires the <strong>SMS notifications</strong> add-on. Add it from your plan &amp; add-ons page, then claim a number here.
    </p>
  @elseif(!$platformReady)
    <p style="font-size:13px;opacity:.6;line-height:1.55">
      Number provisioning is being set up — check back shortly.
    </p>
  @else
    <p style="font-size:12.5px;opacity:.55;margin-bottom:12px;line-height:1.5">
      <strong>Toll-free is recommended</strong> — it's verified for business texting and delivers reliably.
      Local numbers are available by area code.
    </p>
    <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:12px">
      <div>
        <label class="ia-label" style="display:block;margin-bottom:5px">Type</label>
        <select id="msg-search-type" class="ia-input">
          <option value="tollfree">Toll-free (recommended)</option>
          <option value="local">Local (by area code)</option>
        </select>
      </div>
      <div id="msg-area-wrap" style="display:none">
        <label class="ia-label" style="display:block;margin-bottom:5px">Area code</label>
        <input type="text" id="msg-area-code" maxlength="3" placeholder="509" class="ia-input" style="width:90px">
      </div>
      <button type="button" id="msg-search-btn" class="ia-btn ia-btn--primary">Find numbers</button>
    </div>
    <div id="msg-results"></div>
    <form method="POST" action="{{ route('tenant.settings.messaging.claim') }}" id="msg-claim-form" style="display:none">
      @csrf
      <input type="hidden" name="number" id="msg-claim-number">
    </form>
  @endif
</div>
@endif

{{-- ============================================================ BYO --}}
<details class="ia-card" style="margin-bottom:20px" {{ $mode === 'byo' ? 'open' : '' }}>
  <summary style="cursor:pointer;font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em">Advanced — bring your own Twilio</summary>
  @if(tenant()->twilio_number_sid)
    <p style="font-size:12.5px;opacity:.55;margin-top:10px">This business uses an Intake-managed number, so these fields are locked. Contact support to switch.</p>
  @else
  <p style="font-size:12.5px;opacity:.55;margin-top:10px;line-height:1.5">
    For shops that already run their own Twilio account. After saving, click <em>Sync inbound webhook</em> so customer
    replies route into your Inbox (it points your number at <code style="font-size:11px">{{ $inboundUrl }}</code>).
  </p>
  <form method="POST" action="{{ route('tenant.settings.messaging.byo') }}" style="margin-top:12px">
    @csrf
    <div class="ia-input-grid-2">
      <div class="ia-form-group">
        <label class="ia-form-label">From phone number</label>
        <input type="text" name="sms_from_number" class="ia-input" value="{{ old('sms_from_number', tenant()->sms_from_number) }}" placeholder="+15555551234">
      </div>
      <div class="ia-form-group">
        <label class="ia-form-label">Account SID</label>
        <input type="text" name="twilio_account_sid" class="ia-input" value="{{ old('twilio_account_sid', tenant()->twilio_account_sid) }}" placeholder="AC...">
      </div>
      <div class="ia-form-group" style="grid-column:1 / -1">
        <label class="ia-form-label">Auth token</label>
        <input type="password" name="twilio_auth_token" class="ia-input" value="" placeholder="{{ $hasTwilioToken ? '•••••••• (saved — leave blank to keep)' : 'Enter your auth token' }}">
      </div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:10px 0">
      <input type="checkbox" name="sms_enabled" value="1" {{ tenant()->sms_enabled ? 'checked' : '' }}> Sending enabled
    </label>
    <div style="display:flex;gap:8px">
      <button type="submit" class="ia-btn ia-btn--primary">Save</button>
    </div>
  </form>
  @if($mode === 'byo')
  <form method="POST" action="{{ route('tenant.settings.messaging.sync') }}" style="margin-top:8px">
    @csrf
    <button type="submit" class="ia-btn">Sync inbound webhook</button>
  </form>
  @endif
  @endif
</details>

<p style="font-size:11.5px;opacity:.45;line-height:1.55">
  Carrier compliance: business texting in the US requires registration (toll-free verification or 10DLC).
  Intake-managed toll-free numbers are covered under Intake's verification; bring-your-own numbers must be
  verified on your own Twilio account or carriers may filter your messages.
</p>

<script>
(function () {
  var csrf = '{{ csrf_token() }}';

  // ---------------- claim flow
  var typeEl = document.getElementById('msg-search-type');
  if (typeEl) {
    var areaWrap = document.getElementById('msg-area-wrap');
    typeEl.addEventListener('change', function () {
      areaWrap.style.display = typeEl.value === 'local' ? 'block' : 'none';
    });

    document.getElementById('msg-search-btn').addEventListener('click', function () {
      var btn = this;
      var results = document.getElementById('msg-results');
      btn.disabled = true;
      results.innerHTML = '<p style="font-size:12.5px;opacity:.55">Searching…</p>';
      fetch('{{ route('tenant.settings.messaging.search') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ type: typeEl.value, area_code: (document.getElementById('msg-area-code') || {}).value || null })
      }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
        .then(function (res) {
          btn.disabled = false;
          if (!res.ok || !res.json.ok) {
            results.innerHTML = '<p style="font-size:12.5px;color:#A32D2D">' + ((res.json && res.json.error) || 'Search failed.') + '</p>';
            return;
          }
          var nums = res.json.numbers || [];
          if (!nums.length) { results.innerHTML = '<p style="font-size:12.5px;opacity:.6">No numbers found — try another search.</p>'; return; }
          results.innerHTML = nums.map(function (n) {
            return '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:.5px solid var(--ia-border)">' +
              '<span style="font-size:14px;font-weight:600;font-variant-numeric:tabular-nums">' + n.number +
              (n.locality ? ' <span style="font-size:11.5px;opacity:.5;font-weight:400">' + n.locality + '</span>' : '') + '</span>' +
              '<button type="button" class="ia-btn ia-btn--primary msg-claim" data-number="' + n.number + '">Claim</button></div>';
          }).join('');
        })
        .catch(function () { btn.disabled = false; results.innerHTML = '<p style="font-size:12.5px;color:#A32D2D">Search failed.</p>'; });
    });

    document.getElementById('msg-results').addEventListener('click', function (e) {
      var b = e.target.closest('.msg-claim');
      if (!b) return;
      if (!confirm('Claim ' + b.getAttribute('data-number') + ' as your business text number?')) return;
      document.getElementById('msg-claim-number').value = b.getAttribute('data-number');
      document.getElementById('msg-claim-form').submit();
    });
  }

  // ---------------- test send (reuses the existing settings endpoint)
  var testBtn = document.getElementById('msg-test-btn');
  if (testBtn) {
    testBtn.addEventListener('click', function () {
      var to = document.getElementById('msg-test-to').value.trim();
      var status = document.getElementById('msg-test-status');
      if (!to) { return; }
      testBtn.disabled = true;
      fetch('{{ route('tenant.settings.test-sms') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ to: to })
      }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
        .then(function (res) {
          testBtn.disabled = false;
          status.style.display = 'block';
          var good = res.ok && (res.json.ok || res.json.success);
          status.style.background = good ? 'rgba(120,200,120,.10)' : 'rgba(240,149,149,.10)';
          status.style.color = good ? '#3B6D11' : '#A32D2D';
          status.textContent = good ? 'Test sent — check your phone.' : ((res.json && (res.json.error || res.json.message)) || 'Test failed.');
        })
        .catch(function () { testBtn.disabled = false; });
    });
  }
})();
</script>

@endsection
