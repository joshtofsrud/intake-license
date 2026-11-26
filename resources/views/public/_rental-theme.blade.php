{{-- MARKER-RENTAL-PAGES-THEME: one look for every public rental page (browse, reserve, confirmed, extension).
     Everything follows the shop's site tokens — page, surface, border, text, accent and the text that sits
     on the accent — so the pages read on a light or a dark site without per-shop fixes.
     Stripe's Payment Element takes its colours from App\Support\RentalTheme::stripe(). --}}
@php
  $rtTenant = $currentTenant ?? $tenant ?? tenant();
  $rtTok    = \App\Support\RentalTheme::tokens($rtTenant);
  $rtDark   = \App\Support\RentalTheme::dark($rtTenant);
@endphp
<style>
  :root {
{!! \App\Support\DesignTokens::cssVars($rtTok) !!}
    --rt-muted: color-mix(in srgb, var(--p-text) 62%, transparent);
    --rt-line: color-mix(in srgb, var(--p-text) 12%, transparent);
    --rt-card: color-mix(in srgb, var(--p-text) {{ $rtDark ? '5' : '3' }}%, var(--p-bg));
    --rt-field: color-mix(in srgb, var(--p-text) {{ $rtDark ? '7' : '2' }}%, var(--p-bg));
    --rt-r: var(--p-card-r, 12px);
    color-scheme: {{ $rtDark ? 'dark' : 'light' }};
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: var(--p-font-body); color: var(--p-text); background: var(--p-bg); line-height: 1.6; -webkit-font-smoothing: antialiased; }
  h1, h2, h3 { font-family: var(--p-font-heading); font-weight: var(--p-heading-weight, 650); text-transform: var(--p-heading-transform, none); letter-spacing: -.015em; line-height: 1.2; }
  a { color: inherit; text-decoration: none; }
  .rt-wrap { max-width: 1040px; margin: 0 auto; padding: clamp(28px, 5vw, 56px) 20px 88px; }
  .rt-wrap.narrow { max-width: 600px; }
  .rt-back { display: inline-flex; gap: 6px; align-items: center; font-size: 13.5px; color: var(--rt-muted); margin-bottom: 18px; }
  .rt-back:hover { color: var(--p-text); }
  .rt-h1 { font-size: clamp(26px, 3.4vw, 38px); }
  .rt-sub { font-size: 15px; color: var(--rt-muted); margin-top: 6px; }
  .rt-card { background: var(--rt-card); border: 1px solid var(--rt-line); border-radius: var(--rt-r); padding: 20px 22px; }
  .rt-label { display: block; font-size: 11.5px; font-weight: 650; text-transform: uppercase; letter-spacing: .06em; color: var(--rt-muted); margin: 14px 0 6px; }
  .rt-input { font: inherit; font-size: 15px; width: 100%; padding: 11px 13px; color: var(--p-text); background: var(--rt-field); border: 1px solid var(--rt-line); border-radius: calc(var(--rt-r) * .7); }
  .rt-input:focus { outline: none; border-color: var(--p-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--p-accent) 25%, transparent); }
  .rt-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font: inherit; font-size: 15px; font-weight: 650; padding: 12px 24px; border: 2px solid var(--p-accent); border-radius: var(--p-btn-r, 10px); background: var(--p-accent); color: var(--p-accent-text); cursor: pointer; transition: filter .15s, transform .1s; }
  .rt-btn:hover { filter: brightness(.94); }
  .rt-btn:active { transform: scale(.98); }
  .rt-btn:disabled { opacity: .5; cursor: default; }
  .rt-btn.block { width: 100%; }
  .rt-btn.ghost { background: transparent; color: var(--p-text); border-color: var(--rt-line); }
  .rt-kv { display: flex; justify-content: space-between; gap: 16px; font-size: 14.5px; padding: 5px 0; }
  .rt-kv > span:first-child { color: var(--rt-muted); }
  .rt-kv.total { font-weight: 700; border-top: 1px solid var(--rt-line); margin-top: 8px; padding-top: 12px; }
  .rt-err { display: none; background: color-mix(in srgb, #e5484d 12%, var(--p-bg)); border: 1px solid color-mix(in srgb, #e5484d 45%, transparent); color: var(--p-text); border-radius: 10px; padding: 10px 14px; font-size: 13.5px; margin-top: 14px; }
  .rt-note { font-size: 13px; color: var(--rt-muted); }
</style>
