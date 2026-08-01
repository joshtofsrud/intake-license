#!/usr/bin/env bash
# apply-send-confirmation-action.sh
# MARKER-SEND-CONFIRMATION — explicit "Send confirmation" under Cancel /
# Reschedule, plus a note saying when the customer was last told.
#
# No new columns. Every confirmation send already writes a row to
# tenant_notification_log (event_type=booking_confirmation, related_type=
# appointment, related_id=<appt>), and that table is indexed on
# (tenant_id, related_type, related_id) — so "when was this customer
# notified" is a cheap read of data that already exists, and the timestamp
# is truthful because the job writes it AFTER attempting the send.
#
# Inline expansion, not an overlay: the whole point of tonight's change is
# that nothing stacks a second modal on the operator.
#
# Channels offered require BOTH the customer's contact detail and the
# tenant's own notification switch — same test store() already applies.
set -e

# ------------------------------------------------------------------ partial
cat <<'EOF' > resources/views/tenant/appointments/_send-confirmation.blade.php
{{-- MARKER-SEND-CONFIRMATION — shared by show + show-multi-asset.
     Expects: $appointment, $confirmCanEmail, $confirmCanSms,
              $confirmSentAt, $confirmChannels, $confirmFailed --}}
@php
  // Blade comments break inside @php, so these are // comments by rule.
  // Label describes the LAST send, not what is possible now.
  $sc_chLabel = null;
  if (!empty($confirmChannels)) {
      $sc_hasSms   = in_array('sms', $confirmChannels, true);
      $sc_hasEmail = in_array('email', $confirmChannels, true);
      $sc_chLabel  = $sc_hasSms && $sc_hasEmail ? 'text and email' : ($sc_hasSms ? 'text' : 'email');
  }
  $sc_any = $confirmCanEmail || $confirmCanSms;
@endphp

<div class="sc-wrap" style="width:100%">
  @if($sc_any)
    <button type="button" class="ia-btn ia-btn--secondary" id="sc-open" style="width:100%">
      &#9993; {{ $confirmSentAt ? 'Resend confirmation' : 'Send confirmation' }}
    </button>

    <div id="sc-choices" style="display:none;margin-top:8px">
      @if($confirmCanSms)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="sms" style="width:100%;margin-bottom:6px">Text</button>
      @endif
      @if($confirmCanEmail)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="email" style="width:100%;margin-bottom:6px">Email</button>
      @endif
      @if($confirmCanSms && $confirmCanEmail)
        <button type="button" class="ia-btn ia-btn--secondary sc-go" data-ch="both" style="width:100%;margin-bottom:6px">Text and email</button>
      @endif
      <button type="button" class="ia-btn" id="sc-cancel" style="width:100%">Never mind</button>
    </div>
  @endif

  <div id="sc-note" style="font-size:11.5px;line-height:1.55;opacity:.55;margin-top:8px;text-align:center">
    @if($confirmSentAt)
      Customer notified {{ tlocal_datetime($confirmSentAt, 'M j, g:i A') }}@if($sc_chLabel) · {{ $sc_chLabel }}@endif
    @elseif($confirmFailed)
      Last confirmation failed to send.
    @elseif($sc_any)
      Customer has not been notified.
    @else
      No way to reach this customer — add an email or phone, or turn on booking confirmations in settings.
    @endif
  </div>
</div>

