#!/usr/bin/env python3
"""Welcome page, part 2 — the settings card and the honest list.

Adds the card above the pages list (headline, message, CTA, allow-list,
preview) and fixes the two places that would otherwise lie while the
welcome page is on:

  * A page badged "Live" that nobody can actually reach.
  * The splash card, which quietly stops mattering — welcome replaces the
    site, so there is nothing left to splash in front of.
Run from repo root: python3 apply-welcome-page-ui.py
"""
import sys

VIEW = 'resources/views/tenant/pages/index.blade.php'

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

s = read(VIEW)
if 'MARKER-WELCOME' in s:
    print("SKIP (already applied): welcome card"); sys.exit(0)

# Anchor: the splash card opens with this comment block.
anchor = "{{-- MARKER-SPLASH-2"
if anchor not in s:
    print("FAIL: splash card anchor not found"); sys.exit(1)

CARD = """{{-- MARKER-WELCOME — the site-wide holding page. Distinct from Splash:
     splash interrupts before a page the visitor may then see; this
     replaces the site outright. --}}
@php $wAllowable = \\App\\Support\\WelcomePage::ALLOWABLE; @endphp
<div class="ia-card wl-card {{ $welcome['enabled'] ? 'is-on' : '' }}" style="margin-bottom:18px">
  <form method="POST" action="{{ route('tenant.pages.welcome') }}">
    @csrf
    <div class="wl-head">
      <div>
        <div class="wl-title">
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
      <div>
        <label class="wl-label">Headline</label>
        <input type="text" name="welcome_headline" maxlength="120" class="ia-input"
               value="{{ $welcome['headline'] }}" placeholder="Something good is coming.">

        <label class="wl-label">Message</label>
        <textarea name="welcome_message" rows="3" maxlength="400" class="ia-input"
                  placeholder="We're putting the finishing touches on the new site.">{{ $welcome['message'] }}</textarea>

        <label class="wl-label">Button</label>
        <div class="wl-row">
          <input type="text" name="welcome_cta_label" maxlength="40" class="ia-input"
                 value="{{ $welcome['cta_label'] }}" placeholder="Call the shop">
          <input type="text" name="welcome_cta_url" maxlength="255" class="ia-input"
                 value="{{ $welcome['cta_url'] }}" placeholder="tel:5095550142">
        </div>
      </div>

      <div>
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
    </div>

    <div class="wl-foot">
      <a href="{{ route('tenant.pages.welcome.preview') }}" target="_blank" rel="noopener" class="ia-btn ia-btn--secondary ia-btn--sm">Preview ↗</a>
      <button class="ia-btn ia-btn--primary ia-btn--sm">Save welcome page</button>
    </div>
  </form>
</div>

"""

s = s.replace(anchor, CARD + anchor, 1)

# Splash is meaningless while welcome is up — say so rather than leaving
# two switches that appear to fight.
s = s.replace(
    '<div class="sp-sub">A splash appears before a page loads. Pages without a row here are never interrupted.</div>',
    '<div class="sp-sub">A splash appears before a page loads. Pages without a row here are never interrupted.'
    '@if($welcome[\'enabled\'])<br><span style="color:#FBBF24">The welcome page is on, so visitors never reach these.</span>@endif</div>',
    1)

STYLES = """
/* MARKER-WELCOME */
.wl-card{padding:0;overflow:hidden}
.wl-card.is-on{box-shadow:inset 3px 0 0 #FBBF24}
.wl-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;
  padding:14px 16px;border-bottom:.5px solid var(--ia-border)}
.wl-title{font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:8px}
.wl-pill{font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
  background:rgba(251,191,36,.16);color:#FBBF24;border-radius:99px;padding:2px 8px}
.wl-sub{font-size:12px;color:var(--ia-text-dim);margin-top:2px}
.wl-switch{position:relative;width:38px;height:22px;flex:none;cursor:pointer}
.wl-switch input{position:absolute;opacity:0;width:0;height:0}
.wl-track{position:absolute;inset:0;background:rgba(127,127,127,.28);border-radius:99px;transition:background .15s}
.wl-track::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;
  border-radius:50%;background:#fff;transition:transform .15s}
.wl-switch input:checked + .wl-track{background:#FBBF24}
.wl-switch input:checked + .wl-track::after{transform:translateX(16px)}
.wl-switch input:focus-visible + .wl-track{outline:2px solid var(--ia-accent);outline-offset:2px}
.wl-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:16px}
.wl-label{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;
  color:var(--ia-text-muted);font-weight:700;margin:0 0 5px}
.wl-grid .ia-input{width:100%;margin-bottom:12px}
.wl-row{display:flex;gap:8px}
.wl-allow{display:flex;flex-direction:column;gap:7px;margin-bottom:12px}
.wl-check{display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer}
.wl-check code{font-size:11px;color:var(--ia-text-muted)}
.wl-note{border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.06);
  border-radius:8px;padding:9px 11px;font-size:11.5px;line-height:1.6;color:var(--ia-text-dim)}
.wl-note b{color:var(--ia-text)}
.wl-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:.5px solid var(--ia-border)}
@media (max-width:820px){ .wl-grid{grid-template-columns:1fr} }
"""

# Styles go at the end of the view's last <style> block so nothing below
# can out-cascade them.
cut = s.rindex('</style>')
s = s[:cut] + STYLES + s[cut:]

write(VIEW, s)
print("OK: welcome card + styles")
print("OK: splash card notes the override")
print("Done. No migration needed. view:clear after deploy.")
