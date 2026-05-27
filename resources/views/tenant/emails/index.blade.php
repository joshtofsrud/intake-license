@extends('layouts.tenant.app')
@php $pageTitle = 'Email templates'; @endphp

@push('styles')
<style>
.em-grid { display: grid; grid-template-columns: 260px 1fr; gap: 20px; align-items: start; }
.em-sidebar { display: flex; flex-direction: column; gap: 4px; }
.em-type-btn {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 12px 14px;
  border-radius: var(--ia-r-md);
  cursor: pointer;
  border: 0.5px solid transparent;
  transition: all .12s;
  text-align: left;
  background: transparent;
  color: var(--ia-text);
  width: 100%;
}
.em-type-btn:hover { background: var(--ia-hover); }
.em-type-btn.active { background: var(--ia-accent-soft); border-color: var(--ia-accent); }
.em-type-label { font-size: 13px; font-weight: 500; }
.em-type-desc  { font-size: 11px; opacity: .45; line-height: 1.4; }
.em-type-badge { font-size: 10px; padding: 2px 7px; border-radius: 4px; font-weight: 600; display: inline-block; margin-top: 4px; width: fit-content; }
.em-type-badge.custom  { background: var(--ia-accent-soft); color: var(--ia-text); }
.em-type-badge.default { background: var(--ia-hover); color: var(--ia-text-muted); }
.em-editor { display: none; }
.em-editor.active { display: block; }
.em-vars {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 16px;
  padding: 12px 14px;
  background: var(--ia-surface-2);
  border-radius: var(--ia-r-md);
}
.em-var {
  font-size: 11px;
  font-family: var(--ia-font-mono);
  background: var(--ia-surface);
  border: 0.5px solid var(--ia-border);
  border-radius: 4px;
  padding: 3px 8px;
  cursor: pointer;
  color: var(--ia-text-muted);
  transition: all .1s;
}
.em-var:hover { border-color: var(--ia-accent); color: var(--ia-text); }
.em-preview-wrap {
  border: 0.5px solid var(--ia-border);
  border-radius: var(--ia-r-md);
  overflow: hidden;
  margin-top: 16px;
}
.em-preview-bar {
  background: var(--ia-surface-2);
  padding: 8px 14px;
  font-size: 11px;
  opacity: .5;
  border-bottom: 0.5px solid var(--ia-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.em-preview-iframe {
  width: 100%;
  height: 480px;
  border: none;
  background: white;
}
@media (max-width: 860px) {
  .em-grid { grid-template-columns: 1fr; }
  .em-sidebar { flex-direction: row; flex-wrap: wrap; }
  .em-type-btn { flex: 1; min-width: 200px; }
}
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Email templates</h1>
    <p class="ia-page-subtitle">Customize the emails your customers receive.</p>
  </div>
</div>

{{-- MARKER-PATCH-161 — Receipt automation toggle card --}}
<form method="POST" action="{{ route('tenant.emails.settings.update') }}"
      class="ia-card" style="margin-bottom:24px;padding:20px">
  @csrf @method('PATCH')

  <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px">
    <h2 style="font-size:16px;font-weight:600;margin:0">Receipt automation</h2>
    <span style="font-size:11px;opacity:.45;text-transform:uppercase;letter-spacing:.07em">When &amp; how receipts go out</span>
  </div>
  <p style="font-size:13px;opacity:.55;margin:0 0 18px;line-height:1.55">
    These settings apply to all receipts you send. Cashiers can still skip individual receipts at the register.
  </p>

  <div style="display:flex;flex-direction:column;gap:14px">

    {{-- Sale receipts --}}
    <label style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;cursor:pointer;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md)">
      <div style="min-width:0">
        <div style="font-size:13px;font-weight:500">Auto-send on completed sale</div>
        <div style="font-size:11.5px;opacity:.5;margin-top:3px;line-height:1.5">
          Send the customer a receipt as soon as a POS transaction completes. Customers can opt out per sale at the register.
        </div>
      </div>
      <input type="checkbox" name="notify_sale_receipt_email" value="1"
        {{ $receiptSettings['notify_sale_receipt_email'] ? 'checked' : '' }}
        style="width:18px;height:18px;accent-color:var(--ia-accent);flex-shrink:0;margin-top:2px">
    </label>

    {{-- Appointment receipts --}}
    <label style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;cursor:pointer;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md)">
      <div style="min-width:0">
        <div style="font-size:13px;font-weight:500">Auto-send on appointment completion</div>
        <div style="font-size:11.5px;opacity:.5;margin-top:3px;line-height:1.5">
          Send a work-order receipt when an appointment moves into one of the trigger statuses below.
        </div>
      </div>
      <input type="checkbox" name="notify_appointment_receipt_email" value="1"
        {{ $receiptSettings['notify_appointment_receipt_email'] ? 'checked' : '' }}
        style="width:18px;height:18px;accent-color:var(--ia-accent);flex-shrink:0;margin-top:2px">
    </label>

    {{-- Trigger states (nested under appointment receipt) --}}
    <div style="padding:0 12px 12px 36px">
      <div style="font-size:11.5px;opacity:.55;margin-bottom:8px">Send appointment receipts when status becomes:</div>
      <div style="display:flex;gap:18px;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
          <input type="checkbox" name="receipt_appointment_trigger_states[]" value="completed"
            checked disabled
            style="width:14px;height:14px;accent-color:var(--ia-accent);opacity:.6">
          <span>Completed <span style="opacity:.5;font-size:11px">(always)</span></span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="receipt_appointment_trigger_states[]" value="shipped"
            {{ in_array('shipped', $receiptSettings['receipt_appointment_trigger_states']) ? 'checked' : '' }}
            style="width:14px;height:14px;accent-color:var(--ia-accent)">
          <span>Shipped</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" name="receipt_appointment_trigger_states[]" value="closed"
            {{ in_array('closed', $receiptSettings['receipt_appointment_trigger_states']) ? 'checked' : '' }}
            style="width:14px;height:14px;accent-color:var(--ia-accent)">
          <span>Closed</span>
        </label>
      </div>
      <p style="font-size:11px;opacity:.4;margin:8px 0 0;line-height:1.5">
        Shipped and Closed only apply if you've enabled those states in your appointment workflow. Receipts only fire once per appointment per state.
      </p>
    </div>

    {{-- Open tracking --}}
    <label style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;cursor:pointer;padding:12px;border:0.5px solid var(--ia-border);border-radius:var(--ia-r-md)">
      <div style="min-width:0">
        <div style="font-size:13px;font-weight:500">Track opens</div>
        <div style="font-size:11.5px;opacity:.5;margin-top:3px;line-height:1.5">
          Includes a 1×1 transparent pixel so you can see when a receipt is opened. Some customers prefer this off.
        </div>
      </div>
      <input type="checkbox" name="email_track_opens" value="1"
        {{ $receiptSettings['email_track_opens'] ? 'checked' : '' }}
        style="width:18px;height:18px;accent-color:var(--ia-accent);flex-shrink:0;margin-top:2px">
    </label>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-top:18px">
    <button type="submit" class="ia-btn ia-btn--primary">Save automation settings</button>
  </div>
