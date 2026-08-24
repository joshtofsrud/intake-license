#!/usr/bin/env python3
"""Welcome card: standard card spacing, and the live preview from the mock.

Two misses in the first build:

  1. I gave the card its own head/body/foot padding (14/16/12) instead of
     using .ia-card + .ia-card-head, so it didn't line up with the splash
     card directly below it — different edges, different rhythm.

  2. The mockup had a live preview beside the fields. I shipped a
     "Preview ↗" link to a new tab, which is not the same thing: you
     can't see the effect of what you're typing while you type it.

Now the card matches the splash card's structure exactly, and the right
column carries a preview that repaints on every keystroke.
Run from repo root: python3 apply-welcome-card-polish.py
"""
import re, sys

VIEW = 'resources/views/tenant/pages/index.blade.php'

s = open(VIEW).read()
if 'MARKER-WELCOME-POLISH' in s:
    print("SKIP (already applied)"); sys.exit(0)
if 'MARKER-WELCOME' not in s:
    print("FAIL: run apply-welcome-page-ui.py first"); sys.exit(1)

# ── replace the whole card block ────────────────────────────────────────
start = s.index('{{-- MARKER-WELCOME — the site-wide holding page.')
end   = s.index('{{-- MARKER-SPLASH-2')

CARD = """{{-- MARKER-WELCOME / MARKER-WELCOME-POLISH — the site-wide holding page.
     Structure mirrors the splash card below (.ia-card + .ia-card-head) so
     the two line up; the first pass rolled its own padding and didn't. --}}
@php $wAllowable = \\App\\Support\\WelcomePage::ALLOWABLE; @endphp
<div class="ia-card wl-card {{ $welcome['enabled'] ? 'is-on' : '' }}">
  <form method="POST" action="{{ route('tenant.pages.welcome') }}">
    @csrf
    <div class="ia-card-head wl-head">
      <div>
        <div class="ia-card-title">
          Welcome page
          @if($welcome['enabled'])<span class="wl-pill">On</span>@endif
        </div>
        <div class="wl-sub">Show a holding page instead of your site, while you get things ready.</div>
      </div>
      <label class="wl-switch" title="Turn the welcome page on or off">
        <input type="checkbox" name="welcome_enabled" value="1" @checked($welcome['enabled'])>
        <span class="wl-track"></span>
      </label>
    </div>

    <div class="wl-grid">
      {{-- fields --}}
      <div class="wl-fields">
        <label class="wl-label" for="wl-headline">Headline</label>
        <input type="text" id="wl-headline" name="welcome_headline" maxlength="120" class="ia-input"
               value="{{ $welcome['headline'] }}" placeholder="Something good is coming.">

        <label class="wl-label" for="wl-message">Message</label>
        <textarea id="wl-message" name="welcome_message" rows="3" maxlength="400" class="ia-input"
                  placeholder="We're putting the finishing touches on the new site.">{{ $welcome['message'] }}</textarea>

        <label class="wl-label">Button</label>
        <div class="wl-row">
          <input type="text" id="wl-cta" name="welcome_cta_label" maxlength="40" class="ia-input"
                 value="{{ $welcome['cta_label'] }}" placeholder="Call the shop">
          <input type="text" name="welcome_cta_url" maxlength="255" class="ia-input"
                 value="{{ $welcome['cta_url'] }}" placeholder="tel:5095550142">
        </div>

        <label class="wl-label">Let these through anyway</label>
        <div class="wl-allow">
          @foreach($wAllowable as $key => $meta)
            <label class="wl-check">
              <input type="checkbox" name="welcome_allow[]" value="{{ $key }}"
                     @checked(in_array($key, $welcome['allow'], true))>
              <span>{{ $meta['label'] }}</span>
              <code>{{ $meta['path'] }}</code>
            </label>
          @endforeach
        </div>

        <div class="wl-note">
          <b>You'll still see the real site.</b> Signed-in staff bypass the welcome
          page, so you can keep working on it while visitors see the holding page.
        </div>
      </div>

      {{-- live preview: repaints as you type, so the copy is judged in
           place rather than in another tab --}}
      <div class="wl-side">
        <div class="wl-label">Preview</div>
        <div class="wl-prev">
          <div class="wl-prev-bar">{{ parse_url($currentTenant->publicUrl(), PHP_URL_HOST) }}</div>
          <div class="wl-prev-body">
            @if($currentTenant->logo_url)
              <img class="wl-prev-logo" src="{{ $currentTenant->logo_url }}" alt="">
            @else
              <div class="wl-prev-mark">{{ strtoupper(substr($currentTenant->name, 0, 2)) }}</div>
            @endif
            <div class="wl-prev-h" data-wl-preview="headline">{{ $welcome['headline'] }}</div>
            <div class="wl-prev-p" data-wl-preview="message">{{ $welcome['message'] }}</div>
            <div class="wl-prev-cta" data-wl-preview="cta" @if(!$welcome['cta_label']) hidden @endif>{{ $welcome['cta_label'] }}</div>
            <div class="wl-prev-meta">{{ $currentTenant->name }}</div>
          </div>
        </div>
        <div class="wl-hint">Uses your logo, colours and contact details automatically — nothing else to fill in.</div>
      </div>
    </div>

    <div class="wl-foot">
      <a href="{{ route('tenant.pages.welcome.preview') }}" target="_blank" rel="noopener"
         class="ia-btn ia-btn--secondary ia-btn--sm">Open full page ↗</a>
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save welcome page</button>
    </div>
  </form>
</div>

@push('scripts')
<script>
// MARKER-WELCOME-POLISH — live preview. Cheap enough to run on input; no
// fetch, no debounce needed.
(function () {
  var map = {
    'wl-headline': ['headline', 'Something good is coming.'],
    'wl-message':  ['message',  ''],
    'wl-cta':      ['cta',      ''],
  };
  Object.keys(map).forEach(function (id) {
    var input = document.getElementById(id);
    var target = document.querySelector('[data-wl-preview="' + map[id][0] + '"]');
    if (!input || !target) return;
    input.addEventListener('input', function () {
      var v = input.value.trim();
      target.textContent = v || map[id][1];
      // An empty message or button shouldn't leave a gap in the preview.
      if (map[id][0] !== 'headline') target.hidden = (v === '');
    });
    if (map[id][0] !== 'headline' && !input.value.trim()) target.hidden = true;
  });
})();
</script>
@endpush

"""