@if($sc_any)
<script>
(function () {
  var NOTIFY = @json(route('tenant.appointments.notify', ['id' => $appointment->id]));
  var open   = document.getElementById('sc-open');
  var box    = document.getElementById('sc-choices');
  var note   = document.getElementById('sc-note');
  if (!open) { return; }

  open.addEventListener('click', function () {
    var showing = box.style.display !== 'none';
    box.style.display = showing ? 'none' : '';
    open.style.display = showing ? '' : 'none';
  });

  document.getElementById('sc-cancel').addEventListener('click', function () {
    box.style.display = 'none';
    open.style.display = '';
  });

  document.querySelectorAll('.sc-go').forEach(function (b) {
    b.addEventListener('click', function () {
      var ch = b.getAttribute('data-ch');
      var channels = ch === 'both' ? ['sms', 'email'] : [ch];
      document.querySelectorAll('.sc-go').forEach(function (x) { x.disabled = true; });
      b.textContent = 'Sending…';

      var meta = document.querySelector('meta[name="csrf-token"]');
      fetch(NOTIFY, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ channels: channels })
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        box.style.display = 'none';
        open.style.display = '';
        open.innerHTML = '\u2709 Resend confirmation';
        // Queued, not delivered — say so rather than claiming a send that
        // the worker has not attempted yet. The note firms up on reload,
        // where it reads the actual log row.
        note.textContent = (j && j.ok)
          ? 'Queued just now — reload to see the delivery record.'
          : 'Could not queue that. Try again.';
      })
      .catch(function () {
        document.querySelectorAll('.sc-go').forEach(function (x) { x.disabled = false; });
        note.textContent = 'Network error — nothing was sent.';
      });
    });
  });
})();
</script>
@endif
EOF
echo "created resources/views/tenant/appointments/_send-confirmation.blade.php"

python3 <<'PY'
import io

# ============================================================ controller
p = 'app/Http/Controllers/Tenant/AppointmentController.php'
s = io.open(p, encoding='utf-8').read()

old = """            return view('tenant.appointments.show-multi-asset', compact("""
assert s.count(old) == 1, 'C1 multi-asset view anchor'
s = s.replace(old, """            // MARKER-SEND-CONFIRMATION
            [$confirmCanEmail, $confirmCanSms, $confirmSentAt, $confirmChannels, $confirmFailed]
                = $this->confirmationState($tenant, $appointment);

            return view('tenant.appointments.show-multi-asset', compact(""")

old = """                'specialOrdersForAppt', 'soVendors'));
        }"""
assert s.count(old) == 1, 'C2 multi-asset compact tail anchor'
s = s.replace(old, """                'specialOrdersForAppt', 'soVendors',
                'confirmCanEmail', 'confirmCanSms', 'confirmSentAt', 'confirmChannels', 'confirmFailed'));
        }""")

old = """        return view('tenant.appointments.show', compact(
            'appointment', 'transitions', 'destructive',
            'availableServices', 'availableAddons', 'availableResources', 'specialOrdersForAppt', 'soVendors'));
    }"""
