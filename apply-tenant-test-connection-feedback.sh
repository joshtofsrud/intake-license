#!/usr/bin/env bash
# apply-tenant-test-connection-feedback.sh
# MARKER-TENANT-TEST-FEEDBACK — the button that actually feels broken.
#
# I patched the master-admin Filament page first. Wrong surface — the one
# in use is tenant admin, and it isn't Filament at all: it's a plain submit
# button using formaction to POST to connection.test. A full page submit
# with a 30 second server round trip and zero feedback. The browser shows
# its own tab spinner and nothing else happens, which is precisely why it
# reads as hung.
#
# Measured, so the copy isn't a guess (production server, Aug 1):
#
#     GET /inventory?type=json     200, 3,848,122 bytes, ~22s
#     with Range: bytes=0-2047     identical — BTI ignores Range
#     HEAD                         200, ~19.5s — body still built
#     time_starttransfer           24.8s of a 28.4s total
#
# BTI renders the whole feed before sending a byte, so no client-side trick
# helps. The only honest fix is to say so.
#
# What this does, on submit only for the Test button:
#   * disables it and swaps the label for a spinner + "Checking… about 30s"
#   * reveals a line under the row explaining why
#   * disables Save too, so a second submit can't race the first
#
# BTI-specific: HLC and QBP probe System/Echo and answer in a second or
# two, so they get a plain "Checking…" with no 30-second claim. Promising
# a long wait that doesn't happen is its own kind of wrong.
#
# View-only: view:clear is enough.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """        <div style=\"display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;align-items:center\">
          <button class=\"dc-btn primary\" type=\"submit\">Save</button>
          <button class=\"dc-btn\" type=\"submit\"
                  formaction=\"{{ route('tenant.distributors.connection.test') }}\">Test connection</button>

        </div>"""
assert s.count(old) == 1, 'T1 button row anchor'
s = s.replace(old, """        {{-- MARKER-TENANT-TEST-FEEDBACK — a 30s form post with no feedback
             reads as a hung page. Say what's happening and why. --}}
        <div style=\"display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;align-items:center\">
          <button class=\"dc-btn primary\" type=\"submit\" data-dc-save>Save</button>
          <button class=\"dc-btn\" type=\"submit\" data-dc-test
                  data-dc-slow=\"{{ strtoupper($b['code']) === 'BTI' ? '1' : '0' }}\"
                  formaction=\"{{ route('tenant.distributors.connection.test') }}\">Test connection</button>
        </div>
        <div data-dc-testnote
             style=\"display:none;font-size:11.5px;color:var(--ia-text-dim);margin-top:9px;line-height:1.5\"></div>""")

# One script for the page, appended inside the section.
old = """      </form>
    </div>
  @endforeach"""
assert s.count(old) == 1, 'T2 form close anchor'
s = s.replace(old, """      </form>
    </div>
  @endforeach

  {{-- MARKER-TENANT-TEST-FEEDBACK --}}
  <script>
  (function () {
    document.querySelectorAll('[data-dc-test]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        if (!form) { return; }

        var slow = btn.getAttribute('data-dc-slow') === '1';
        var note = form.querySelector('[data-dc-testnote]');
        var save = form.querySelector('[data-dc-save]');

        // Let the submit proceed, then lock the row down on the next tick —
        // disabling before submit would drop the button's formaction.
        setTimeout(function () {
          btn.disabled = true;
          btn.textContent = slow ? 'Checking\\u2026 about 30s' : 'Checking\\u2026';
          if (save) { save.disabled = true; }
          if (note) {
            note.style.display = '';
            note.textContent = slow
              ? 'BTI has no status endpoint \\u2014 the only address that answers rebuilds their entire stock feed on every request, so this takes about 30 seconds. Nothing is wrong; the page will reload with the result.'
              : 'Sending one authenticated request to confirm the credentials work.';
          }
        }, 0);
      });
    });
  })();
  </script>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- markup ---"
grep -n "data-dc-test\|data-dc-save\|data-dc-slow\|data-dc-testnote" resources/views/tenant/distributors/connection.blade.php

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
raw = io.open('resources/views/tenant/distributors/connection.blade.php', encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', '', raw, flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|forelse|endforelse)\b', s)))
for a, b in [('@if','@endif'), ('@foreach','@endforeach'), ('@php','@endphp'), ('@forelse','@endforelse')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')

blocks = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', raw, flags=re.S)
os.makedirs('/tmp/dc', exist_ok=True)
for n, bl in enumerate(blocks):
    bl = re.sub(r'\{\{--.*?--\}\}', '', bl, flags=re.S)
    bl = re.sub(r'\{\{.*?\}\}', '"S"', bl, flags=re.S)
    f = '/tmp/dc/b%d.js' % n
    io.open(f, 'w', encoding='utf-8').write(bl)
    r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
    print('script %d: %s' % (n, 'OK' if r.returncode == 0 else 'FAIL\n' + r.stderr[:400]))
PY

echo
echo "apply-tenant-test-connection-feedback: OK"