s = s[:start] + CARD + s[end:]

# ── styles: drop the custom paddings, add the preview ───────────────────
old_styles_start = s.index('/* MARKER-WELCOME */')
old_styles_end   = s.index('@media (max-width:820px){ .wl-grid{grid-template-columns:1fr} }') + len('@media (max-width:820px){ .wl-grid{grid-template-columns:1fr} }')

NEW_STYLES = """/* MARKER-WELCOME / MARKER-WELCOME-POLISH — inherits .ia-card padding so it
   sits on the same rhythm as the splash card below. */
.wl-card{margin-top:22px}
.wl-card.is-on{box-shadow:inset 3px 0 0 #FBBF24}
.wl-head{align-items:flex-start;gap:14px}
.wl-pill{font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
  background:rgba(251,191,36,.16);color:#FBBF24;border-radius:99px;padding:2px 8px;margin-left:8px}
.wl-sub{font-size:12px;color:var(--ia-text-dim);margin-top:3px;line-height:1.45}
.wl-switch{margin-left:auto;position:relative;width:44px;height:25px;flex:0 0 44px;cursor:pointer}
.wl-switch input{position:absolute;opacity:0;width:0;height:0}
.wl-track{position:absolute;inset:0;border-radius:99px;background:rgba(127,127,127,.3);transition:background .16s}
.wl-track::after{content:'';position:absolute;top:3px;left:3px;width:19px;height:19px;
  border-radius:50%;background:#fff;transition:transform .16s}
.wl-switch input:checked + .wl-track{background:#FBBF24}
.wl-switch input:checked + .wl-track::after{transform:translateX(19px)}
.wl-switch input:focus-visible + .wl-track{outline:2px solid var(--ia-accent);outline-offset:2px}

.wl-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;align-items:start}
.wl-label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;
  color:var(--ia-text-muted);font-weight:700;margin:0 0 6px}
.wl-fields .wl-label:not(:first-child){margin-top:14px}
.wl-fields .ia-input{width:100%}
.wl-row{display:flex;gap:8px}
.wl-row .ia-input{flex:1;min-width:0}
.wl-allow{display:flex;flex-direction:column;gap:8px}
.wl-check{display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer}
.wl-check code{font-size:11px;color:var(--ia-text-muted)}
.wl-note{border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.06);
  border-radius:8px;padding:10px 12px;font-size:11.5px;line-height:1.6;
  color:var(--ia-text-dim);margin-top:14px}
.wl-note b{color:var(--ia-text)}

/* Preview */
.wl-prev{border:.5px solid var(--ia-border);border-radius:10px;overflow:hidden;background:#0d0d0d}
.wl-prev-bar{background:rgba(255,255,255,.04);border-bottom:.5px solid var(--ia-border);
  padding:6px 10px;font-size:10.5px;color:var(--ia-text-muted);
  font-family:ui-monospace,Menlo,monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wl-prev-body{padding:26px 18px;text-align:center;
  background:radial-gradient(circle at 50% 0%, color-mix(in srgb, var(--ia-accent) 12%, transparent), transparent 65%)}
.wl-prev-logo{height:26px;margin:0 auto 12px;display:block;object-fit:contain}
.wl-prev-mark{width:34px;height:34px;border-radius:9px;margin:0 auto 12px;
  display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;
  background:color-mix(in srgb, var(--ia-accent) 16%, transparent);color:var(--ia-accent)}
.wl-prev-h{font-size:16px;font-weight:700;letter-spacing:-.01em;line-height:1.25;color:#f2f2f2}
.wl-prev-p{font-size:11.5px;color:rgba(255,255,255,.5);line-height:1.55;margin-top:7px}
.wl-prev-cta{display:inline-block;margin-top:14px;padding:7px 16px;border-radius:7px;
  background:var(--ia-accent);color:var(--ia-accent-text,#0a0a0a);font-size:11.5px;font-weight:700}
.wl-prev-meta{font-size:10.5px;color:rgba(255,255,255,.3);margin-top:14px}
.wl-hint{font-size:11px;color:var(--ia-text-muted);line-height:1.55;margin-top:9px}

.wl-foot{display:flex;justify-content:flex-end;gap:8px;
  margin-top:20px;padding-top:16px;border-top:.5px solid var(--ia-border)}
@media (max-width:900px){ .wl-grid{grid-template-columns:1fr} .wl-side{order:-1} }"""

s = s[:old_styles_start] + NEW_STYLES + s[old_styles_end:]
open(VIEW, 'w').write(s)
print("OK: standard card structure")
print("OK: live preview")
print("Done. No migration needed. view:clear after deploy.")
