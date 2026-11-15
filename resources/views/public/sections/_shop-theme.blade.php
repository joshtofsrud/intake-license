{{-- MARKER-SHOP-THEME: the shop pages take their colours from the site's own look
     (the --p-* design tokens every public page gets from the template and the
     customizer) instead of the fixed white-and-black they used to hard-code.
     Status colours are mixed toward the text colour so they stay readable on a
     light or a dark site. Product photos keep a white tile: real product shots
     are on white. --}}
@once
<style>
  .spg-index, .spg-show, .spg-cart, .spg-checkout, .spg-confirm, .spg-search {
    --acc: var(--p-accent, #BEF264);
    --s-acc-text: var(--p-accent-text, #0a0a0a);
    --s-card: var(--p-shop-card, color-mix(in srgb, var(--p-text, #111) 3%, var(--p-bg, #fff)));
    --s-field: var(--p-field-bg, color-mix(in srgb, var(--p-text, #111) 4%, var(--p-bg, #fff)));
    --s-ink: var(--p-text, #111);
    --s-on-ink: var(--p-bg, #fff);
    --s-photo: var(--p-tile-bg, #fff); /* MARKER-WEB-TEMPLATES: the template's Behind product photos */
    --s-ok: color-mix(in srgb, #1d7a3a 72%, var(--p-text, #111));
    --s-bad: color-mix(in srgb, #b3261e 78%, var(--p-text, #111));
    --s-warn: color-mix(in srgb, #8a5a00 70%, var(--p-text, #111));
    --s-note-bg: color-mix(in srgb, #f5c400 12%, var(--p-bg, #fff));
    --s-r: var(--p-card-r, 14px);
    --s-btn-r: var(--p-btn-r, 11px);
    /* secondary text from the text colour itself, so it reads on a light or a dark
       site even when a shop changed its colours without a template */
    --p-muted: var(--p-muted-real, color-mix(in srgb, var(--p-text, #111) 62%, transparent));
    color: var(--p-text, #111);
  }
  .spg-index h1, .spg-show h1, .spg-cart h1, .spg-checkout h1, .spg-confirm h1, .spg-search h1,
  .spg-index h2, .spg-show h2, .spg-cart h2, .spg-checkout h2, .spg-confirm h2, .spg-search h2 {
    font-family: var(--p-font-heading, inherit);
  }
  .spg-index input, .spg-show input, .spg-cart input, .spg-checkout input, .spg-checkout textarea, .spg-checkout select, .spg-search input, .spg-search select, .spg-index select, .spg-index textarea {
    color: var(--p-text, #111);
  }
  .spg-index select option, .spg-checkout select option { background: var(--p-bg, #fff); color: var(--p-text, #111); }
</style>
@endonce
