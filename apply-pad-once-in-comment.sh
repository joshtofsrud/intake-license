#!/usr/bin/env bash
# apply-pad-once-in-comment.sh
# MARKER-PAD-COMMENT-DIRECTIVE — a directive written inside a JS comment.
#
#   syntax error, unexpected end of file, expecting "elseif" or "else" or
#   "endif" (View: .../_notes-pad.blade.php)
#
# The file has ONE @endonce and TWO things Blade compiled as @once: the real
# directive, and the word inside a JavaScript comment explaining what it does.
# That leaves an `if` open at the end of the compiled view, which takes down
# every tenant page, because the pad renders in both headers.
#
# BLADE DOES NOT CARE WHERE THE TEXT IS. It compiles the file as text before
# any of it is HTML or JavaScript — being inside <script>, inside /* */, or
# inside a string makes no difference. I checked this exact line earlier and
# reasoned it was safe "because it is inside a script block". That reasoning
# was wrong, and the check I wrote to prove it encoded the same mistake.
#
# The fix is to stop writing directive names in prose. The comment says the
# same thing without the @.
set -e

python3 <<'PY'
import io, re

p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """     @once emits this block at the FIRST include (the mobile header), and the
     desktop attention row is further down the page. Running immediately meant
     querySelectorAll found only the instance that had already been parsed,
     leaving the desktop button with no handler at all. */"""
assert s.count(old) == 1, 'C1 comment anchor'
s = s.replace(old, """     MARKER-PAD-COMMENT-DIRECTIVE — the once-directive emits this block at
     the FIRST include (the mobile header), and the desktop attention row is
     further down the page. Running immediately meant querySelectorAll found
     only the instance already parsed, leaving the desktop button with no
     handler at all.

     NOTE: that directive is deliberately NOT written by name here. Blade
     compiles the file as text before any of it is script or comment, so the
     word in prose became a second opening directive against one closer and
     took every tenant page down. */""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- Blade-compilable directives, counted the way Blade counts them ---"
python3 - <<'PY'
import io, re

f = 'resources/views/layouts/tenant/_notes-pad.blade.php'
raw = io.open(f, encoding='utf-8').read()

# Blade strips its OWN comments before compiling statements. Nothing else is
# stripped — not <script>, not /* */, not quoted strings.
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' ' * len(m.group(0)), raw, flags=re.S)

# compileStatements: /\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ... \))?/x
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)')

OPEN  = {'if', 'unless', 'isset', 'empty', 'auth', 'guest', 'forelse', 'foreach',
         'for', 'while', 'php', 'section', 'error', 'once', 'push', 'prepend'}
CLOSE = {'endif', 'endunless', 'endisset', 'endempty', 'endauth', 'endguest',
         'endforelse', 'endforeach', 'endfor', 'endwhile', 'endphp', 'endsection',
         'enderror', 'endonce', 'endpush', 'endprepend'}

counts = {}
for m in pat.finditer(s):
    name = m.group(1)
    if name in OPEN or name in CLOSE:
        counts.setdefault(name, []).append(s[:m.start()].count('\n') + 1)

pairs = [('if','endif'), ('unless','endunless'), ('forelse','endforelse'),
         ('foreach','endforeach'), ('php','endphp'), ('once','endonce')]

bad = False
for o, c in pairs:
    # @empty inside a forelse is a branch marker, not an opener.
    no = len(counts.get(o, []))
    nc = len(counts.get(c, []))
    if no or nc:
        ok = (no == nc)
        if not ok: bad = True
        print('  @%-8s %d  @%-11s %d  %s' % (o, no, c, nc, 'OK' if ok else '*** MISMATCH ***'))
        if not ok:
            print('       opens at lines:', counts.get(o, []))
            print('       closes at lines:', counts.get(c, []))

print('  ', 'balanced' if not bad else '*** STILL BROKEN ***')
assert not bad
PY

echo
echo "--- no directive names left in prose or comments ---"
python3 - <<'PY'
import io, re
raw = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' ' * len(m.group(0)), raw, flags=re.S)

# Only BLOCK directives matter here. An expression directive like @json is
# deliberate inside a script — it emits a value. A block directive emits an
# `if`/`foreach`, and one written in prose is what broke the view.
BLOCK = {'if','unless','isset','empty','auth','guest','forelse','foreach','for',
         'while','php','section','error','once','push','prepend',
         'endif','endunless','endisset','endempty','endauth','endguest',
         'endforelse','endforeach','endfor','endwhile','endphp','endsection',
         'enderror','endonce','endpush','endprepend','else','elseif'}
spans = [(m.start(), m.end()) for m in re.finditer(r'<script[^>]*>.*?</script>', s, re.S)]
hits = []
for m in re.finditer(r'\B@(@?\w+)', s):
    if m.group(1) in BLOCK and any(a <= m.start() < b for a, b in spans):
        hits.append((s[:m.start()].count('\n') + 1, m.group(0)))
print('  block directives inside <script>:', hits or 'none')
assert not hits, 'Blade compiles these and JavaScript does not want them'
PY

echo
echo "--- js still parses ---"
python3 - <<'PY'
import io, re, subprocess, os
raw = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]
out, i = [], 0
while i < len(js):
    if js.startswith('@json(', i):
        d = 0; j = i + 5
        while j < len(js):
            if js[j] == '(': d += 1
            elif js[j] == ')':
                d -= 1
                if d == 0: break
            j += 1
        out.append('"/x"'); i = j + 1
    else:
        out.append(js[i]); i += 1
os.makedirs('/tmp/padc', exist_ok=True)
io.open('/tmp/padc/p.js','w',encoding='utf-8').write(''.join(out))
r = subprocess.run(['node','--check','/tmp/padc/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode == 0 else 'FAIL\n' + r.stderr[:300])
PY

echo
echo "apply-pad-once-in-comment: OK"
