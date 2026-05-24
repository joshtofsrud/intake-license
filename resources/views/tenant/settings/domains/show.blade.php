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
  <form method="POST" action="{{ route('tenant.domains.destroy', ['subdomain' => $tenant->subdomain, 'id' => $domain->id]) }}"
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

{{-- ───────────── DNS RECORDS (MARKER-DNS-HOTFIX — always visible, state-aware framing) ───────────── --}}
@if($statusKey !== 'suspended')
  @php
    // State-aware presentation:
    //   - prominent      → tenant needs to act (pending_dns, error)
    //   - reference      → setup in progress; tenant can sanity-check (verifying, issuing_cert)
    //   - collapsed      → already working; keep visible for reference but de-emphasized (active)
    $dnsPresentation = match($statusKey) {
      'pending_dns', 'error' => 'prominent',
      'verifying', 'issuing_cert' => 'reference',
      'active' => 'collapsed',
      default => 'reference',
    };
  @endphp

  @if($dnsPresentation === 'collapsed')
    <details class="ia-card" style="margin-bottom:16px">
      <summary style="cursor:pointer;font-size:13px;color:var(--ia-text-3,#888);font-weight:600">
        DNS records on file <span style="font-weight:400;opacity:.7">— keep these in place to stay live</span>
      </summary>
      <div style="padding:14px 0 0">
  @else
    <div class="ia-card" style="margin-bottom:16px">
      <div class="ia-card-head">
        <span class="ia-card-title">
          @if($dnsPresentation === 'prominent')
            Add these records at your registrar
          @else
            DNS records (for reference)
          @endif
        </span>
      </div>
      @if($dnsPresentation === 'prominent')
        <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
          Wherever you bought <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code> — GoDaddy, Cloudflare, Namecheap, etc.
        </p>
      @else
        <p style="font-size:12.5px;color:var(--ia-text-3,#888);margin-bottom:12px;line-height:1.55">
          We detected the records below on <code style="font-family:var(--ia-font-mono,monospace)">{{ $domain->hostname }}</code>. Keep them in place — if any are removed, your domain will stop serving customers.
        </p>
      @endif
  @endif

      <div class="ds-dns">
        <div class="ds-dns-row head">
          <div>Type</div><div>Name / Host</div><div>Value</div><div></div>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">TXT</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordName() }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->verificationRecordValue() }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $domain->verificationRecordValue() }}">Copy</button>
        </div>
        <div class="ds-dns-row">
          <div><span class="dm-pill verifying" style="padding:2px 8px">CNAME</span></div>
          <div class="ds-dns-mono ds-dns-value">{{ $domain->hostname }}</div>
          <div class="ds-dns-mono ds-dns-value">{{ $cnameTarget }}</div>
          <button type="button" class="ds-copy-btn" data-copy="{{ $cnameTarget }}">Copy</button>
        </div>
      </div>

      @if($dnsPresentation === 'prominent')
        <p style="font-size:12px;color:var(--ia-text-3,#888);margin-top:14px;line-height:1.55">
          <strong style="color:#F59E0B">Apex domain note:</strong> Some registrars don't allow CNAME on the root domain.
          If yours doesn't, use a CNAME flattening feature (Cloudflare's default, or "ANAME" / "ALIAS" records elsewhere),
          or use a subdomain like <code style="font-family:var(--ia-font-mono,monospace)">www.{{ $domain->hostname }}</code>.
        </p>
      @endif

  @if($dnsPresentation === 'collapsed')
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
    var syncUrl = "{{ route('tenant.domains.sync', ['subdomain' => $tenant->subdomain, 'id' => $domain->id]) }}";
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