</form>

<div class="em-grid">

  {{-- Sidebar: template type selector --}}
  <div class="em-sidebar">
    @foreach($types as $key => $type)
      <button type="button" class="em-type-btn {{ $loop->first ? 'active' : '' }}"
        onclick="showEditor('{{ $key }}')" id="em-btn-{{ $key }}">
        <span class="em-type-label">{{ $type['label'] }}</span>
        <span class="em-type-desc">{{ $type['desc'] }}</span>
        <span class="em-type-badge {{ $type['is_custom'] ? 'custom' : 'default' }}"
          id="em-badge-{{ $key }}">
          {{ $type['is_custom'] ? 'Custom' : 'Default' }}
        </span>
      </button>
    @endforeach

    <div class="ia-card ia-card--tight" style="margin-top:12px">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:500;opacity:.4;margin-bottom:8px">
        Available variables
      </div>
      <p style="font-size:12px;opacity:.5;line-height:1.55">
        Click a variable below to copy it. Paste it into your subject or body — it'll be replaced with the real value when the email is sent.
      </p>
    </div>
  </div>

  {{-- Right: template editors --}}
  <div>
    @foreach($types as $key => $type)
      <div class="em-editor {{ $loop->first ? 'active' : '' }}" id="em-editor-{{ $key }}">

        <div class="ia-card">
          <div class="ia-card-head">
            <span class="ia-card-title">{{ $type['label'] }}</span>
            @if($type['is_custom'])
              <form method="POST" action="{{ route('tenant.emails.update', $key) }}" style="display:inline">
                @csrf @method('PATCH')
                <input type="hidden" name="op" value="reset">
                <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm"
                  data-confirm="Reset to default template?">Reset to default</button>
              </form>
            @endif
          </div>

          {{-- Variable chips --}}
          <div style="font-size:11px;opacity:.4;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">
            Variables
          </div>
          @php($OBR = str_repeat(chr(123), 2))
          @php($CBR = str_repeat(chr(125), 2))
          <div class="em-vars" id="em-vars-{{ $key }}">
            @foreach($type['vars'] as $var)
              @php($token = $OBR . $var . $CBR)
              <span class="em-var" onclick="insertVar('{{ $key }}', @js($token))"
                title="Click to copy">{{ $token }}</span>
            @endforeach
          </div>

          <form method="POST" action="{{ route('tenant.emails.update', $key) }}"
            id="em-form-{{ $key }}">
            @csrf @method('PATCH')
            <input type="hidden" name="op" value="save">

            @php($defaultSubject = $type['_default_subject'] ?? ('Your booking is confirmed — ' . str_repeat(chr(123),2) . 'ra_number' . str_repeat(chr(125),2)))
            <div class="ia-form-group">
              <label class="ia-form-label">Subject line <span class="ia-required">*</span></label>
              <input type="text" name="subject" id="em-subject-{{ $key }}" class="ia-input"
                value="{{ old('subject', $type['subject']) ?: ($type['_default_subject'] ?? '') }}"
                placeholder="{{ $defaultSubject }}"
                required>
            </div>

            <div class="ia-form-group">
              <label class="ia-form-label">Body (HTML supported)</label>
              <textarea name="body" id="em-body-{{ $key }}" class="ia-input"
                style="min-height:220px;font-family:var(--ia-font-mono);font-size:12px;resize:vertical"
                rows="12">{{ old('body', $type['body']) }}</textarea>
            </div>

            <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="is_active" value="1"
                  {{ $type['is_active'] ? 'checked' : '' }}
                  style="width:16px;height:16px;accent-color:var(--ia-accent)">
                Send this email automatically
              </label>
            </div>

            <div style="display:flex;gap:10px;align-items:center">
              <button type="submit" class="ia-btn ia-btn--primary">Save template</button>
              <button type="button" class="ia-btn ia-btn--ghost"
                onclick="previewTemplate('{{ $key }}')">
                Preview
              </button>
            </div>
          </form>
        </div>

        {{-- Preview panel (hidden until Preview clicked) --}}
        <div class="em-preview-wrap" id="em-preview-{{ $key }}" style="display:none">
          <div class="em-preview-bar">
            <span>Preview — sample data</span>
            <button onclick="document.getElementById('em-preview-{{ $key }}').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:16px;opacity:.5">×</button>
          </div>
          <iframe class="em-preview-iframe" id="em-iframe-{{ $key }}" sandbox="allow-same-origin"></iframe>
        </div>

      </div>
    @endforeach
  </div>

