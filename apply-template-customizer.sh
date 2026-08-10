#!/usr/bin/env bash
set -euo pipefail
# apply-template-customizer.sh — MARKER-CUSTOMIZER
# Step 3 of the template sequence. Turns the templates page into a customizer.
#
#   - _thumb becomes VARIABLE-DRIVEN. It already renders every token faithfully
#     (that is why the thumbnails looked right while the live site didn't), so
#     it is the honest preview — but it hardcoded each value inline, which JS
#     can't touch. Now it declares --t-* on its own root and every downstream
#     style reads those, so the five cards still render exactly as before AND
#     the customizer copy can be repainted live from JS.
#   - A Customize panel: pick a template as your starting point, then adjust
#     colour / hero / type / buttons. Controls you have changed show a reset
#     link and mark their group, so template default vs your override is
#     always visible.
#   - Save writes ONLY changed values into tenants.design_tokens, which
#     DesignTokens::resolve already reads (MARKER-TOKENS). The _prev revert
#     snapshot is preserved untouched.
#
# REQUIRES apply-design-token-pipeline (MARKER-TOKENS) — this writes the
# overrides that pipeline reads. The script refuses without it.

THUMB=resources/views/tenant/templates/_thumb.blade.php
INDEX=resources/views/tenant/templates/index.blade.php
CTRL=app/Http/Controllers/Tenant/SiteTemplateController.php
ROUTES=routes/web.php

for f in "$THUMB" "$INDEX" "$CTRL" "$ROUTES"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

grep -q "MARKER-TOKENS" resources/views/public/layout.blade.php \
  || { echo "PRECONDITION FAILED: deploy apply-design-token-pipeline.sh first"; exit 1; }

if grep -q "MARKER-CUSTOMIZER" "$THUMB"; then
  echo "Already applied (MARKER-CUSTOMIZER present) — no-op."
  exit 0
fi

# ================================================================ thumb → vars
python3 - "$THUMB" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

old_php_start = src.index('@php')
old_php_end   = src.index('@endphp') + len('@endphp')

new_php = """@php
  /* MARKER-CUSTOMIZER — every value is now a CSS variable read off this
     preview's own root, so the same markup serves the static template cards
     and the live customizer (which repaints by setting those variables).
     $tokenVars below is what declares them. */
  $t = $tokens;
  $rawAccent = $t['accent'] ?? '#BEF264';

  $tokenVars = implode(';', [
      '--t-accent: '      . $rawAccent,
      '--t-accent-text: ' . \\App\\Support\\ColorHelper::accentTextColor($rawAccent),
      '--t-bg: '          . ($t['bg'] ?? '#ffffff'),
      '--t-text: '        . ($t['text'] ?? '#111'),
      '--t-surface: '     . ($t['surface'] ?? '#f2f2f2'),
      '--t-muted: '       . ($t['muted'] ?? '#777'),
      '--t-hero-bg: '     . ($t['hero_bg'] ?? $t['bg'] ?? '#ffffff'),
      '--t-hero-text: '   . ($t['hero_text'] ?? $t['text'] ?? '#111'),
      '--t-btn-r: '       . (int) ($t['button_radius'] ?? 8) . 'px',
      "--t-f-head: '"     . ($t['font_heading'] ?? 'Inter') . "', sans-serif",
      "--t-f-body: '"     . ($t['font_body'] ?? 'Inter') . "', sans-serif",
      '--t-h-weight: '    . (int) ($t['heading_weight'] ?? 700),
      '--t-h-case: '      . ($t['heading_transform'] ?? 'none'),
  ]);

  $accent   = 'var(--t-accent)';
  $bg       = 'var(--t-bg)';
  $text     = 'var(--t-text)';
  $surface  = 'var(--t-surface)';
  $muted    = 'var(--t-muted)';
  $heroBg   = 'var(--t-hero-bg)';
  $heroText = 'var(--t-hero-text)';
  $radius   = 'var(--t-btn-r)';
  $accentText = 'var(--t-accent-text)';

  /* Button style can't be a variable (it changes which properties apply), so
     it stays server-rendered; the customizer swaps a class instead. */
  $btnStyle = $t['button_style'] ?? 'solid';
  $btn = $btnStyle === 'outline'
      ? 'background:transparent;border:1.5px solid var(--t-accent);color:var(--t-text)'
      : ($btnStyle === 'ghost'
          ? 'background:var(--t-surface);border:1.5px solid transparent;color:var(--t-text)'
          : 'background:var(--t-accent);border:1.5px solid var(--t-accent);color:var(--t-accent-text)');

  $hStyle = 'font-family:var(--t-f-head);font-weight:var(--t-h-weight);text-transform:var(--t-h-case)';
  $shop   = $currentTenant->name ?? 'Your Business';
  $blocks = $layout ?? [];
@endphp"""

