#!/usr/bin/env bash
set -euo pipefail
# apply-customizer-fixes.sh — MARKER-CZFIX
# Two bugs Josh hit immediately after MARKER-CUSTOMIZER shipped. Both come from
# the same wrong assumption: that a tenant always has a template applied.
#
# 1) SAVE SILENTLY DID NOTHING. With no template, surface and muted resolve to
#    the FALLBACKS 'rgba(0,0,0,.03)' / 'rgba(0,0,0,.5)'. Those get posted in the
#    hidden fields, but customize() validated every colour as hex-only — so the
#    whole request failed validation and NOTHING saved, including colours that
#    were changed. Worse, the templates page renders no error block, so it
#    failed with no visible sign. Fixes: validation accepts hex OR rgb()/rgba(),
#    and the page now renders validation errors + the success flash.
#
# 2) RESET DID NOTHING. Per-control reset and Reset all both read data-default,
#    which is the active template's token — empty when there is no template. So
#    every row was skipped. Defaults now fall back to DesignTokens::FALLBACKS,
#    which is the honest answer to "what would this be if I hadn't touched it".
#
# Also: the colour swatch showed white for any rgba token because <input
# type="color"> can only hold hex. DesignTokens::toHex() now flattens rgba over
# the page background for DISPLAY only — what gets saved is unchanged.

SUP=app/Support/DesignTokens.php
CTRL=app/Http/Controllers/Tenant/SiteTemplateController.php
INDEX=resources/views/tenant/templates/index.blade.php

for f in "$SUP" "$CTRL" "$INDEX"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

grep -q "MARKER-CUSTOMIZER" "$INDEX" \
  || { echo "PRECONDITION FAILED: deploy apply-template-customizer.sh first"; exit 1; }

if grep -q "MARKER-CZFIX" "$CTRL"; then
  echo "Already applied (MARKER-CZFIX present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- toHex helper
python3 - "$SUP" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

tail = src.rstrip()
if not tail.endswith('}'):
    print("FAIL toHex: file does not end with }"); sys.exit(1)

helper = '''
    /**
     * MARKER-CZFIX — flatten a colour to a 6-digit hex for DISPLAY only.
     *
     * <input type="color"> can only hold hex, so an rgba() token (which is what
     * surface/muted/border fall back to) showed as white in the customizer.
     * This composites rgba over the given background so the swatch tells the
     * truth. The stored value is never changed by this.
     */
    public static function toHex(?string $value, string $over = '#ffffff'): string
    {
        $value = trim((string) $value);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m)) {
            return '#' . preg_replace('/(.)/', '$1$1', $m[1]);
        }

        if (preg_match('/^rgba?\\(([^)]+)\\)$/i', $value, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            $r = (int) ($parts[0] ?? 0);
            $g = (int) ($parts[1] ?? 0);
            $b = (int) ($parts[2] ?? 0);
            $a = isset($parts[3]) ? (float) $parts[3] : 1.0;
            $a = max(0.0, min(1.0, $a));

            $bg = preg_match('/^#[0-9a-fA-F]{6}$/', $over) ? $over : '#ffffff';
            $br = hexdec(substr($bg, 1, 2));
            $bgc = hexdec(substr($bg, 3, 2));
            $bb = hexdec(substr($bg, 5, 2));

            return sprintf(
                '#%02x%02x%02x',
                (int) round($r * $a + $br * (1 - $a)),
                (int) round($g * $a + $bgc * (1 - $a)),
                (int) round($b * $a + $bb * (1 - $a))
            );
        }

        return '#ffffff';
    }
}
'''
src = tail[:-1].rstrip('\n') + '\n' + helper
open(path, 'w').write(src)
print("ok   DesignTokens::toHex()")
PY

# ---------------------------------------------------------------- validation
python3 - "$CTRL" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()

# MARKER-CZFIX — accept rgb()/rgba() as well as hex, so the seeded fallback
# values round-trip instead of failing the whole request.
old_rule = "['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/']"
new_rule = "['nullable', 'string', 'regex:/^(#[0-9a-fA-F]{6}|rgba?\\([\\d.,\\s]+\\))$/']"

