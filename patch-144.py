#!/usr/bin/env python3
"""
Patch 144 — fix the test-send button on /admin/settings.

The test-send <form> in patch 143 was nested inside the outer settings
<form>. Browsers don't allow nested forms — the inner one is silently
absorbed, so clicking "Send test email" submitted the outer settings
form instead of POSTing to the test endpoint.

Fix: replace the inner <form> with a plain block that uses fetch() to
POST to the test endpoint asynchronously. No nesting problem, and the
user gets inline feedback (success / error) right where the button is
instead of triggering a page reload that fires the wrong action.

Idempotent.
"""

import argparse
import pathlib
import sys


OLD_BLOCK = '''      {{-- MARKER-PATCH-143 — Test send block --}}
      <div style="margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)">
        <div style="font-size:13px;font-weight:500;margin-bottom:6px">Test your email setup</div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <form method="POST" action="{{ route('tenant.settings.email.test') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          @csrf
          <input type="email" name="recipient" class="ia-input" style="flex:1;min-width:240px"
            placeholder="recipient@example.com"
            value="{{ Auth::guard('tenant')->user()->email ?? '' }}" required>
          <button type="submit" class="ia-btn ia-btn--ghost ia-btn--sm">Send test email</button>
        </form>
      </div>'''

NEW_BLOCK = '''      {{-- MARKER-PATCH-144 — Test send block (no nested form, uses fetch) --}}
      <div style="margin-top:14px;padding:14px;background:rgba(190,242,100,.06);border:1px solid rgba(190,242,100,.18);border-radius:var(--ia-r-md)" id="email-test-block">
        <div style="font-size:13px;font-weight:500;margin-bottom:6px">Test your email setup</div>
        <div style="font-size:12px;color:var(--ia-text-dim);margin-bottom:10px;line-height:1.55">
          Save any changes above first. Then enter a recipient and send a test email to verify the From name and reply-to look right.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" id="email-test-recipient" class="ia-input" style="flex:1;min-width:240px"
            placeholder="recipient@example.com"
            value="{{ Auth::guard('tenant')->user()->email ?? '' }}">
          <button type="button" id="email-test-btn" class="ia-btn ia-btn--ghost ia-btn--sm">Send test email</button>
        </div>
        <div id="email-test-result" style="margin-top:10px;font-size:12px;display:none"></div>
      </div>
      <script>
        (function() {
          const btn = document.getElementById('email-test-btn');
          const recipient = document.getElementById('email-test-recipient');
          const result = document.getElementById('email-test-result');
          if (!btn) return;
          btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const r = (recipient.value || '').trim();
            if (!r) {
              result.style.display = 'block';
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Enter a recipient email first.';
              return;
            }
            btn.disabled = true;
            btn.textContent = 'Sending…';
            result.style.display = 'block';
            result.style.color = 'var(--ia-text-dim)';
            result.textContent = 'Sending test email to ' + r + '…';
            try {
              const resp = await fetch('{{ route('tenant.settings.email.test') }}', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/x-www-form-urlencoded',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  'X-Requested-With': 'XMLHttpRequest',
                  'Accept': 'application/json'
                },
                body: 'recipient=' + encodeURIComponent(r)
              });
              if (resp.ok) {
                result.style.color = 'var(--ia-ok, #86EFAC)';
                result.textContent = 'Sent to ' + r + '. Check the inbox (and spam folder) within ~1 minute.';
              } else {
                const body = await resp.text();
                result.style.color = 'var(--ia-bad, #F87171)';
                result.textContent = 'Send failed (HTTP ' + resp.status + '). Check logs for details.';
              }
            } catch (err) {
              result.style.color = 'var(--ia-bad, #F87171)';
              result.textContent = 'Send failed: ' + err.message;
            } finally {
              btn.disabled = false;
              btn.textContent = 'Send test email';
            }
          });
        })();
      </script>'''


# Controller needs to return JSON when requested via XHR so the fetch
# response.ok check is meaningful.
OLD_CTRL = """    public function sendSettingsTest(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'recipient' => ['nullable', 'email', 'max:255'],
        ]);

        $recipient = $data['recipient'] ?? $me->email;
        $result = $this->tests->sendSettingsTest(tenant(), $recipient);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }"""

NEW_CTRL = """    // MARKER-PATCH-144 — JSON response for XHR, fallback redirect for non-XHR
    public function sendSettingsTest(Request $request)
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => 'Manager or owner access required.'], 403);
            }
            return back()->with('error', 'Manager or owner access required.');
        }

        $data = $request->validate([
            'recipient' => ['nullable', 'email', 'max:255'],
        ]);

        $recipient = $data['recipient'] ?? $me->email;
        $result = $this->tests->sendSettingsTest(tenant(), $recipient);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($result, $result['ok'] ? 200 : 500);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }"""


EDITS = [
    ('resources/views/tenant/settings/index.blade.php', OLD_BLOCK, NEW_BLOCK, 'view test block', 'MARKER-PATCH-144 — Test send block'),
    ('app/Http/Controllers/Tenant/TestEmailController.php', OLD_CTRL, NEW_CTRL, 'controller JSON response', 'MARKER-PATCH-144 — JSON response for XHR'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    for rel, old, new, label, marker in EDITS:
        p = root / rel
        t = p.read_text()
        if marker in t:
            print(f'already_applied: {label}'); continue
        if old not in t:
            print(f'ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
        if t.count(old) > 1:
            print(f'ERROR: anchor not unique for {label}', file=sys.stderr); sys.exit(2)
        if a.apply:
            p.write_text(t.replace(old, new, 1))
        print(f'{"applied" if a.apply else "would_apply"}: {label}')


if __name__ == '__main__':
    main()