src = src[:old_php_start] + new_php + src[old_php_end:]
print("ok   thumb token vars")

# radius already carries its unit
n = src.count('{{ $radius }}px')
src = src.replace('{{ $radius }}px', '{{ $radius }}')
print(f"ok   thumb radius unit ({n} sites)")

old_root = """<div class="fs" style="background:{{ $bg }};color:{{ $text }};font-family:'{{ $fBody }}',sans-serif">"""
new_root = """<div class="fs" data-fs-preview style="{{ $tokenVars }};background:var(--t-bg);color:var(--t-text);font-family:var(--t-f-body)">"""
if src.count(old_root) != 1:
    print(f"FAIL thumb root: anchor found {src.count(old_root)} times"); sys.exit(1)
src = src.replace(old_root, new_root, 1)
print("ok   thumb root")

leftovers = re.findall(r'\$(fHead|fBody|hWeight|hCase)\b', src)
if leftovers:
    print(f"FAIL thumb: stale variables still referenced: {sorted(set(leftovers))}"); sys.exit(1)
print("ok   thumb no stale vars")

open(path, 'w').write(src)
PY

# ================================================================ controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL controller tail: file does not end with }"); sys.exit(1)

method = '''
    /**
     * MARKER-CUSTOMIZER — save per-tenant token overrides.
     *
     * Only values that differ from the active template's own token are stored,
     * so "reset to template default" is simply the absence of a key and a
     * later template change still moves anything untouched. The five columns
     * stay the source of truth for their tokens (booking, the customer portal
     * and transactional email all read them), so those are written through to
     * the columns rather than into design_tokens.
     */
    public function customize(Request $request)
    {
        $tenant = tenant();

        $data = $request->validate([
            'accent'            => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text'              => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg'                => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'surface'           => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'muted'             => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hero_bg'           => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hero_text'         => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_heading'      => ['nullable', 'string', 'max:40'],
            'font_body'         => ['nullable', 'string', 'max:40'],
            'heading_weight'    => ['nullable', 'integer', 'min:300', 'max:900'],
            'heading_transform' => ['nullable', 'in:none,uppercase'],
            'button_style'      => ['nullable', 'in:solid,outline,ghost'],
            'button_radius'     => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $template = $tenant->site_template
            ? (\\App\\Support\\SiteTemplate::tokens($tenant->site_template) ?? [])
            : [];

        // Preserve the template-revert snapshot; it is not a token.
        $stored = (array) ($tenant->design_tokens ?? []);
        $next   = array_key_exists('_prev', $stored) ? ['_prev' => $stored['_prev']] : [];

        $columns = ['accent' => 'accent_color', 'text' => 'text_color', 'bg' => 'bg_color',
                    'font_heading' => 'font_heading', 'font_body' => 'font_body'];

        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue; // absent = use the template default
            }

            if (isset($columns[$key])) {
                $tenant->{$columns[$key]} = $value;
                continue;
            }

            $default = $template[$key] ?? null;
            if ($default === null || (string) $default !== (string) $value) {
                $next[$key] = $value;
            }
        }

        $tenant->design_tokens = $next;
        $tenant->save();

        return redirect()->route('tenant.templates.index')
            ->with('flash', 'Your look has been saved and is live on your site.');
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + method
print("ok   customize()")

open(path, 'w').write(src)
PY

# ================================================================ route
python3 - "$ROUTES" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old = """            Route::post('/website/templates/revert',       [TenantControllers\\SiteTemplateController::class, 'revert'])->name('templates.revert');"""
new = """            Route::post('/website/templates/revert',       [TenantControllers\\SiteTemplateController::class, 'revert'])->name('templates.revert');
            Route::post('/website/templates/customize',    [TenantControllers\\SiteTemplateController::class, 'customize'])->name('templates.customize'); // MARKER-CUSTOMIZER"""
n = src.count(old)
if n != 1:
    print(f"FAIL route: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   route templates.customize")

open(path, 'w').write(src)
PY

# ================================================================ panel
python3 - "$INDEX" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

anchor = '<div class="tpl-grid">'
n = src.count(anchor)
if n != 1:
    print(f"FAIL panel: anchor found {n} times"); sys.exit(1)

panel = '''{{-- MARKER-CUSTOMIZER — live customizer. The preview is the SAME _thumb
     partial the cards use, so what you tune here is what a template promises.
     It repaints by setting --t-* on the preview root; nothing is saved until
     Save is pressed. --}}
