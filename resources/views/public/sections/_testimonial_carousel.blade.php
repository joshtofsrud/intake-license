{{-- MARKER-SHOP-TESTIMONIALS: testimonials on shop sites. The builder has
     offered this section to shops all along, but there was no shop renderer,
     so the section silently never appeared. Same layout as intake.works
     (marketing/sections/testimonial_carousel): a grid of quote cards with
     eyebrow, heading and subheading, in the shop's own theme colors.
     Content: eyebrow, heading, subheading, testimonials[{quote, author, role, avatar_url}] --}}
@php
  $c = $c ?? [];
  $tsList = $c['testimonials'] ?? [];
  if (is_string($tsList)) { $d = json_decode($tsList, true); $tsList = is_array($d) ? $d : []; }
  if (! is_array($tsList)) $tsList = [];
  $tsList = array_values(array_filter($tsList, fn ($t) => is_array($t) && trim((string) ($t['quote'] ?? '')) !== ''));

  $tsBg = $c['bg_color'] ?? null;
  $tsBg = is_string($tsBg) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $tsBg) ? $tsBg : null;
  $tsText = $tsBg ? \App\Support\ColorHelper::accentTextColor(strlen($tsBg) === 4 ? '#' . $tsBg[1] . $tsBg[1] . $tsBg[2] . $tsBg[2] . $tsBg[3] . $tsBg[3] : substr($tsBg, 0, 7)) : null;
  $tsSafe = fn ($u) => preg_match('#^(https?://|/)#i', trim((string) $u)) ? trim((string) $u) : '';

  $anchorId    = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($c['anchor_id'] ?? ''));
  $customClass = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string) ($c['custom_classes'] ?? '')));
  $hideMobile  = ! empty($c['hide_on_mobile']) && ! in_array((string) $c['hide_on_mobile'], ['0', 'false'], true);
  $hideDesktop = ! empty($c['hide_on_desktop']) && ! in_array((string) $c['hide_on_desktop'], ['0', 'false'], true);

  $instId = 'p-ts-' . ($section->id ?? uniqid());
@endphp
<style>
.{{ $instId }} {
  padding: clamp(56px, 8vw, 96px) 0;
  @if($tsBg) background: {{ $tsBg }}; @endif
  color: {{ $tsText ?: 'var(--p-text, #0a0a0a)' }};
}
.{{ $instId }} .p-ts-wrap { max-width: var(--p-page-max, 1100px); margin: 0 auto; padding: 0 clamp(20px, 5vw, 48px); }
.{{ $instId }} .p-ts-head { text-align: center; margin-bottom: 44px; }
.{{ $instId }} .p-ts-eyebrow { font-family: ui-monospace, monospace; font-size: 11px; text-transform: uppercase; letter-spacing: .12em; color: var(--p-accent, currentColor); margin-bottom: 14px; }
.{{ $instId }} .p-ts-heading { font-size: clamp(24px, 3.5vw, 40px); font-weight: var(--p-heading-weight, 500); text-transform: var(--p-heading-transform, none); line-height: 1.15; letter-spacing: -.02em; margin: 0; }
.{{ $instId }} .p-ts-sub { font-size: 16px; line-height: 1.6; opacity: .7; max-width: 60ch; margin: 14px auto 0; }
.{{ $instId }} .p-ts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
.{{ $instId }} .p-ts-card { margin: 0; padding: 28px; border-radius: var(--p-card-r, 14px); background: color-mix(in srgb, currentColor 4%, transparent); border: 1px solid color-mix(in srgb, currentColor 10%, transparent); transition: border-color .15s; display: flex; flex-direction: column; }
.{{ $instId }} .p-ts-card:hover { border-color: color-mix(in srgb, var(--p-accent, currentColor) 45%, transparent); }
.{{ $instId }} .p-ts-mark { color: var(--p-accent, currentColor); font-size: 30px; line-height: 1; margin-bottom: 14px; font-family: Georgia, serif; }
.{{ $instId }} .p-ts-quote { font-size: 15px; line-height: 1.65; margin: 0 0 22px; flex: 1; }
.{{ $instId }} .p-ts-who { display: flex; align-items: center; gap: 12px; }
.{{ $instId }} .p-ts-av { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; flex: none; background: color-mix(in srgb, var(--p-accent, currentColor) 18%, transparent); }
.{{ $instId }} .p-ts-name { font-weight: 600; font-size: 14px; }
.{{ $instId }} .p-ts-role { font-size: 12px; opacity: .6; }
@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>
<section class="{{ $instId }} p-testimonials {{ $customClass }}" @if($anchorId !== '') id="{{ $anchorId }}" @endif>
  <div class="p-ts-wrap">
    @if(! empty($c['eyebrow']) || ! empty($c['heading']) || ! empty($c['subheading']))
      <div class="p-ts-head">
        @if(! empty($c['eyebrow']))<div class="p-ts-eyebrow">{{ $c['eyebrow'] }}</div>@endif
        @if(! empty($c['heading']))<h2 class="p-ts-heading">{{ $c['heading'] }}</h2>@endif
        @if(! empty($c['subheading']))<p class="p-ts-sub">{{ $c['subheading'] }}</p>@endif
      </div>
    @endif
    <div class="p-ts-grid">
      @foreach($tsList as $t)
        <blockquote class="p-ts-card">
          <div class="p-ts-mark" aria-hidden="true">&ldquo;</div>
          <p class="p-ts-quote">{{ $t['quote'] }}</p>
          <footer class="p-ts-who">
            @if($tsSafe($t['avatar_url'] ?? '') !== '')
              <img class="p-ts-av" src="{{ $tsSafe($t['avatar_url']) }}" alt="" loading="lazy">
            @else
              <span class="p-ts-av" aria-hidden="true"></span>
            @endif
            <div>
              @if(! empty($t['author']))<div class="p-ts-name">{{ $t['author'] }}</div>@endif
              @if(! empty($t['role']))<div class="p-ts-role">{{ $t['role'] }}</div>@endif
            </div>
          </footer>
        </blockquote>
      @endforeach
    </div>
  </div>
</section>
