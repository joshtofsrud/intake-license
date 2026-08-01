#!/bin/bash
# appointment-save-notify-modal — ask before telling the customer.
#
#   Second half of the notify work. The appointment now saves without
#   contacting anyone; this is how a shop chooses to contact them.
#
#   The button becomes "Save appointment" — it was "Create Appointment",
#   which said what the software did rather than what the person was doing,
#   and gave no hint that a message might follow.
#
#   On save, a modal: Text · Email · Both · Don't notify. Nothing goes out
#   until one is picked, and "Don't notify" is a plain equal choice, not a
#   dismissal — that is the common case when a shop books someone in at the
#   counter and the customer is standing right there.
#
#   Only channels that can actually work are offered. Store now reports, per
#   channel, whether the CUSTOMER has that contact detail AND the tenant has
#   that notification enabled. Offering "Text" for a customer with no phone,
#   or for a shop with SMS switched off, would be a button that silently does
#   nothing. If neither channel is available the modal is skipped entirely
#   rather than shown with everything disabled.
#
#   Sending posts to appointments.notify, which dispatches the same job the
#   public booking path uses.
# NO MIGRATION. Server: optimize:clear && view:clear
set -e
if grep -q "MARKER-NOTIFY-MODAL" resources/views/tenant/appointments/_create_modal.blade.php; then
  echo "appointment-save-notify-modal already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ controller
python3 - <<'ANM_0_EOF'
import io
p = 'app/Http/Controllers/Tenant/AppointmentController.php'
s = io.open(p, encoding='utf-8').read()

old = """        if ($request->expectsJson()) {
            return response()->json([
                'ok'       => true,
                'id'       => $appointment->id,
                'ra'       => $appointment->ra_number,"""
assert s.count(old) == 1, ('store json', s.count(old))
new = """        if ($request->expectsJson()) {
            // MARKER-NOTIFY-MODAL — which channels could actually reach this
            // customer. A channel needs BOTH the customer's contact detail and
            // the tenant's notification switch; offering one without both is a
            // button that silently does nothing.
            $cust = $appointment->customer;
            $canEmail = filled($cust?->email)
                && $tenant->notificationEnabled('booking_confirmation_email');
            $canSms = filled($cust?->phone)
                && $tenant->notificationEnabled('booking_confirmation_sms');

            return response()->json([
                'ok'       => true,
                'id'       => $appointment->id,
                'ra'       => $appointment->ra_number,
                'notify'   => [
                    'email' => $canEmail,
                    'sms'   => $canSms,
                    'to'    => trim((string) ($cust?->first_name . ' ' . $cust?->last_name)) ?: 'the customer',
                ],
                'notify_url' => route('tenant.appointments.notify', ['id' => $appointment->id]),"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('controller ok')
ANM_0_EOF

# ------------------------------------------------------------------ view
python3 - <<'ANM_1_EOF'
import io
p = 'resources/views/tenant/appointments/_create_modal.blade.php'
s = io.open(p, encoding='utf-8').read()

# --- button label -------------------------------------------------------
old = """        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Create Appointment</button>"""
assert s.count(old) == 1, ('button', s.count(old))
new = """        {{-- MARKER-NOTIFY-MODAL — "Save" says what the person is doing; the
             old label described what the software did and gave no hint that a
             message might follow. --}}
        <button type="button" class="appt-btn appt-btn--create" id="appt-submit" onclick="ApptModal.submit()">Save appointment</button>"""
s = s.replace(old, new)

old = """        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
    }"""
assert s.count(old) == 1, ('btn reset 1', s.count(old))
s = s.replace(old, """        btn.disabled = false; btn.innerHTML = 'Save appointment';
        return;
      }
    }""")

old = """        btn.disabled = false; btn.innerHTML = 'Create Appointment';
        return;
      }
      var msg ="""
assert s.count(old) == 1, ('btn reset 2', s.count(old))
s = s.replace(old, """        btn.disabled = false; btn.innerHTML = 'Save appointment';
        return;
      }
      var msg =""")

# --- success path: offer the choice ------------------------------------
old = """      if (res.ok && res.body.ok) {
        if (res.body.redirect) window.location.href = res.body.redirect;
        else window.location.reload();
        return;
      }"""
assert s.count(old) == 1, ('success', s.count(old))
new = """      if (res.ok && res.body.ok) {
        // MARKER-NOTIFY-MODAL — saved. Nothing has been sent; ask first.
        askNotify(res.body);
        return;
      }"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('view wiring ok')
