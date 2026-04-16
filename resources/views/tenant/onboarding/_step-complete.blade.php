<div style="text-align:center">
  <div class="ob-complete-icon">✓</div>
  <div class="ob-step-label" style="justify-content:center;margin-bottom:8px">You're all set</div>
  <h1 class="ob-title">{{ $currentTenant->name }} is live</h1>
  <p class="ob-subtitle" style="margin-left:auto;margin-right:auto;max-width:380px">
    Your booking page is up and your admin is ready to go.
    Here's where to head next:
  </p>
</div>

<div style="margin-top:24px;display:flex;flex-direction:column;gap:0">
  <a href="{{ route('tenant.dashboard') }}" class="ob-link-card">
    <div>
      <div class="ob-link-label">Go to dashboard</div>
      <div class="ob-link-url">Manage appointments, customers, and more</div>
    </div>
    <span class="ob-link-arrow">→</span>
  </a>

  <a href="{{ $currentTenant->publicUrl() }}" target="_blank" class="ob-link-card">
    <div>
      <div class="ob-link-label">View your site</div>
      <div class="ob-link-url">{{ $currentTenant->publicUrl() }}</div>
    </div>
    <span class="ob-link-arrow">↗</span>
  </a>

  <a href="{{ $currentTenant->bookingUrl() }}" target="_blank" class="ob-link-card">
    <div>
      <div class="ob-link-label">Test your booking page</div>
      <div class="ob-link-url">{{ $currentTenant->bookingUrl() }}</div>
    </div>
    <span class="ob-link-arrow">↗</span>
  </a>

  <a href="{{ route('tenant.pages.index') }}" class="ob-link-card">
    <div>
      <div class="ob-link-label">Customize your website</div>
      <div class="ob-link-url">Build your homepage with the page builder</div>
    </div>
    <span class="ob-link-arrow">→</span>
  </a>
</div>

<div style="margin-top:16px;padding:14px 18px;background:var(--bg3);border-radius:10px;font-size:13px;color:var(--muted);line-height:1.55">
  <strong style="color:#f0f0f0">Share your booking link:</strong>
  <span style="font-family:monospace;font-size:12px;display:block;margin-top:4px;color:var(--accent)">
    {{ $currentTenant->bookingUrl() }}
  </span>
</div>