</div>

@endsection

@push('scripts')
<script>

// Tenant values injected from Blade (safe, no nested braces)
const SHOP_NAME    = @js($currentTenant->name);
const ACCENT_COLOR = @js($currentTenant->accent_color ?? '#BEF264');

function showEditor(key) {
  document.querySelectorAll('.em-editor').forEach(function(e) { e.classList.remove('active'); });
  document.querySelectorAll('.em-type-btn').forEach(function(b) { b.classList.remove('active'); });
  document.getElementById('em-editor-' + key).classList.add('active');
  document.getElementById('em-btn-' + key).classList.add('active');
}

function insertVar(editorKey, varStr) {
  var subject = document.getElementById('em-subject-' + editorKey);
  var body    = document.getElementById('em-body-' + editorKey);
  var last    = window._emLastFocus;
  var target  = (last === subject || last === body) ? last : body;
  if (!target) return;
  var start = target.selectionStart, end = target.selectionEnd;
  target.value = target.value.substring(0, start) + varStr + target.value.substring(end);
  target.selectionStart = target.selectionEnd = start + varStr.length;
  target.focus();
}

document.addEventListener('focusin', function(e) {
  if (e.target && e.target.id && /^em-(subject|body)-/.test(e.target.id)) {
    window._emLastFocus = e.target;
  }
});

function previewTemplate(key) {
  var subject  = document.getElementById('em-subject-' + key)?.value || '';
  var body     = document.getElementById('em-body-' + key)?.value || '';
  var preview  = document.getElementById('em-preview-' + key);
  var iframe   = document.getElementById('em-iframe-' + key);

  // Substitute sample vars. The @ prefix on keys below tells Blade to output them literally to the browser.
  var sampleVars = {
    '@{{first_name}}':       'Jane',
    '@{{ra_number}}':        'SPK-A3F9B2',
    '@{{appointment_date}}': 'Thursday, November 14, 2024',
    '@{{total}}':            '$185.00',
    '@{{status}}':           'Completed',
    '@{{status_note}}':      'Your bike is ready for pickup.',
    '@{{name}}':             'Jane Smith',
    '@{{shop_name}}':        SHOP_NAME,
    '@{{reset_url}}':        '#',
    '@{{accent}}':           ACCENT_COLOR,
    '@{{accent_text}}':      '#0a0a0a',
  };

  var rendered = body;
  Object.keys(sampleVars).forEach(function(k) {
    rendered = rendered.split(k).join(sampleVars[k]);
  });

  var html = '<!DOCTYPE html><html><body style="margin:0;padding:16px;background:#f4f4f2;font-family:-apple-system,sans-serif">'
    + '<p style="font-size:11px;color:#888;margin:0 0 8px;text-transform:uppercase;letter-spacing:.07em">Subject: ' + subject + '</p>'
    + '<div style="background:#fff;border-radius:8px;padding:24px;border:1px solid #e8e8e4;font-size:14px;line-height:1.7;color:#111">'
    + (rendered || '<em style="color:#aaa">No body content yet.</em>')
    + '</div></body></html>';

  if (preview) preview.style.display = '';
  if (iframe) {
    var doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open(); doc.write(html); doc.close();
  }
}
</script>
@endpush
