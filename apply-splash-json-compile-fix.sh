#!/bin/bash
# apply-splash-json-compile-fix.sh
#
# MARKER-SPLASH-2-JSONFIX — HOTFIX: /admin/pages threw a 500.
#
#   Unclosed '[' on line 244 does not match ')'
#   (View: resources/views/tenant/pages/index.blade.php)
#
# My bug. Blade parses a directive's argument by matching parentheses, and
# it cannot handle a MULTI-LINE array literal inside them:
#
#     @json($pages->map(fn ($p) => [
#         'id' => $p->id,          <-- the '[' is still open when the
#     ])->values())                    parser reaches a ')'
#
# It matched the closing ')' against the open '[' and gave up. Nothing about
# the PHP is wrong — it is purely how the directive argument is scanned.
#
# WHY MY CHECKS MISSED IT, worth fixing in the habit and not just the file:
# I verified balanced @if/@php/@push counts and ran node --check on the
# script body, but neither COMPILES the template. A Blade directive-argument
# error only appears when the view is compiled, which nothing in my
# toolchain did. The sweep at the end of this script is the cheap stand-in.
#
# THE FIX: build the arrays in a @php block, then hand @json a plain
# variable. Same output, and the directive argument becomes a single token
# that cannot be misparsed.
set -e

MARKER="MARKER-SPLASH-2-JSONFIX"
V="resources/views/tenant/pages/index.blade.php"

[ -f "$V" ] || { echo "ERROR: run from the repo root"; exit 1; }
grep -q "MARKER-SPLASH-2-UI" "$V" || { echo "ERROR: requires apply-splash-pairings-ui.sh"; exit 1; }
if grep -q "$MARKER" "$V" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/pages/index.blade.php'
src = io.open(p, encoding='utf-8').read()

old = """  var PAGES = @json($splashablePages->map(fn ($p) => [
        'id' => $p->id, 'title' => $p->title,
        'path' => $p->is_home ? '/' : '/' . $p->slug,
      ])->values());

  var rows = @json($splashRows->map(fn ($p) => [
        'visit_page_id'  => $p->id,
        'splash_page_id' => $p->splash_page_id,
        'mode'           => $p->splash_mode ?: 'overlay',
        'style'          => $p->splash_style ?: 'full',
        'frequency'      => (string) ($p->splash_frequency ?: 'session'),
        'starts_at'      => optional($p->splash_starts_at)->format('Y-m-d') ?? '',
        'ends_at'        => optional($p->splash_ends_at)->format('Y-m-d') ?? '',
      ])->values());"""
assert src.count(old) == 1, 'json blocks not found'

new = """  var PAGES = @json($spPagesJs);
  var rows  = @json($spRowsJs);"""
src = src.replace(old, new, 1)

# Same class of bug, quieter: ONE comma reassembles into valid PHP but
# REPLACES Blade's encoding options, so the value stops being escaped for
# embedding in a <script>. Hand it a variable too.
r_old = "  var PREVIEW = @json(route('tenant.pages.preview', ['id' => '__ID__']));"
assert src.count(r_old) == 1, 'preview route json'
src = src.replace(r_old, "  var PREVIEW = @json($spPreviewUrl);", 1)

# The arrays move above the script. @push content is compiled in place, so
# anything defined here is in scope inside it.
a = """@push('scripts')
<script>
(function () {"""
assert src.count(a) == 1, 'push anchor'
src = src.replace(a, """@push('scripts')
@php
  // MARKER-SPLASH-2-JSONFIX — computed here so @json() receives a single
  // variable. A multi-line array literal inside a Blade directive's
  // parentheses cannot be parsed and fatals the whole view.
  $spPagesJs = $splashablePages->map(function ($p) {
      return [
          'id'    => $p->id,
          'title' => $p->title,
          'path'  => $p->is_home ? '/' : '/' . $p->slug,
      ];
  })->values();

  $spRowsJs = $splashRows->map(function ($p) {
      return [
          'visit_page_id'  => $p->id,
          'splash_page_id' => $p->splash_page_id,
          'mode'           => $p->splash_mode ?: 'overlay',
          'style'          => $p->splash_style ?: 'full',
          'frequency'      => (string) ($p->splash_frequency ?: 'session'),
          'starts_at'      => $p->splash_starts_at ? $p->splash_starts_at->format('Y-m-d') : '',
          'ends_at'        => $p->splash_ends_at ? $p->splash_ends_at->format('Y-m-d') : '',
      ];
  })->values();

  $spPreviewUrl = route('tenant.pages.preview', ['id' => '__ID__']);
@endphp
<script>
(function () {""", 1)

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: @json now receives plain variables')
PY

# ---------------------------------------------------------------
# Sweep: no OTHER view has a multi-line array inside a directive's parens
# ---------------------------------------------------------------
echo ""
echo "-- sweep: @json() arguments containing commas --"
echo "   Blade compiles @json with explode(',') and keeps only 3 parts:"
echo "     >2 commas  = expression TRUNCATED, the view fatals"
echo "     1-2 commas = reassembles by luck, but Blade's HTML-escaping"
echo "                  options get replaced by the fragment"
python3 - <<'SWEEP'
import re, glob

fatal, lossy = [], []
for path in glob.glob('resources/views/**/*.blade.php', recursive=True):
    src = open(path, encoding='utf-8', errors='replace').read()
    for m in re.finditer(r'@json\s*\(', src):
        i = m.end() - 1
        depth = 0
        j = i
        while j < len(src):
            if src[j] == '(':
                depth += 1
            elif src[j] == ')':
                depth -= 1
                if depth == 0:
                    break
            j += 1
        arg = src[i + 1:j]
        commas = arg.count(',')
        line = src[:i].count('\n') + 1
        if commas > 2:
            fatal.append(f'{path}:{line}  ({commas} commas - FATAL)')
        elif commas > 0:
            lossy.append(f'{path}:{line}  ({commas} comma(s) - renders, loses escaping)')

if fatal:
    print('  FATAL:')
    for b in fatal:
        print('   ', b)
else:
    print('  no fatal @json arguments')

if lossy:
    print('  worth tidying later:')
    for b in lossy:
        print('   ', b)
SWEEP

echo ""
echo "== splash json compile fix applied =="
echo "Post-deploy: php artisan optimize:clear (compiled views are cached)"
