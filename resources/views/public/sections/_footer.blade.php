@php
  $footerBg = $tenant->bg_color ?? '#ffffff';
  $logoUrl = \App\Support\ColorHelper::pickLogo($tenant, $footerBg);
@endphp

<style>
.p-footer {
  border-top: 1px solid rgba(0,0,0,.08);
  padding: clamp(32px, 5vw, 64px) 0 clamp(24px, 3vw, 40px);
  margin-top: auto;
}
.p-footer-inner {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 32px;
  flex-wrap: wrap;
  margin-bottom: 32px;
}
.p-footer-brand { max-width: 300px; }
.p-footer-logo {
  font-family: var(--p-font-heading);
  font-size: 18px;
  font-weight: 700;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.p-footer-logo img { height: 28px; width: auto; }
.p-footer-tagline { font-size: 14px; opacity: .5; line-height: 1.5; }
.p-footer-nav {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.p-footer-nav-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  font-weight: 600;
  opacity: .4;
  margin-bottom: 4px;
}
.p-footer-link { font-size: 14px; opacity: .65; transition: opacity .12s; }
.p-footer-link:hover { opacity: 1; }
.p-footer-bottom {
  border-top: 1px solid rgba(0,0,0,.07);
  padding-top: 20px;
  font-size: 13px;
  opacity: .4;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}
@media (max-width: 600px) { .p-footer-inner { flex-direction: column; } }
</style>

<footer class="p-footer">
  <div class="p-container">
    <div class="p-footer-inner">
      <div class="p-footer-brand">
        @if($c['show_logo'] ?? true)
          <div class="p-footer-logo">
            @if($logoUrl)
              <img src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
            @else
              {{ $tenant->name }}
            @endif
          </div>
        @endif
        @if($tenant->tagline)
          <p class="p-footer-tagline">{{ $tenant->tagline }}</p>
        @endif
      </div>

      @if($navItems->isNotEmpty())
        <div class="p-footer-nav">
          <div class="p-footer-nav-label">Navigation</div>
          @foreach($navItems as $item)
            <a href="{{ $item->url }}" class="p-footer-link">{{ $item->label }}</a>
          @endforeach
        </div>
      @endif

      <div class="p-footer-nav">
        <div class="p-footer-nav-label">Book</div>
        <a href="/book" class="p-footer-link">Book online</a>
        @if($tenant->notification_email)
          <a href="mailto:{{ $tenant->email_from_address ?? $tenant->notification_email }}" class="p-footer-link">
            Contact us
          </a>
        @endif
      </div>
    </div>

    <div class="p-footer-bottom">
      <span>
        {{ $c['copyright_text'] ?: '© ' . date('Y') . ' ' . $tenant->name . '. All rights reserved.' }}
      </span>
      <span>Powered by <a href="https://intake.works" style="opacity:.7" target="_blank">Intake</a></span>
    </div>
  </div>
</footer>
