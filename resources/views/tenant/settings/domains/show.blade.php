{{-- MARKER-PATCH-120-PART2 — tenant domain detail (state-aware) --}}
@extends('layouts.tenant.app')

@php
  $pageTitle = $domain->hostname;
  $statusKey = $domain->status;
@endphp

@push('styles')
<style>
  .ds-page-head { padding-bottom:16px; border-bottom:1px solid var(--ia-border); margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-end; }
  .ds-crumb { font-size:11px; color:var(--ia-text-4,#555); text-transform:uppercase; letter-spacing:0.1em; margin-bottom:6px; }
  .ds-title { font-size:22px; font-weight:800; letter-spacing:-0.02em; font-family:var(--ia-font-mono,monospace); }
  .ds-sub { font-size:13px; color:var(--ia-text-3,#888); margin-top:4px; }
  .ds-sub.error { color:#F87171; }

  .ds-banner { display:flex; align-items:center; gap:14px; padding:14px 18px; border-radius:10px; margin-bottom:16px; font-size:13px; }
  .ds-banner-icon { font-size:18px; flex-shrink:0; }
  .ds-banner-msg { flex:1; line-height:1.5; }
  .ds-banner-msg strong { color:var(--ia-text); }
  .ds-banner-small { font-size:11.5px; color:var(--ia-text-3,#888); display:block; margin-top:3px; }
  .ds-banner-actions { display:flex; gap:6px; }
  .ds-banner.amber   { background:rgba(245,158,11,.10); border:1px solid rgba(245,158,11,.25); }
  .ds-banner.blue    { background:rgba(95,168,220,.10); border:1px solid rgba(95,168,220,.25); }
  .ds-banner.success { background:rgba(190,242,100,.10); border:1px solid rgba(190,242,100,.25); }
  .ds-banner.error   { background:rgba(248,113,113,.10); border:1px solid rgba(248,113,113,.25); }

  .ds-dns { width:100%; border:1px solid var(--ia-border); border-radius:10px; overflow:hidden; margin:8px 0; font-size:12px; }
  .ds-dns-row { display:grid; grid-template-columns:80px 1fr 1fr 36px; gap:10px; align-items:center; padding:11px 14px; border-bottom:1px solid var(--ia-border); }
  .ds-dns-row:last-child { border-bottom:none; }
  .ds-dns-row.head { background:rgba(255,255,255,.02); font-size:10.5px; text-transform:uppercase; letter-spacing:0.06em; color:var(--ia-text-3,#888); font-weight:700; }
  .ds-dns-mono { font-family:var(--ia-font-mono,monospace); font-size:12px; color:var(--ia-text); word-break:break-all; }
  .ds-copy-btn { background:rgba(255,255,255,.04); border:none; color:var(--ia-text-3,#888); border-radius:4px; padding:4px 6px; font-size:11px; cursor:pointer; }
  .ds-copy-btn:hover { color:var(--ia-accent,#BEF264); }

  .ds-step-list { display:flex; flex-direction:column; }
  .ds-step { display:grid; grid-template-columns:36px 1fr; gap:16px; padding:18px 0; border-bottom:1px solid var(--ia-border); }
  .ds-step:last-child { border-bottom:none; padding-bottom:0; }
  .ds-step-num { width:30px; height:30px; border-radius:50%; background:var(--ia-surface-2,#1a1a1a); border:1px solid var(--ia-border); color:var(--ia-text-3,#888); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; }
  .ds-step.done .ds-step-num { background:var(--ia-accent,#BEF264); color:#0a0a0a; border:none; }
  .ds-step.active .ds-step-num { border-color:var(--ia-accent,#BEF264); color:var(--ia-accent,#BEF264); }
  .ds-step-title { font-size:14px; font-weight:700; margin-bottom:4px; }
  .ds-step-desc { font-size:12.5px; color:var(--ia-text-3,#888); line-height:1.55; }

  @media (max-width: 640px) {
    .ds-page-head { flex-direction:column; align-items:flex-start; gap:12px; }
    .ds-dns-row { grid-template-columns:60px 1fr 32px; }
    .ds-dns-row .ds-dns-value { grid-column:1 / -1; }
  }
</style>
@endpush

@section('content')
<div class="ds-page-head">
  <div>
    <div class="ds-crumb">Settings → Domains → {{ $domain->hostname }}</div>
    <h1 class="ds-title">{{ $domain->hostname }}</h1>
    <div class="ds-sub @if($statusKey === 'error') error @endif">
      @switch($statusKey)
        @case('pending_dns')     Add DNS records to finish setup. @break
        @case('verifying')       Almost there. We're handling the rest. @break
        @case('issuing_cert')    Provisioning HTTPS certificate. @break
        @case('active')          Live and serving customers. @break
        @case('error')           Action needed — your domain is not serving customers. @break
        @case('suspended')       Suspended by platform admin. @break
        @default                 Domain status: {{ $statusKey }}.
      @endswitch
    </div>
  </div>
  <form method="POST" action="{{ route('tenant.domains.destroy', ['id' => $domain->id]) }}"
        onsubmit="return confirm('Remove {{ $domain->hostname }}? This will tear down the cert and the tenant will be back on the subdomain.');">
    @csrf
    @method('DELETE')
    <button type="submit" class="ia-btn ia-btn-secondary" style="color:#F87171;border-color:rgba(248,113,113,.3);font-size:12px;padding:6px 12px">
      Remove domain
    </button>
  </form>
</div>

{{-- ───────────── STATE-AWARE BANNER ───────────── --}}
@if($statusKey === 'pending_dns')
  <div class="ds-banner amber">
    <div class="ds-banner-icon">⏳</div>
    <div class="ds-banner-msg">
      <strong>Waiting for your DNS records.</strong>
      <span class="ds-banner-small">
        We check every 60 seconds. Most registrars take 5–15 minutes to propagate.
        @if($domain->last_check_at)
          Last checked {{ $domain->last_check_at->diffForHumans() }}.
        @endif
      </span>
    </div>
    <div class="ds-banner-actions">
      <button type="button" class="ia-btn ia-btn-secondary" id="ds-check-now" style="font-size:12px;padding:6px 12px">Check now</button>
    </div>
  </div>
@elseif($statusKey === 'verifying' || $statusKey === 'issuing_cert')
  <div class="ds-banner blue">
    <div class="ds-banner-icon">🔍</div>
    <div class="ds-banner-msg">
      <strong>
        @if($statusKey === 'verifying') Verifying ownership.
        @else Provisioning HTTPS certificate.
        @endif
      </strong>
      <span class="ds-banner-small">
        DNS records detected. We're handling the rest. This usually takes under a minute.
      </span>
    </div>
  </div>
@elseif($statusKey === 'active')
  <div class="ds-banner success">
    <div class="ds-banner-icon">✓</div>
    <div class="ds-banner-msg">
      <strong>Your domain is live.</strong>
      <span class="ds-banner-small">
        Customers can find you at {{ $domain->hostname }}.
        Cert renews automatically.
        @if($domain->activated_at)
          Activated {{ $domain->activated_at->format('M j, Y') }}.
        @endif
      </span>
    </div>
  </div>
@elseif($statusKey === 'error')
  <div class="ds-banner error">
    <div class="ds-banner-icon">⚠️</div>
    <div class="ds-banner-msg">
      <strong>We can't reach this domain anymore.</strong>
      <span class="ds-banner-small">
        @if($domain->last_error_message)
          {{ $domain->last_error_message }}
        @else
          The DNS for {{ $domain->hostname }} isn't pointing at us, or the cert validation failed.
        @endif
        @if($domain->last_check_at)
          We last checked {{ $domain->last_check_at->diffForHumans() }}.
        @endif
      </span>
    </div>
    <div class="ds-banner-actions">
      <button type="button" class="ia-btn ia-btn-secondary" id="ds-check-now" style="font-size:12px;padding:6px 12px">Retry</button>
    </div>
  </div>
@elseif($statusKey === 'suspended')
  <div class="ds-banner error">
    <div class="ds-banner-icon">⏸</div>
    <div class="ds-banner-msg">
      <strong>This domain is suspended.</strong>
      <span class="ds-banner-small">
        @if($domain->suspended_reason)
          {{ $domain->suspended_reason }}.
        @endif
        Contact support to resolve.
      </span>
    </div>
  </div>
@endif

{{-- ───────────── DNS RECORDS — 3-STEP SETUP (MARKER-PATCH-127) ───────────── --}}
@if($statusKey !== 'suspended')
  @php
    $instructionsPresentation = $statusKey === 'active' ? 'collapsed' : 'prominent';
  @endphp

  @push('styles')
  <style>
    .ds-steps { display:flex; flex-direction:column; gap:22px; }
    .ds-step-block { display:grid; grid-template-columns:30px 1fr; gap:14px; align-items:flex-start; }
    .ds-step-num { width:24px; height:24px; border-radius:50%; background:rgba(255,255,255,.04); border:1px solid var(--ia-border2,rgba(255,255,255,.14)); color:var(--ia-muted,rgba(255,255,255,.5)); display:flex; align-items:center; justify-content:center; font-family:var(--ia-font-mono,monospace); font-size:11px; font-weight:500; margin-top:1px; }
    .ds-step-title { font-size:14px; font-weight:500; margin:0 0 4px; color:var(--ia-text); }
    .ds-step-desc  { font-size:12.5px; color:var(--ia-muted,rgba(255,255,255,.55)); line-height:1.6; }
    .ds-step-desc code { font-family:var(--ia-font-mono,monospace); font-size:11.5px; background:rgba(255,255,255,.06); padding:1px 5px; border-radius:3px; color:var(--ia-text); }
    .ds-rec-note { display:grid; grid-template-columns:80px 1fr 1fr 36px; gap:10px; padding:11px 14px; background:rgba(245,158,11,.06); border-bottom:1px solid var(--ia-border); }
    .ds-rec-note:last-child { border-bottom:none; }
    .ds-rec-note-pill { font-family:var(--ia-font-mono,monospace); font-size:10.5px; padding:2px 8px; border-radius:4px; background:rgba(245,158,11,.14); color:#F59E0B; display:inline-block; height:fit-content; }
    .ds-rec-note-name, .ds-rec-note-value { font-family:var(--ia-font-mono,monospace); font-size:11.5px; color:var(--ia-muted,rgba(255,255,255,.55)); word-break:break-all; line-height:1.55; }
    .ds-rec-note-value em { font-style:normal; color:rgba(245,158,11,.85); }
  </style>
  @endpush

  @if($instructionsPresentation === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        Setup records <span style="font-weight:400;opacity:.7">— keep these in place to stay live</span>
      </summary>
      <div style="padding:18px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">Add three DNS records at your registrar</span>
      </div>
      <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:18px;line-height:1.55">
        Wherever you bought <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code> — GoDaddy, Cloudflare, Namecheap, etc. Work through the steps in order; the last record comes from Cloudflare after the first two are live.
      </p>
  @endif

      <div class="ds-steps">

        {{-- Step 1 — Ownership TXT --}}
        <div class="ds-step-block">
          <div class="ds-step-num">1</div>
          <div style="min-width:0">
            <div class="ds-step-title">Prove you own the domain</div>
            <div class="ds-step-desc">
              Add this TXT record so we can confirm you control <code>{{ $domain->hostname }}</code>.
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">TXT</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordName() }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordValue() }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $domain->verificationRecordValue() }}">Copy</button>
              </div>
            </div>
          </div>
        </div>

        {{-- Step 2 — Routing CNAME --}}
        <div class="ds-step-block">
          <div class="ds-step-num">2</div>
          <div style="min-width:0">
            <div class="ds-step-title">Send your traffic to us</div>
            <div class="ds-step-desc">
              Add this CNAME so visitors to <code>{{ $domain->hostname }}</code> reach your shop.
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">CNAME</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $domain->hostname }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $cnameTarget }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $cnameTarget }}">Copy</button>
              </div>
            </div>
            <p style="font-size:11.5px;color:var(--ia-text-3,#888);margin-top:10px;line-height:1.55">
              <strong style="color:#F59E0B">Apex domain note:</strong> Some registrars don't permit a CNAME on the root domain. If yours doesn't, use a CNAME-flattening feature (Cloudflare's is automatic; some registrars call it ANAME or ALIAS), or use a subdomain like <code style="font-family:var(--ia-font-mono,monospace)">www.{{ $domain->hostname }}</code>.
            </p>
          </div>
        </div>

        {{-- Step 3 — ACME challenge (informational, value comes from Cloudflare) --}}
        <div class="ds-step-block">
          <div class="ds-step-num">3</div>
          <div style="min-width:0">
            <div class="ds-step-title">Authorise the HTTPS certificate</div>
            <div class="ds-step-desc">
              Once steps 1 and 2 are live, our certificate provider (Cloudflare) will email you with a third record to add. It looks like the example below — the exact value will be unique to your domain. <strong style="color:var(--ia-text);font-weight:500">Use the value from the email, not this example.</strong>
            </div>
            <div class="ds-dns" style="margin-top:10px">
              <div class="ds-dns-row head">
                <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
              </div>
              <div class="ds-rec-note">
                <div><span class="ds-rec-note-pill">CNAME</span></div>
                <div class="ds-rec-note-name">_acme-challenge.{{ $domain->hostname }}</div>
                <div class="ds-rec-note-value"><em>(Cloudflare-issued, ends in <code style="font-family:inherit">.dcv.cloudflare.com</code>)</em></div>
                <div></div>
              </div>
            </div>
          </div>
        </div>

      </div>

  @if($instructionsPresentation === 'collapsed')
      </div>
    </details>
  @else
    </div>
  @endif
@endif

{{-- ───────────── CERT VALIDATION (MARKER-PATCH-125) ───────────── --}}
{{-- Cloudflare for SaaS gate-2 records. Cert can't issue until tenant adds these. --}}
@php
  $preferredDcv = $domain->preferredDcvRecord();
  $txtFallback  = $domain->dcvTxtFallbackRecord();
  $showCertVal  = $preferredDcv !== null
                  && in_array($statusKey, ['pending_dns','verifying','issuing_cert','active'], true);
  $certValMode  = $statusKey === 'active' ? 'collapsed' : 'prominent';
@endphp

@if($showCertVal)
  @if($certValMode === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        Cert validation records <span style="font-weight:400;opacity:.7">— required for automatic cert renewal</span>
      </summary>
      <div style="padding:14px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">
          @if($preferredDcv['type'] === 'CNAME')
            One more record — handles cert renewals automatically
          @else
            One more record — required to issue your HTTPS cert
          @endif
        </span>
      </div>
      <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
        @if($preferredDcv['type'] === 'CNAME')
          This record lets the cert authority validate your domain. Adding the CNAME version (below) is preferred — it's set-and-forget and renews on its own.
        @else
          Add this TXT record so the cert authority can validate your domain. The value rotates at every cert renewal — we'll prompt you when it changes.
        @endif
      </p>
  @endif

      <div class="ds-dns">
        <div class="ds-dns-row head">
          <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">{{ $preferredDcv['type'] }}</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $preferredDcv['name'] }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $preferredDcv['value'] }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $preferredDcv['value'] }}">Copy</button>
        </div>
      </div>

      @if($preferredDcv['type'] === 'CNAME' && $txtFallback && $certValMode !== 'collapsed')
        <details style="margin-top:12px">
          <summary style="cursor:pointer;font-size:12px;color:var(--ia-text-3,#888)">
            Can't add a CNAME under <code style="font-family:var(--ia-font-mono,monospace)">_acme-challenge</code>? Use the TXT alternative.
          </summary>
          <div style="margin-top:10px">
            <p style="font-size:11.5px;color:var(--ia-text-3,#888);margin-bottom:8px;line-height:1.55">
              Some registrars don't permit CNAMEs at this subdomain. The TXT version works but its value changes at every cert renewal (about every 90 days) — you'll need to update it manually each time.
            </p>
            <div class="ds-dns">
              <div class="ds-dns-row">
                <div><span class="dm-pill verifying" style="padding:2px 8px">{{ $txtFallback['type'] }}</span></div>
                <div class="ds-dns-mono ds-dns-value">{{ $txtFallback['name'] }}</div>
                <div class="ds-dns-mono ds-dns-value">{{ $txtFallback['value'] }}</div>
                <button type="button" class="ds-copy-btn" data-copy="{{ $txtFallback['value'] }}">Copy</button>
              </div>
            </div>
          </div>
        </details>
      @endif

  @if($certValMode === 'collapsed')
      </div>
    </details>
  @else
    </div>
  @endif
@endif

{{-- ───────────── PROGRESS STEPS (during verifying/issuing) ───────────── --}}
@if(in_array($statusKey, ['verifying', 'issuing_cert']))
  <div class="ia-card" style="margin-bottom:16px">
    <div class="ia-card-head">
      <span class="ia-card-title">Setup progress</span>
    </div>
    <div class="ds-step-list">
      <div class="ds-step done">
        <div class="ds-step-num">✓</div>
        <div>
          <div class="ds-step-title">DNS records added</div>
          <div class="ds-step-desc">Both records detected on {{ $domain->hostname }}.</div>
        </div>
      </div>
      <div class="ds-step {{ in_array($statusKey, ['issuing_cert']) ? 'done' : 'active' }}">
        <div class="ds-step-num">{{ in_array($statusKey, ['issuing_cert']) ? '✓' : '2' }}</div>
        <div>
          <div class="ds-step-title">Ownership verified</div>
          <div class="ds-step-desc">Your TXT token matched. You control this domain.</div>
        </div>
      </div>
      <div class="ds-step {{ $statusKey === 'issuing_cert' ? 'active' : '' }}">
        <div class="ds-step-num">3</div>
        <div>
          <div class="ds-step-title">Provisioning HTTPS certificate</div>
          <div class="ds-step-desc">Our edge network is issuing a free TLS certificate. Usually 30–90 seconds.</div>
        </div>
      </div>
      <div class="ds-step">
        <div class="ds-step-num">4</div>
        <div>
          <div class="ds-step-title">Serving traffic</div>
          <div class="ds-step-desc">Your domain will be live at <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code>.</div>
        </div>
      </div>
    </div>
  </div>
@endif

{{-- ───────────── REASSURANCE (during error) ───────────── --}}
@if($statusKey === 'error')
  <div class="ia-card" style="margin-bottom:16px;background:rgba(255,255,255,.015)">
    <div class="ia-card-head">
      <span class="ia-card-title">Customers are still booking</span>
    </div>
    <p style="font-size:12.5px;color:var(--ia-text-2,#c8c8c8);line-height:1.6">
      Don't panic. Customers can still find you at
      <code style="font-family:var(--ia-font-mono,monospace);color:var(--ia-accent,#BEF264)">{{ $tenant->subdomain }}.{{ config('intake.domain', 'intake.works') }}</code>.
      Once you fix the DNS for {{ $domain->hostname }}, visitors will land on your site again automatically.
    </p>
  </div>

  <div class="ia-card" style="margin-bottom:16px">
    <div class="ia-card-head">
      <span class="ia-card-title">Most common causes</span>
    </div>
    <div class="ds-step-list">
      <div class="ds-step">
        <div class="ds-step-num">1</div>
        <div>
          <div class="ds-step-title">Someone removed the CNAME record</div>
          <div class="ds-step-desc">Check with whoever manages your DNS. Re-add the CNAME and click Retry above.</div>
        </div>
      </div>
      <div class="ds-step">
        <div class="ds-step-num">2</div>
        <div>
          <div class="ds-step-title">Your domain expired</div>
          <div class="ds-step-desc">Log in to your registrar and check the expiry date for {{ $domain->hostname }}.</div>
        </div>
      </div>
      <div class="ds-step">
        <div class="ds-step-num">3</div>
        <div>
          <div class="ds-step-title">Temporary DNS provider outage</div>
          <div class="ds-step-desc">Usually resolves on its own. Click Retry in a few minutes.</div>
        </div>
      </div>
    </div>
  </div>
@endif

{{-- ───────────── DEBUG INFO (collapsible, always visible) ───────────── --}}
<details class="ia-card" style="margin-bottom:16px">
  <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888)">
    Technical details
  </summary>
  <div style="padding:14px 0 0;font-size:12px;line-height:1.7">
    <div>Status: <code>{{ $statusKey }}</code></div>
    <div>Role: <code>{{ $domain->role }}</code> @if($domain->is_primary)<span style="color:var(--ia-accent,#BEF264)">· primary</span>@endif</div>
    @if($domain->alias_mode === 'redirect' && !$domain->is_primary)
      <div>Alias mode: <code>redirect to primary</code></div>
    @endif
    @if($domain->cloudflare_hostname_id)
      <div>Cloudflare hostname ID: <code style="font-family:var(--ia-font-mono,monospace);font-size:11px">{{ $domain->cloudflare_hostname_id }}</code></div>
    @endif
    <div>Last check: <span id="ds-last-check">{{ $domain->last_check_at?->diffForHumans() ?? 'never' }}</span></div>
    @if($domain->last_check_status)
      <div>Last check status: <code>{{ $domain->last_check_status }}</code></div>
    @endif
    @if($domain->last_error_code)
      <div>Last error code: <code style="color:#F87171">{{ $domain->last_error_code }}</code></div>
    @endif
  </div>
</details>

<script>
(function () {
  // Copy buttons
  document.querySelectorAll('.ds-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      if (!text || !navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () {
        var original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = original; }, 1400);
      });
    });
  });

  // "Check now" button — AJAX sync
  var checkBtn = document.getElementById('ds-check-now');
  if (checkBtn) {
    var syncUrl = "{{ route('tenant.domains.sync', ['id' => $domain->id]) }}";
    var csrf    = "{{ csrf_token() }}";
    checkBtn.addEventListener('click', function () {
      var originalText = checkBtn.textContent;
      checkBtn.textContent = 'Checking…';
      checkBtn.disabled = true;
      fetch(syncUrl, {
        method:  'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp && resp.ok) {
            if (resp.changed) {
              // Status changed — reload to show the new state
              window.location.reload();
              return;
            }
            // No change — just update the "last check" label
            var lastCheckEl = document.getElementById('ds-last-check');
            if (lastCheckEl && resp.last_check_at) {
              lastCheckEl.textContent = resp.last_check_at;
            }
            checkBtn.textContent = 'No change yet';
            setTimeout(function () { checkBtn.textContent = originalText; checkBtn.disabled = false; }, 2000);
          } else {
            checkBtn.textContent = 'Check failed';
            setTimeout(function () { checkBtn.textContent = originalText; checkBtn.disabled = false; }, 2000);
          }
        })
        .catch(function () {
          checkBtn.textContent = 'Check failed';
          setTimeout(function () { checkBtn.textContent = originalText; checkBtn.disabled = false; }, 2000);
        });
    });
  }
})();
</script>
@endsection