@php
  $czTokens   = \\App\\Support\\DesignTokens::resolve($currentTenant);
  $czTemplate = $currentTenant->site_template
      ? (\\App\\Support\\SiteTemplate::tokens($currentTenant->site_template) ?? [])
      : [];
  $czLayout = $currentTenant->site_template
      ? (\\App\\Support\\SiteTemplate::find($currentTenant->site_template)['layout'] ?? [])
      : ($templates[array_key_first($templates)]['layout'] ?? []);
  $czFonts = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];
@endphp

<div class="cz-wrap">
  <div class="cz-stage">
    <div class="cz-stage-bar">
      <span class="cz-stage-title">Live preview</span>
      <span class="cz-dirty" id="cz-dirty">Unsaved changes</span>
    </div>
    <div class="cz-canvas">
      @include('tenant.templates._thumb', ['tokens' => $czTokens, 'layout' => $czLayout])
    </div>
  </div>

  <form method="POST" action="{{ route('tenant.templates.customize') }}" class="cz-panel" id="cz-form">
    @csrf
    <div class="cz-panel-head"><span class="cz-panel-title">Customize</span>
      @if($currentTenant->site_template)
        <span class="cz-base">based on {{ \\App\\Support\\SiteTemplate::name($currentTenant->site_template) }}</span>
      @endif
    </div>

    <div class="cz-scroll">
      @php
        $czGroups = [
          'Color'   => [['accent','Accent','color'],['text','Text','color'],['bg','Page background','color'],['surface','Cards & panels','color'],['muted','Secondary text','color']],
          'Hero'    => [['hero_bg','Hero background','color'],['hero_text','Hero text','color']],
          'Type'    => [['font_heading','Heading font','font'],['font_body','Body font','font'],['heading_weight','Heading weight','range'],['heading_transform','Heading case','case']],
          'Buttons' => [['button_style','Button style','style'],['button_radius','Button corners','range']],
        ];
      @endphp

      @foreach($czGroups as $czGroupName => $czFields)
        <details class="cz-group" {{ $loop->first ? 'open' : '' }}>
          <summary>{{ $czGroupName }}<span class="cz-gdot"></span></summary>
          <div class="cz-body">
            @foreach($czFields as [$czKey, $czLabel, $czType])
              @php
                $czVal = $czTokens[$czKey] ?? '';
                $czDef = $czTemplate[$czKey] ?? null;
              @endphp
              <div class="cz-row" data-k="{{ $czKey }}" data-default="{{ $czDef }}">
                <label class="cz-lbl">{{ $czLabel }}
                  <button type="button" class="cz-reset" title="Back to the template default">reset</button>
                </label>
                <div class="cz-ctl">
                  @if($czType === 'color')
                    <input type="text" class="cz-hex" value="{{ $czVal }}" data-role="hex" autocomplete="off">
                    <input type="color" class="cz-sw" value="{{ \\Illuminate\\Support\\Str::startsWith($czVal, '#') ? $czVal : '#ffffff' }}" data-role="pick">
                  @elseif($czType === 'font')
                    <select class="cz-select" data-role="val">
                      @foreach($czFonts as $czFont)
                        <option value="{{ $czFont }}" @selected($czVal === $czFont)>{{ $czFont }}</option>
                      @endforeach
                    </select>
                  @elseif($czType === 'range')
                    @php $czMin = $czKey === 'heading_weight' ? 300 : 0; $czMax = $czKey === 'heading_weight' ? 900 : 24; $czStep = $czKey === 'heading_weight' ? 100 : 1; @endphp
                    <input type="range" class="cz-range" min="{{ $czMin }}" max="{{ $czMax }}" step="{{ $czStep }}" value="{{ (int) $czVal }}" data-role="val">
                    <span class="cz-num">{{ (int) $czVal }}</span>
                  @elseif($czType === 'case')
                    <select class="cz-select" data-role="val">
                      <option value="none" @selected($czVal === 'none')>Normal</option>
                      <option value="uppercase" @selected($czVal === 'uppercase')>UPPERCASE</option>
                    </select>
                  @else
                    <select class="cz-select" data-role="val">
                      <option value="solid" @selected($czVal === 'solid')>Solid</option>
                      <option value="outline" @selected($czVal === 'outline')>Outline</option>
                      <option value="ghost" @selected($czVal === 'ghost')>Ghost</option>
                    </select>
                  @endif
                </div>
                <input type="hidden" name="{{ $czKey }}" value="{{ $czVal }}" data-role="field">
              </div>
            @endforeach
          </div>
        </details>
      @endforeach
    </div>

    <div class="cz-foot">
      <button type="button" class="ia-btn ia-btn--ghost" id="cz-reset-all">Reset all</button>
      <button type="submit" class="ia-btn ia-btn--primary">Save</button>
    </div>
  </form>
