@php
  $bgColor   = $c['bg_color']   ?: 'var(--p-accent)';
  $textColor = $c['text_color'] ?: 'var(--p-accent-text)';
@endphp

<section style="background:{{ $bgColor }};padding:clamp(40px,6vw,72px) 0">
  <div class="p-container" style="text-align:center">
    @if(!empty($c['headline']))
      <h2 style="font-size:clamp(24px,4vw,48px);font-weight:800;color:{{ $textColor }};margin-bottom:12px;letter-spacing:-.02em">
        {{ $c['headline'] }}
      </h2>
    @endif
    @if(!empty($c['subheading']))
      <p style="font-size:17px;color:{{ $textColor }};opacity:.75;margin-bottom:28px;max-width:520px;margin-left:auto;margin-right:auto">
        {{ $c['subheading'] }}
      </p>
    @endif
    @if(!empty($c['cta_label']))
      @php
        $isAccentBg = !$c['bg_color'] || $c['bg_color'] === 'var(--p-accent)';
        $btnBg      = $isAccentBg ? 'rgba(0,0,0,.15)' : 'var(--p-accent)';
        $btnColor   = $isAccentBg ? $textColor : 'var(--p-accent-text)';
      @endphp
      <a href="{{ $c['cta_url'] ?? '/book' }}"
         style="display:inline-flex;align-items:center;padding:14px 32px;background:{{ $btnBg }};color:{{ $btnColor }};border-radius:var(--p-r);font-size:16px;font-weight:700;text-decoration:none;transition:filter .15s"
         onmouseover="this.style.filter='brightness(.92)'"
         onmouseout="this.style.filter=''">
        {{ $c['cta_label'] }}
      </a>
    @endif
  </div>
</section>
