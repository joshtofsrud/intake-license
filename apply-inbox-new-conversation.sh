#!/usr/bin/env bash
set -euo pipefail
# apply-inbox-new-conversation.sh — MARKER-INBOX-NEW
# Start a conversation with any customer from the unified inbox.
#   view: "+ New conversation" button, inline compose panel (customer picker,
#         SMS/Email choice, message), reopens with typed text on a failed send
#   controller: start() accepts optional channel (sms|email), passes it through
#   service: postOutbound email subject drops "Re:" when the customer never wrote

VIEW=resources/views/tenant/inbox/index.blade.php
CTRL=app/Http/Controllers/Tenant/InboxController.php
SVC=app/Services/Tenant/InboxService.php

for f in "$VIEW" "$CTRL" "$SVC"; do
  [ -f "$f" ] || { echo "MISSING $f — run from the repo root"; exit 1; }
done

if grep -q "MARKER-INBOX-NEW" "$VIEW"; then
  echo "Already applied (MARKER-INBOX-NEW present) — no-op."
  exit 0
fi

# ---------------------------------------------------------------- snippets
cat <<'EOF' > /tmp/ibn_css.txt
  /* MARKER-INBOX-NEW — start a conversation from the inbox */
  .ib-new { margin-bottom:16px; padding:14px 16px; border-radius:12px; background:var(--ia-surface);
            box-shadow:inset 0 0 0 .5px var(--ia-border); }
  .ib-new[hidden] { display:none; }
  .ib-new-title { font-size:13px; font-weight:700; margin-bottom:10px; }
  .ib-new-cust { position:relative; }
  .ib-new-results { position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:60;
                    background:var(--ia-surface); border-radius:10px;
                    box-shadow:0 8px 24px rgba(0,0,0,.14), inset 0 0 0 .5px var(--ia-border);
                    max-height:240px; overflow-y:auto; }
  .ib-new-results[hidden] { display:none; }
  .ib-new-hit { display:block; width:100%; text-align:left; background:none; border:0; cursor:pointer;
                padding:9px 12px; font-family:inherit; color:inherit; font-size:13px;
                border-bottom:.5px solid var(--ia-border); }
  .ib-new-hit:last-child { border-bottom:0; }
  .ib-new-hit:hover { background:rgba(127,127,127,.08); }
  .ib-new-hit small { display:block; font-size:11px; opacity:.55; margin-top:1px; }
  .ib-new-chip { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:600;
                 padding:7px 11px; border-radius:9px; background:rgba(127,127,127,.10); }
  .ib-new-chip[hidden] { display:none; }
  .ib-new-chip button { background:none; border:0; cursor:pointer; color:inherit;
                        font-size:15px; line-height:1; padding:0; opacity:.55; }
  .ib-new-chip button:hover { opacity:1; }
  .ib-new-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin:10px 0 8px; }
EOF

cat <<'EOF' > /tmp/ibn_panel.txt