</div>

<div class="tpl-section-head">Start from a template</div>

<div class="tpl-grid">'''

src = src.replace(anchor, panel, 1)
print("ok   customizer panel")

# --- CSS
css_anchor = "</style>"
if src.count(css_anchor) < 1:
    print("FAIL panel css: no </style>"); sys.exit(1)

css = '''
/* MARKER-CUSTOMIZER */
.cz-wrap{display:grid;grid-template-columns:1fr 330px;gap:18px;align-items:start;margin-bottom:30px}
@media(max-width:1000px){.cz-wrap{grid-template-columns:1fr}}
.cz-stage{background:var(--ia-surface);border-radius:var(--ia-r-lg);box-shadow:inset 0 0 0 .5px var(--ia-border);overflow:hidden}
.cz-stage-bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
.cz-stage-title{font-size:12px;opacity:.55}
.cz-dirty{margin-left:auto;font-size:11.5px;color:var(--ia-accent);opacity:0;transition:opacity .12s}
.cz-dirty.on{opacity:1}
.cz-canvas{padding:18px;background:rgba(0,0,0,.28);display:flex;justify-content:center;overflow:hidden}
.cz-canvas .fs{width:100%;max-width:600px}
.cz-panel{background:var(--ia-surface);border-radius:var(--ia-r-lg);box-shadow:inset 0 0 0 .5px var(--ia-border);overflow:hidden;display:flex;flex-direction:column}
.cz-panel-head{display:flex;align-items:center;gap:8px;padding:13px 16px;border-bottom:.5px solid var(--ia-border)}
.cz-panel-title{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em}
.cz-base{margin-left:auto;font-size:11px;opacity:.5}
.cz-scroll{max-height:60vh;overflow-y:auto}
.cz-group{border-bottom:.5px solid var(--ia-border)}
.cz-group summary{padding:11px 16px;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.55;cursor:pointer;list-style:none;display:flex;align-items:center;gap:7px}
.cz-group summary::-webkit-details-marker{display:none}
.cz-group summary::before{content:'\\25B8';font-size:9px;opacity:.6}
.cz-group[open] summary::before{content:'\\25BE'}
.cz-gdot{margin-left:auto;width:6px;height:6px;border-radius:50%;background:var(--ia-accent);opacity:0}
.cz-group.is-edited .cz-gdot{opacity:1}
.cz-body{padding:4px 16px 14px}
.cz-row{display:flex;align-items:center;gap:10px;padding:7px 0;min-height:36px}
.cz-lbl{font-size:12.5px;flex:1;min-width:0;display:flex;align-items:center;gap:6px}
.cz-reset{border:0;background:none;color:var(--ia-accent);font:inherit;font-size:10.5px;cursor:pointer;opacity:0;padding:0;text-decoration:underline}
.cz-row.is-edited .cz-reset{opacity:.85}
.cz-ctl{flex:0 0 auto;display:flex;align-items:center;gap:8px}
.cz-hex{width:80px;font-family:ui-monospace,monospace;font-size:11.5px;padding:5px 7px;border-radius:6px;border:.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text)}
.cz-sw{width:30px;height:26px;padding:0;border:.5px solid var(--ia-border-strong);border-radius:6px;background:none;cursor:pointer}
.cz-select{font-size:12px;padding:5px 8px;border-radius:6px;border:.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);font-family:inherit}
.cz-range{width:100px;accent-color:var(--ia-accent)}
.cz-num{font-family:ui-monospace,monospace;font-size:11.5px;opacity:.6;width:30px;text-align:right}
.cz-foot{display:flex;gap:8px;justify-content:flex-end;padding:13px 16px;border-top:.5px solid var(--ia-border)}
.tpl-section-head{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin:0 0 12px}
'''
i = src.rindex(css_anchor)
src = src[:i] + css + src[i:]
print("ok   customizer css")

open(path, 'w').write(src)
PY

# --- JS appended at the end of the view
python3 - "$INDEX" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

js = '''