assert s.count(old) == 1, 'C3 show view anchor'
s = s.replace(old, """        // MARKER-SEND-CONFIRMATION
        [$confirmCanEmail, $confirmCanSms, $confirmSentAt, $confirmChannels, $confirmFailed]
            = $this->confirmationState($tenant, $appointment);

        return view('tenant.appointments.show', compact(
            'appointment', 'transitions', 'destructive',
            'availableServices', 'availableAddons', 'availableResources', 'specialOrdersForAppt', 'soVendors',
            'confirmCanEmail', 'confirmCanSms', 'confirmSentAt', 'confirmChannels', 'confirmFailed'));
    }

    /**
     * MARKER-SEND-CONFIRMATION — what the operator needs to decide whether
     * to tell this customer anything.
     *
     * Reads tenant_notification_log rather than a new column: the job
     * already records every attempt there, after the send, so the record
     * covers failures too and needs no backfill. Indexed on
     * (tenant_id, related_type, related_id).
     *
     * @return array{0:bool,1:bool,2:?\\Illuminate\\Support\\Carbon,3:array,4:bool}
     */
    private function confirmationState($tenant, $appointment): array
    {
        $customer = $appointment->customer;

        $canEmail = filled($customer?->email)
            && $tenant->notificationEnabled('booking_confirmation_email');
        $canSms = filled($customer?->phone)
            && $tenant->notificationEnabled('booking_confirmation_sms');

        $rows = \\App\\Models\\Tenant\\TenantNotificationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('related_type', 'appointment')
            ->where('related_id', $appointment->id)
            ->where('event_type', 'booking_confirmation')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        $sent    = $rows->where('status', 'sent');
        $latest  = $sent->first();
        $sentAt  = $latest?->created_at;

        // One "send" can be two rows (text + email) written seconds apart —
        // group anything within a minute of the latest as the same act.
        $channels = $sentAt
            ? $sent->filter(fn ($r) => $r->created_at->diffInSeconds($sentAt) <= 60)
                   ->pluck('channel')->unique()->values()->all()
            : [];

        // Only call it failed when nothing has ever succeeded — a failed
        // retry after a good send shouldn't erase the good send.
        $failed = $latest === null && $rows->isNotEmpty();

        return [$canEmail, $canSms, $sentAt, $channels, $failed];
    }""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ show.blade
p = 'resources/views/tenant/appointments/show.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """      <button type="button" class="ia-btn ia-btn--danger appt-b-cancel-btn">Cancel appointment</button>
    </div>
    @endunless"""
assert s.count(old) == 1, 'V1 show actions anchor'
s = s.replace(old, """      <button type="button" class="ia-btn ia-btn--danger appt-b-cancel-btn">Cancel appointment</button>
      {{-- MARKER-SEND-CONFIRMATION --}}
      <div class="appt-b-actions-divider"></div>
      @include('tenant.appointments._send-confirmation')
    </div>
    @endunless""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ show-multi-asset
p = 'resources/views/tenant/appointments/show-multi-asset.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """          <button type="button" class="ia-btn ia-btn--danger ia-btn--sm ma-cancel-btn" style="flex: 1;">Cancel</button>
        </div>
      @endunless"""
assert s.count(old) == 1, 'V2 multi-asset actions anchor'
s = s.replace(old, """          <button type="button" class="ia-btn ia-btn--danger ia-btn--sm ma-cancel-btn" style="flex: 1;">Cancel</button>
        </div>
        {{-- MARKER-SEND-CONFIRMATION --}}
        <div style="margin-top:8px">
          @include('tenant.appointments._send-confirmation')
        </div>
      @endunless""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- glued-directive sweep ---"
python3 - <<'PY'
import io, re
for f in ['resources/views/tenant/appointments/_send-confirmation.blade.php',
          'resources/views/tenant/appointments/show.blade.php',
          'resources/views/tenant/appointments/show-multi-asset.blade.php']:
    s = re.sub(r'\{\{--.*?--\}\}', '', io.open(f, encoding='utf-8').read(), flags=re.S)
    hits = re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp)\b', s)
    print(f, 'glued:', len(hits), hits[:3])
PY

echo "--- controller braces/parens ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/AppointmentController.php', encoding='utf-8').read()
i, n, d, par = 0, len(s), 0, 0
while i < n:
    c = s[i]
    if c == '#' or (c == '/' and i+1 < n and s[i+1] == '/'):
        while i < n and s[i] != '\n': i += 1
    elif c == '/' and i+1 < n and s[i+1] == '*':
        i += 2
        while i+1 < n and not (s[i] == '*' and s[i+1] == '/'): i += 1
        i += 2
    elif c in '"\'':
        q = c; i += 1
        while i < n and s[i] != q:
            if s[i] == '\\': i += 1
            i += 1
        i += 1
    else:
        if c == '{': d += 1
        elif c == '}': d -= 1
        elif c == '(': par += 1
        elif c == ')': par -= 1
        i += 1
print('braces', d, 'parens', par)
PY

echo "--- partial JS parses ---"
python3 - <<'PY'
import io, re, subprocess, os
s = io.open('resources/views/tenant/appointments/_send-confirmation.blade.php', encoding='utf-8').read()
b = re.findall(r'<script[^>]*>(.*?)</script>', s, flags=re.S)[0]
b = re.sub(r'\{\{--.*?--\}\}', '', b, flags=re.S)
out, i = [], 0
while i < len(b):
    if b.startswith('@json(', i):
        d, j = 0, i + 5
        while j < len(b):
            if b[j] == '(': d += 1
            elif b[j] == ')':
                d -= 1
                if d == 0: break
            j += 1
        out.append('"STUB"'); i = j + 1; continue
    out.append(b[i]); i += 1
b = ''.join(out)
b = re.sub(r'^\s*@(if|endif|else|elseif)\b.*$', '', b, flags=re.M)
os.makedirs('/tmp/sc', exist_ok=True)
io.open('/tmp/sc/p.js', 'w', encoding='utf-8').write(b)
r = subprocess.run(['node', '--check', '/tmp/sc/p.js'], capture_output=True, text=True)
print('OK' if r.returncode == 0 else 'FAIL\n' + r.stderr[:500])
PY

echo
echo "apply-send-confirmation-action: OK"