{{-- MARKER-INBOX-NEW — start a conversation with any customer. Posts to the
     pre-existing inbox.start route; the picker reuses tenant.customers.search.
     from_new marks a failed submit as OURS so the panel reopens with the typed
     message intact, and so a failed REPLY's old('body') never leaks in here. --}}
@php /* old() only counts when the failed post came from this form. */
  $ibnOld = (bool) old('from_new');
  $ibnChannel = $ibnOld ? old('channel', 'sms') : 'sms';
@endphp
<div class="ib-new" id="ib-new" data-reopen="{{ $ibnOld ? '1' : '' }}" @if(!$ibnOld) hidden @endif>
  <div class="ib-new-title">New conversation</div>
  <form method="POST" action="{{ route('tenant.inbox.start') }}" id="ib-new-form">
    @csrf
    <input type="hidden" name="from_new" value="1">
    <input type="hidden" name="customer_id" id="ib-new-cid" value="{{ $ibnOld ? old('customer_id') : '' }}">
    <input type="hidden" name="customer_label" id="ib-new-clabel" value="{{ $ibnOld ? old('customer_label') : '' }}">
    <div class="ib-new-cust" id="ib-new-cust">
      <input type="text" id="ib-new-q" class="ia-input" style="width:100%"
             placeholder="Search customers by name, phone, or email&hellip;" autocomplete="off">
      <div class="ib-new-chip" id="ib-new-chip" hidden></div>
      <div class="ib-new-results" id="ib-new-results" hidden></div>
    </div>
    <div class="ib-new-meta">
      <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
        Send via
        <select name="channel" id="ib-new-channel" class="ia-input" style="font-size:12px;padding:3px 6px;width:auto">
          <option value="sms" {{ $ibnChannel === 'sms' ? 'selected' : '' }}>Text (SMS)</option>
          <option value="email" {{ $ibnChannel === 'email' ? 'selected' : '' }}>Email</option>
        </select>
      </label>
    </div>
    <div class="ib-compose-row">
      <textarea name="body" rows="2" maxlength="1200" required placeholder="Type your message&hellip;"
                class="ia-input ib-compose-field" style="resize:vertical">{{ $ibnOld ? old('body') : '' }}</textarea>
      <button type="submit" class="ia-btn ia-btn--primary ib-compose-send"><span class="ib-send-txt">Send</span><span class="ib-send-ar" aria-hidden="true">&uarr;</span></button>
    </div>
  </form>
</div>
EOF

cat <<'EOF' > /tmp/ibn_js.txt

<script>
  // MARKER-INBOX-NEW — panel toggle + customer picker for starting a conversation.
  (function () {
    var panel = document.getElementById('ib-new');
    var openBtn = document.getElementById('ib-new-btn');
    if (!panel || !openBtn) { return; }

    var qInput  = document.getElementById('ib-new-q');
    var results = document.getElementById('ib-new-results');
    var chip    = document.getElementById('ib-new-chip');
    var cid     = document.getElementById('ib-new-cid');
    var clabel  = document.getElementById('ib-new-clabel');
    var chanSel = document.getElementById('ib-new-channel');
    var form    = document.getElementById('ib-new-form');
    var bodyTa  = form.querySelector('textarea[name="body"]');
    var searchUrl = @json(route('tenant.customers.search'));
    var timer = null;

    openBtn.addEventListener('click', function () {
      panel.hidden = !panel.hidden;
      if (!panel.hidden) { (cid.value ? bodyTa : qInput).focus(); }
    });

    function setChannelOptions(hasPhone, hasEmail) {
      var optSms   = chanSel.querySelector('option[value="sms"]');
      var optEmail = chanSel.querySelector('option[value="email"]');
      optSms.disabled   = !hasPhone;
      optEmail.disabled = !hasEmail;
      if (chanSel.selectedOptions.length && chanSel.selectedOptions[0].disabled) {
        if (hasPhone) { chanSel.value = 'sms'; }
        else if (hasEmail) { chanSel.value = 'email'; }
      }
    }

    function showChip(label) {
      while (chip.firstChild) { chip.removeChild(chip.firstChild); }
      chip.appendChild(document.createTextNode(label));
      var x = document.createElement('button');
      x.type = 'button';
      x.setAttribute('aria-label', 'Change customer');
      x.appendChild(document.createTextNode('\u00d7'));
      x.addEventListener('click', clearCustomer);
      chip.appendChild(x);
      chip.hidden = false;
      qInput.hidden = true;
      results.hidden = true;
    }

    function clearCustomer() {
      cid.value = '';
      clabel.value = '';
      chip.hidden = true;
      qInput.hidden = false;
      qInput.value = '';
      setChannelOptions(true, true);
      qInput.focus();
    }

    function pick(c) {
      cid.value = c.id;
      clabel.value = c.label || c.name || '';
      setChannelOptions(!!c.phone, !!c.email);
      showChip(clabel.value);
      qInput.setCustomValidity('');
      bodyTa.focus();
    }

    function render(list) {
      while (results.firstChild) { results.removeChild(results.firstChild); }
      if (!list.length) { results.hidden = true; return; }
      list.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ib-new-hit';
        b.appendChild(document.createTextNode(c.label || c.name || ''));
        var s = document.createElement('small');
        var bits = [];
        if (c.phone) { bits.push(c.phone); }
        if (c.email) { bits.push(c.email); }
        s.appendChild(document.createTextNode(bits.length ? bits.join(' \u00b7 ') : 'no phone or email on file'));
        b.appendChild(s);
        b.addEventListener('click', function () { pick(c); });
        results.appendChild(b);
      });
      results.hidden = false;
    }

    qInput.addEventListener('input', function () {
      clearTimeout(timer);
      qInput.setCustomValidity('');
      var term = qInput.value.trim();
      if (!term) { results.hidden = true; return; }
      timer = setTimeout(function () {
        fetch(searchUrl + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : { customers: [] }; })
          .then(function (data) { render(data.customers || []); })
          .catch(function () { results.hidden = true; });
      }, 250);
    });

    document.addEventListener('click', function (e) {
      if (!document.getElementById('ib-new-cust').contains(e.target)) { results.hidden = true; }
    });

    form.addEventListener('submit', function (e) {
      if (!cid.value) {
        e.preventDefault();
        qInput.setCustomValidity('Pick a customer first');
        qInput.reportValidity();
      }
    });

    // A failed submit reopens the panel with everything the user typed.
    if (panel.dataset.reopen === '1' && cid.value && clabel.value) {
      showChip(clabel.value);
    }
  })();
</script>
EOF

# ---------------------------------------------------------------- view edits
python3 - "$VIEW" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

def splice(anchor, insert, where, label):
    global src
    n = src.count(anchor)
    if n != 1:
        print(f"FAIL {label}: anchor found {n} times"); sys.exit(1)
    rep = insert + anchor if where == 'before' else anchor + insert
    src = src.replace(anchor, rep, 1)
    print(f"ok   {label}")