ANM_1_EOF

# --- the modal itself ---------------------------------------------------
python3 - <<'ANM_2_EOF'
import io
p = 'resources/views/tenant/appointments/_create_modal.blade.php'
s = io.open(p, encoding='utf-8').read()

anchor = """  function submit() {"""
assert s.count(anchor) == 1, ('submit fn', s.count(anchor))

fn = """  // MARKER-NOTIFY-MODAL — the appointment is saved and nobody has been told.
  //
  // "Don't notify" is a plain equal choice, not a dismissal: booking someone
  // in at the counter while they're standing there is the common case, and a
  // confirmation then is noise.
  function askNotify(body) {
    var n = (body && body.notify) || {};
    var go = function () {
      if (body.redirect) window.location.href = body.redirect;
      else window.location.reload();
    };

    // Nothing could be sent anyway — don't ask a question with one answer.
    if (!n.email && !n.sms) { go(); return; }

    var bg = document.createElement('div');
    bg.setAttribute('style',
      'position:fixed;inset:0;z-index:500;display:grid;place-items:center;' +
      'background:rgba(0,0,0,.55)');

    var opts = '';
    if (n.sms)   { opts += '<button type="button" class="appt-btn appt-btn--cancel" data-ch="sms" style="width:100%;padding:11px;margin-bottom:8px">Text ' + esc(n.to) + '</button>'; }
    if (n.email) { opts += '<button type="button" class="appt-btn appt-btn--cancel" data-ch="email" style="width:100%;padding:11px;margin-bottom:8px">Email ' + esc(n.to) + '</button>'; }
    if (n.sms && n.email) { opts += '<button type="button" class="appt-btn appt-btn--cancel" data-ch="both" style="width:100%;padding:11px;margin-bottom:8px">Text and email</button>'; }

    bg.innerHTML =
      '<div style="background:var(--ia-surface,#151517);border:0.5px solid var(--ia-border,rgba(255,255,255,.1));' +
      'border-radius:16px;padding:26px 26px 22px;width:min(380px,calc(100vw - 32px))">' +
        '<div style="font-size:16px;font-weight:600;margin-bottom:4px">Appointment saved</div>' +
        '<div style="font-size:12.5px;opacity:.6;margin-bottom:18px">Nothing has been sent yet.</div>' +
        opts +
        '<button type="button" class="appt-btn appt-btn--create" data-ch="none" style="width:100%;padding:11px">Don\\'t notify</button>' +
      '</div>';

    document.body.appendChild(bg);

    bg.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ch]');
      if (!b) return;
      var ch = b.getAttribute('data-ch');

      if (ch === 'none') { go(); return; }

      var channels = ch === 'both' ? ['sms', 'email'] : [ch];
      b.disabled = true;
      b.textContent = 'Sending…';

      var meta = document.querySelector('meta[name="csrf-token"]');
      fetch(body.notify_url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : ''
        },
        credentials: 'same-origin',
        body: JSON.stringify({ channels: channels }),
      })
      // Queued or not, the appointment is saved — never strand someone on
      // this modal because a message failed to dispatch.
      .then(go)
      .catch(go);
    });
  }

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
  }

"""

s = s.replace(anchor, fn + anchor)
io.open(p, 'w', encoding='utf-8').write(s)
print('modal fn ok')
ANM_2_EOF

echo
echo "appointment-save-notify-modal applied."