# MARKER-CZFIX marker goes on its OWN line — an inline // comment after the
# rule array would swallow the element's trailing comma and fatal the file.
anchor = "        $data = $request->validate(["
if src.count(anchor) != 1:
    print("FAIL validate anchor"); sys.exit(1)
src = src.replace(anchor, "        // MARKER-CZFIX — colours may be hex OR rgba (the seeded fallbacks).\n" + anchor, 1)

n = src.count(old_rule)
if n < 5:
    print(f"FAIL validation: expected the colour rules, found {n}"); sys.exit(1)
src = src.replace(old_rule, new_rule)
print(f"ok   colour validation accepts rgba ({n} rules)")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- page fixes
python3 - "$INDEX" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def edit(old, new, label):
    global src
    n = src.count(old)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    src = src.replace(old, new, 1)
    print(f"ok   {label}")

# 1) defaults fall back to FALLBACKS so reset works with no template applied
edit("""                $czDef = $czTemplate[$czKey] ?? null;""",
"""                /* MARKER-CZFIX — with no template applied there is no
                   template default, and reset had nothing to reset TO. The
                   pipeline fallback is the honest answer. */
                $czDef = $czTemplate[$czKey] ?? (\\App\\Support\\DesignTokens::FALLBACKS[$czKey] ?? null);""",
"reset defaults")

# 2) the colour swatch tells the truth for rgba tokens
edit("""                    <input type="color" class="cz-sw" value="{{ \\Illuminate\\Support\\Str::startsWith($czVal, '#') ? $czVal : '#ffffff' }}" data-role="pick">""",
"""                    <input type="color" class="cz-sw" value="{{ \\App\\Support\\DesignTokens::toHex($czVal, \\App\\Support\\DesignTokens::toHex($czTokens['bg'] ?? '#ffffff')) }}" data-role="pick"> {{-- MARKER-CZFIX --}}""",
"swatch flatten")

# 3) a failed save must be visible — this is what made it look like nothing
#    happened at all
edit("""<div class="cz-wrap">""",
"""{{-- MARKER-CZFIX — without this a validation failure was completely silent --}}
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    Couldn't save your changes: {{ $errors->first() }}
  </div>
@endif
@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('flash') }}</div>
@endif
@if(session('flash_error'))
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('flash_error') }}</div>
@endif

<div class="cz-wrap">""",
"error + flash rendering")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- js
python3 - "$INDEX" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

# The JS guard skipped any row whose default was blank. Defaults are now always
# present, but keep the guard honest: reset should run whenever a default
# exists, and marking should compare against it.
old = """    row.querySelector('.cz-reset').addEventListener('click', function () {
      var def = row.getAttribute('data-default');
      if (def !== null && def !== '') { setRow(row, def); }
    });"""
new = """    row.querySelector('.cz-reset').addEventListener('click', function () {
      var def = row.getAttribute('data-default');
      if (def) { setRow(row, def); } // MARKER-CZFIX
    });"""
n = src.count(old)
if n != 1:
    print(f"FAIL js reset: anchor found {n} times"); sys.exit(1)
src = src.replace(old, new, 1)
print("ok   js per-control reset")

old_all = """    document.querySelectorAll('.cz-row').forEach(function (row) {
      var def = row.getAttribute('data-default');
      if (def !== null && def !== '') { setRow(row, def); }
    });"""
new_all = """    document.querySelectorAll('.cz-row').forEach(function (row) {
      var def = row.getAttribute('data-default');
      if (def) { setRow(row, def); } // MARKER-CZFIX
    });"""
n = src.count(old_all)
if n != 1:
    print(f"FAIL js reset all: anchor found {n} times"); sys.exit(1)
src = src.replace(old_all, new_all, 1)
print("ok   js reset all")

# A non-hex value can't paint the type=color input; guard so the text field
# still drives the preview.
old_pick = """    if (pick && /^#[0-9a-fA-F]{6}$/.test(value)) { pick.value = value; }"""
if src.count(old_pick) != 1:
    print("FAIL js pick guard: anchor not unique"); sys.exit(1)
print("ok   js pick guard already correct")

open(path, 'w').write(src)
PY

php -l "$SUP"
php -l "$CTRL"

echo ""
echo "SUCCESS — apply-customizer-fixes applied."
echo "Deploy's optimize covers the view cache."
