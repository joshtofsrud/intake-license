#!/usr/bin/env bash
# apply-remove-notify-modal.sh
# MARKER-NOTIFY-MODAL-REMOVED — drop the second modal.
#
# The create modal saved the appointment and then stacked a notify chooser
# on top at z-index 500, under the modal's own 9999 — so it rendered behind
# what you were looking at and the submit button, which deliberately never
# resets on success, sat on "Creating…" forever.
#
# Rather than fix the z-index, the modal goes away entirely: creating an
# appointment now redirects straight to the appointment page. Notifying
# becomes a deliberate act on that page, which is the point — a work-up or
# a quote should never reach the customer as a side effect of saving.
#
# askNotify() is removed rather than left unreferenced. The controller's
# notify_url / notify payload stays: it is what the Send confirmation
# button on the appointment page will consume.
#
# View-only change — view:clear is enough, no migration, no route cache.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/appointments/_create_modal.blade.php'
s = io.open(p, encoding='utf-8').read()

# ---- 1. call site: go straight to the appointment
old = """      if (res.ok && res.body.ok) {
        // MARKER-NOTIFY-MODAL — saved. Nothing has been sent; ask first.
        askNotify(res.body);
        return;
      }"""
assert s.count(old) == 1, 'M1 submit success anchor'
s = s.replace(old, """      if (res.ok && res.body.ok) {
        // MARKER-NOTIFY-MODAL-REMOVED — saved, and nobody has been told.
        // Straight to the appointment; notifying is a deliberate action
        // there, never a side effect of creating the record.
        if (res.body.redirect) { window.location.href = res.body.redirect; }
        else { window.location.reload(); }
        return;
      }""")

# ---- 2. remove askNotify() by brace-matching, so no giant literal anchor
start_marker = '  function askNotify(body) {'
i = s.find(start_marker)
assert i != -1, 'M2 askNotify not found'
assert s.count(start_marker) == 1, 'M2 askNotify not unique'

# Walk from the opening brace, skipping strings and comments, to its match.
j = s.index('{', i)
depth, k, n = 0, j, len(s)
while k < n:
    c = s[k]
    if c == '/' and k + 1 < n and s[k+1] == '/':
        while k < n and s[k] != '\n':
            k += 1
        continue
    if c == '/' and k + 1 < n and s[k+1] == '*':
        k += 2
        while k + 1 < n and not (s[k] == '*' and s[k+1] == '/'):
            k += 1
        k += 2
        continue
    if c in '"\'':
        q = c
        k += 1
        while k < n and s[k] != q:
            if s[k] == '\\':
                k += 1
            k += 1
        k += 1
        continue
    if c == '{':
        depth += 1
    elif c == '}':
        depth -= 1
        if depth == 0:
            k += 1
            break
    k += 1

assert depth == 0, 'M3 could not brace-match askNotify'

# Take the preceding comment block with it.
head = s[:i]
lead = head.rfind('  // \u2500\u2500 Submit \u2500\u2500')
if lead == -1:
    lead = i
removed = s[lead:k]
assert 'askNotify' in removed and len(removed) > 400, 'M4 removal slice looks wrong'

s = s[:lead] + """  // \u2500\u2500 Submit \u2500\u2500
  // MARKER-NOTIFY-MODAL-REMOVED — askNotify() lived here and is gone.
  // Creating an appointment redirects to the appointment page; the
  // Send confirmation action belongs there, not in a stacked overlay.
""" + s[k:]

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
print('removed %d chars of askNotify()' % len(removed))
PY

echo "--- askNotify should now appear only in the tombstone comment ---"
grep -n "askNotify" resources/views/tenant/appointments/_create_modal.blade.php || echo "(no references)"

echo
echo "--- script blocks parse ---"
python3 - <<'PY'
import io, re, os, subprocess
p = 'resources/views/tenant/appointments/_create_modal.blade.php'
s = io.open(p, encoding='utf-8').read()
def stub(js):
    js = re.sub(r'\{\{--.*?--\}\}', '', js, flags=re.S)
    js = re.sub(r'\{!!.*?!!\}', '""', js, flags=re.S)
    out, i = [], 0
    while i < len(js):
        if js.startswith('@json(', i):
            d, j = 0, i + 5
            while j < len(js):
                if js[j] == '(': d += 1
                elif js[j] == ')':
                    d -= 1
                    if d == 0: break
                j += 1
            out.append('"STUB"'); i = j + 1; continue
        out.append(js[i]); i += 1
    js = ''.join(out)
    js = re.sub(r'\{\{.*?\}\}', '"STUB"', js, flags=re.S)
    js = re.sub(r'^\s*@(if|elseif|else|endif|foreach|endforeach|php|endphp|unless|endunless)\b.*$', '', js, flags=re.M)
    return js
os.makedirs('/tmp/nm', exist_ok=True)
blocks = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', s, flags=re.S)
for n, b in enumerate(blocks):
    f = '/tmp/nm/b%d.js' % n
    io.open(f, 'w', encoding='utf-8').write(stub(b))
    r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
    print('block %d: %s' % (n, 'OK' if r.returncode == 0 else 'FAIL\n' + r.stderr[:500]))
PY

echo
echo "apply-remove-notify-modal: OK"