{{-- MARKER-CUSTOMIZER --}}
<script>
(function () {
  var form = document.getElementById('cz-form');
  var prev = document.querySelector('.cz-canvas [data-fs-preview]');
  if (!form || !prev) { return; }

  var dirty = document.getElementById('cz-dirty');

  // token key -> the CSS variable the preview paints from
  var VAR = {
    accent: '--t-accent', text: '--t-text', bg: '--t-bg', surface: '--t-surface',
    muted: '--t-muted', hero_bg: '--t-hero-bg', hero_text: '--t-hero-text',
    heading_weight: '--t-h-weight', heading_transform: '--t-h-case',
    button_radius: '--t-btn-r'
  };

  function contrast(hex) {
    var c = (hex || '').replace('#', '');
    if (c.length !== 6) { return '#111111'; }
    var r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111111' : '#ffffff';
  }

  function paint(key, value) {
    if (key === 'font_heading') { prev.style.setProperty('--t-f-head', "'" + value + "', sans-serif"); return; }
    if (key === 'font_body')    { prev.style.setProperty('--t-f-body', "'" + value + "', sans-serif"); return; }
    if (key === 'button_style') { return; } // needs a re-render; saved, not previewed
    if (key === 'button_radius'){ prev.style.setProperty(VAR[key], value + 'px'); return; }
    if (!VAR[key]) { return; }
    prev.style.setProperty(VAR[key], value);
    if (key === 'accent') { prev.style.setProperty('--t-accent-text', contrast(value)); }
  }

  function mark(row) {
    var field = row.querySelector('[data-role="field"]');
    var def   = row.getAttribute('data-default');
    var edited = def !== null && def !== '' && String(field.value) !== String(def);
    row.classList.toggle('is-edited', edited);

    var group = row.closest('.cz-group');
    if (group) {
      group.classList.toggle('is-edited', !!group.querySelector('.cz-row.is-edited'));
    }
    if (dirty) { dirty.classList.add('on'); }
  }

  function setRow(row, value, silent) {
    var key   = row.getAttribute('data-k');
    var field = row.querySelector('[data-role="field"]');
    field.value = value;

    var hex = row.querySelector('[data-role="hex"]');
    var pick = row.querySelector('[data-role="pick"]');
    var val = row.querySelector('[data-role="val"]');
    var num = row.querySelector('.cz-num');

    if (hex) { hex.value = value; }
    if (pick && /^#[0-9a-fA-F]{6}$/.test(value)) { pick.value = value; }
    if (val) { val.value = value; }
    if (num) { num.textContent = value; }

    paint(key, value);
    if (!silent) { mark(row); }
  }

  document.querySelectorAll('.cz-row').forEach(function (row) {
    var hex  = row.querySelector('[data-role="hex"]');
    var pick = row.querySelector('[data-role="pick"]');
    var val  = row.querySelector('[data-role="val"]');

    if (hex)  { hex.addEventListener('input', function () {
      if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) { setRow(row, hex.value); }
    }); }
    if (pick) { pick.addEventListener('input', function () { setRow(row, pick.value); }); }
    if (val)  { val.addEventListener('input', function () { setRow(row, val.value); }); }

    row.querySelector('.cz-reset').addEventListener('click', function () {
      var def = row.getAttribute('data-default');
      if (def !== null && def !== '') { setRow(row, def); }
    });

    mark(row);
  });

  if (dirty) { dirty.classList.remove('on'); }

  document.getElementById('cz-reset-all').addEventListener('click', function () {
    document.querySelectorAll('.cz-row').forEach(function (row) {
      var def = row.getAttribute('data-default');
      if (def !== null && def !== '') { setRow(row, def); }
    });
  });
})();
</script>
'''

open(path, 'w').write(src.rstrip() + js)
print("ok   customizer js")
PY

php -l "$CTRL"

echo ""
echo "SUCCESS — apply-template-customizer applied."
echo "Deploy's optimize covers route + view cache."
