#!/bin/bash
# apply-register-mobile.sh
#
# MARKER-REG-MOBILE — the register section on a phone. Josh: "register is
# sloppy on mobile."
#
#  1. THE PICKER'S MOBILE RULE HAS NEVER WORKED — a real bug, not a taste
#     call. MARKER-OFFLINE-SYNC stage 3b added a <=760px rule giving the
#     register picker its own full-width row, but the <select> carries
#     style="margin-left:auto;max-width:220px;font-size:13px" INLINE, and
#     inline styles beat stylesheet rules regardless of media queries. So
#     max-width:none/width:100% never applied and the picker stayed a
#     220px box floating right — the stray element in Josh's screenshot.
#     Those three declarations move into CSS, where the media query can
#     actually override them.
#
#  2. TABS SCROLL INSTEAD OF WRAPPING. Five tabs at 390px wrapped to two
#     rows with "Registers Settings" stranded under a half-empty row. The
#     links get a wrapper that is display:contents on desktop — so the bar's
#     flex layout, including the picker's margin-left:auto, is byte-for-byte
#     unchanged — and a horizontal scroller below 760px.
#
#  3. SUBTITLE HIDDEN ON MOBILE across the four register pages. The header
#     stack ate roughly half the viewport before any content.
#
#  4. THE CHECKOUT BANNER STACKS. Its button sat mid-block beside five lines
#     of wrapped text; below 760px it drops to its own full-width line.
#
# NOT INCLUDED, deliberately: the cart being entirely below the fold. That
# is a layout decision (sticky cart summary that expands), not a tidy-up,
# and Josh should choose it rather than find it.
set -e

MARKER="MARKER-REG-MOBILE"
cd_check="resources/views/tenant/register/index.blade.php"
[ -f "$cd_check" ] || { echo "ERROR: run from the repo root"; exit 1; }
if grep -q "$MARKER" "$cd_check" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io

CSS = """
  /* MARKER-REG-MOBILE ------------------------------------------------- */
  /* display:contents keeps the links as direct flex children of the bar on
     desktop, so nothing about the existing layout changes. */
  .reg-tabs-scroll{display:contents}

  @media (max-width: 760px){
    .ia-page-subtitle{display:none}

    .reg-tabs-bar{display:block;flex-wrap:nowrap}
    .reg-tabs-scroll{
      display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;
      -webkit-overflow-scrolling:touch
    }
    .reg-tabs-scroll::-webkit-scrollbar{display:none}
    .reg-tab-link{white-space:nowrap;flex:0 0 auto;padding:10px 14px}
  }
"""

PICKER_CSS = """
  /* MARKER-REG-MOBILE — these three were inline on the <select>, which meant
     no media query could override them and the stage-3b mobile rule below
     silently did nothing. */
  .reg-tabs-bar #registerPicker{margin-left:auto;max-width:220px;font-size:13px}

  @media (max-width: 760px){
    /* now reachable: its own full-width row under the tabs */
    .reg-tabs-bar #registerPicker{
      display:block;width:100%;max-width:none;margin:8px 0 2px
    }
    /* the checkout banner stacks rather than wrapping around its button */
    #appointment-tray-banner{flex-wrap:wrap}
    #appointment-tray-banner > button{flex:1 1 100%}
  }
"""

# ---------------------------------------------------------------
# 1. CSS into all four register pages
# ---------------------------------------------------------------
tab_anchor = """  .reg-tabs-bar{
    display:flex;gap:4px;margin:0 0 18px;border-bottom:0.5px solid var(--ia-border);
    flex-wrap:wrap
  }"""

for f in ['index', 'history', 'quotes', 'settings']:
    p = f'resources/views/tenant/register/{f}.blade.php'
    src = io.open(p, encoding='utf-8').read()
    assert src.count(tab_anchor) == 1, f'tab css anchor in {f}'
    add = CSS + (PICKER_CSS if f == 'index' else '')
    src = src.replace(tab_anchor, tab_anchor + add, 1)
    io.open(p, 'w', encoding='utf-8').write(src)
    print(f'ok: {f} css')

# ---------------------------------------------------------------
# 2. Wrap the tab links (markup differs per page — explicit each time)
# ---------------------------------------------------------------
blocks = {
  'history': ("""<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link active">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
</div>""",
  """<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link active">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  </div>
</div>"""),

  'quotes': ("""<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link active">Quotes</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
</div>""",
  """<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link active">Quotes</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  </div>
</div>"""),

  'settings': ("""<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link active">Settings</a>
</div>""",
  """<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link">Transaction</a>
  <a href="{{ route('tenant.register.history.index') }}" class="reg-tab-link">Transaction History</a>
  <a href="{{ route('tenant.register.quotes.index') }}" class="reg-tab-link">Quotes</a>
  <a href="{{ route('tenant.register.registers') }}" class="reg-tab-link">Registers</a>
  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link active">Settings</a>
  </div>
</div>"""),
}

for f, (old, new) in blocks.items():
    p = f'resources/views/tenant/register/{f}.blade.php'
    src = io.open(p, encoding='utf-8').read()
    assert src.count(old) == 1, f'tab markup anchor in {f}'
    io.open(p, 'w', encoding='utf-8').write(src.replace(old, new, 1))
    print(f'ok: {f} tabs wrapped')

# index: links wrapped, picker stays OUTSIDE the scroller so it can take its
# own row on mobile instead of scrolling away with the tabs.
p = 'resources/views/tenant/register/index.blade.php'
src = io.open(p, encoding='utf-8').read()

old = """<div class="reg-tabs-bar">
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>"""
assert src.count(old) == 1, 'index tabs open'
src = src.replace(old, """<div class="reg-tabs-bar">
  <div class="reg-tabs-scroll">{{-- MARKER-REG-MOBILE --}}
  <a href="{{ route('tenant.register.index') }}" class="reg-tab-link active">Transaction</a>""", 1)

old2 = """  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  {{-- MARKER-REGISTER-RECON-DISPLAY — register picker (only when registers exist) --}}"""
assert src.count(old2) == 1, 'index picker boundary'
src = src.replace(old2, """  <a href="{{ route('tenant.register.settings') }}" class="reg-tab-link">Settings</a> {{-- MARKER-REG-SETTINGS --}}
  </div>{{-- /reg-tabs-scroll MARKER-REG-MOBILE — the picker sits OUTSIDE the
        scroller so it can take its own row on a phone. --}}
  {{-- MARKER-REGISTER-RECON-DISPLAY — register picker (only when registers exist) --}}""", 1)

# strip the inline styles now living in CSS
old3 = """    <select id="registerPicker" class="ia-input" style="margin-left:auto;max-width:220px;font-size:13px\""""
assert src.count(old3) == 1, 'picker inline style'
src = src.replace(old3, """    {{-- MARKER-REG-MOBILE — margin/max-width/font-size moved to CSS so the
         mobile rule can override them. --}}
    <select id="registerPicker" class="ia-input""" + '"', 1)


io.open(p, 'w', encoding='utf-8').write(src)
print('ok: index tabs, picker, banner')
PY

echo ""
echo "== register mobile applied =="
echo "Post-deploy: php artisan optimize:clear"