css   = open('/tmp/ibn_css.txt').read()
panel = open('/tmp/ibn_panel.txt').read()
js    = open('/tmp/ibn_js.txt').read()

# 1) CSS before the closing style tag
splice("</style>\n@endpush", css, 'before', 'css block')

# 2) "+ New conversation" button in the page head
head_anchor = """<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
</div>"""
head_new = """<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
  {{-- MARKER-INBOX-NEW --}}
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="ib-new-btn">+ New conversation</button>
  </div>
</div>"""
n = src.count(head_anchor)
if n != 1:
    print(f"FAIL head button: anchor found {n} times"); sys.exit(1)
src = src.replace(head_anchor, head_new, 1)
print("ok   head button")

# 3) compose panel after the error flash block
flash_anchor = """@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif"""
splice(flash_anchor, panel, 'after', 'compose panel')

# 4) empty-state copy mentions the button
empty_anchor = "No conversations here yet. Inbound texts to your business number land in this list automatically."
empty_new = "No conversations here yet. Inbound texts to your business number land here automatically &mdash; or start one with &ldquo;+ New conversation&rdquo;."
n = src.count(empty_anchor)
if n != 1:
    print(f"FAIL empty copy: anchor found {n} times"); sys.exit(1)
src = src.replace(empty_anchor, empty_new, 1)
print("ok   empty-state copy")

# 5) picker JS after the existing script block
splice("</script>\n\n@endsection", None, 'noop', 'noop') if False else None
anchor = "</script>\n\n@endsection"
n = src.count(anchor)
if n != 1:
    print(f"FAIL js block: anchor found {n} times"); sys.exit(1)
src = src.replace(anchor, "</script>\n" + js + "\n@endsection", 1)
print("ok   js block")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- controller
python3 - "$CTRL" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old_v = """        $request->validate([
            'customer_id' => ['required', 'string', 'uuid'],
            'body'        => ['required', 'string', 'max:1200'],
        ]);"""
new_v = """        $request->validate([
            'customer_id' => ['required', 'string', 'uuid'],
            'body'        => ['required', 'string', 'max:1200'],
            'channel'     => ['nullable', 'in:sms,email'], // MARKER-INBOX-NEW
        ]);"""
if src.count(old_v) != 1:
    print(f"FAIL start() validation: anchor found {src.count(old_v)} times"); sys.exit(1)
src = src.replace(old_v, new_v, 1)
print("ok   start() validation")

old_s = """        $thread = $this->inbox->threadFor($tenant, $customer, 'sms');

        try {
            $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id());"""
new_s = """        // MARKER-INBOX-NEW — channel comes from the compose panel; it seeds the
        // thread when new and is passed explicitly so postOutbound can't infer.
        $channel = $request->input('channel', 'sms');

        $thread = $this->inbox->threadFor($tenant, $customer, $channel);

        try {
            $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id(), $channel);"""
if src.count(old_s) != 1:
    print(f"FAIL start() channel: anchor found {src.count(old_s)} times"); sys.exit(1)
src = src.replace(old_s, new_s, 1)
print("ok   start() channel passthrough")

open(path, 'w').write(src)
PY

# ---------------------------------------------------------------- service
python3 - "$SVC" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

old_c = """        $channel = $channel
            ?? optional(
                TenantMessage::where('thread_id', $thread->id)
                    ->where('direction', 'in')
                    ->orderByDesc('created_at')
                    ->first()
            )->channel
            ?? $thread->channel;"""
new_c = """        // MARKER-INBOX-NEW — the last inbound also decides the email subject
        // below: "Re:" is only honest when the customer actually wrote first.
        $lastIn = TenantMessage::where('thread_id', $thread->id)
            ->where('direction', 'in')
            ->orderByDesc('created_at')
            ->first();

        $channel = $channel
            ?? optional($lastIn)->channel
            ?? $thread->channel;"""
if src.count(old_c) != 1:
    print(f"FAIL postOutbound lastIn: anchor found {src.count(old_c)} times"); sys.exit(1)
src = src.replace(old_c, new_c, 1)
print("ok   postOutbound lastIn")

old_j = """            $subject = 'Re: your message to ' . $tenant->emailFromName();"""
new_j = """            $subject = $lastIn
                ? 'Re: your message to ' . $tenant->emailFromName()
                : 'Message from ' . $tenant->emailFromName(); // MARKER-INBOX-NEW"""
if src.count(old_j) != 1:
    print(f"FAIL postOutbound subject: anchor found {src.count(old_j)} times"); sys.exit(1)
src = src.replace(old_j, new_j, 1)
print("ok   postOutbound subject")

open(path, 'w').write(src)
PY

php -l "$CTRL"
php -l "$SVC"

echo ""
echo "SUCCESS — apply-inbox-new-conversation applied."
echo "Deploy note: needs php artisan optimize:clear (view cache)."
