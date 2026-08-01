#!/usr/bin/env bash
# apply-inbox-thread-polish.sh
# MARKER-INBOX-POLISH — two layout bugs and a styling pass on the thread view.
#
# BUG 1 — the giant bubbles.
#   .ib-msg carries white-space:pre-wrap, which applies to the WHOLE bubble,
#   not just the message text. The Blade template puts the delete form, the
#   body and the timestamp on their own indented lines, so every one of
#   those newlines and every leading space renders literally inside the
#   bubble. "I need a new fork" ends up in a 250px-tall box with the text
#   shoved to the bottom. pre-wrap moves onto a span around $m->body, which
#   is the only place that actually needs line breaks preserved.
#
# BUG 2 — the composer walks off the page.
#   .ib-msgs is flex:1 + overflow-y:auto, which can only scroll when an
#   ancestor has a bounded height. .ib-wrap has min-height:560px and no
#   maximum, so the grid grows with the thread and the reply box ends up
#   below the fold. Bounding .ib-msgs directly (rather than guessing the
#   height of the page chrome above it) keeps this correct regardless of
#   what the header does later.
#
# Also:
#   - the delete × was float:right at 30% opacity on every message, which
#     is both noisy and part of what mangled the layout. Now absolute and
#     hover-only.
#   - .ib-msg.in on mobile referenced var(--ia-surface-2), which does not
#     exist in Intake's themes — inbound bubbles rendered transparent on
#     phones. Same trap as the special-orders sticky header.
#   - the channel marker (WEB / EMAIL) becomes a real tag now that email
#     actually threads, instead of caps text jammed into the timestamp.
#   - outbound bubbles were a full inversion (var(--ia-text) background),
#     a stark slab on the dark theme. Softened to an accent tint. This one
#     is taste rather than a bug — say the word and I'll revert just it.
#
# View-only: view:clear is enough.
set -e

python3 <<'PY'
import io

p = 'resources/views/tenant/inbox/index.blade.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- base CSS
old = """  .ib-msg { max-width:72%; padding:9px 12px; border-radius:12px; font-size:13px; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
  .ib-msg.in  { align-self:flex-start; background:rgba(127,127,127,.10); border-bottom-left-radius:4px; }
  .ib-msg.out { align-self:flex-end; background:var(--ia-text); color:var(--ia-bg, #fff); border-bottom-right-radius:4px; }"""
assert s.count(old) == 1, 'I1 base bubble CSS anchor'
s = s.replace(old, """  /* MARKER-INBOX-POLISH — pre-wrap belongs on the body only. On the bubble it
     rendered the template's own indentation as blank lines. */
  .ib-msg { position:relative; max-width:72%; padding:9px 24px 9px 12px; border-radius:12px;
            font-size:13px; line-height:1.45; word-break:break-word; }
  .ib-msg-body { white-space:pre-wrap; }
  .ib-msg.in  { align-self:flex-start; background:rgba(127,127,127,.10); border-bottom-left-radius:4px; }
  .ib-msg.out { align-self:flex-end; background:rgba(190,242,100,.13); color:var(--ia-text);
                box-shadow:inset 0 0 0 .5px rgba(190,242,100,.26); border-bottom-right-radius:4px; }
  /* Delete stays out of the way until you go looking for it. */
  .ib-msg-del { position:absolute; top:5px; right:6px; opacity:0; transition:opacity .12s; }
  .ib-msg:hover .ib-msg-del { opacity:.4; }
  .ib-msg-del:hover { opacity:1; }
  .ib-msg-del button { background:none; border:0; color:inherit; cursor:pointer;
                       font-size:14px; line-height:1; padding:0; }
  /* Mid-grey reads on both the light and dark bubble backgrounds. */
  .ib-chan { display:inline-block; font-size:9.5px; font-weight:700; letter-spacing:.08em;
             padding:1px 5px; border-radius:4px; margin-right:6px;
             background:rgba(127,127,127,.22); vertical-align:1px; }""")

old = """  .ib-msg-time { font-size:10px; opacity:.45; margin-top:4px; }"""
assert s.count(old) == 1, 'I2 time CSS anchor'
s = s.replace(old, """  .ib-msg-time { font-size:10px; opacity:.45; margin-top:5px; }
  /* MARKER-INBOX-POLISH — bound the scroller itself. Guessing the height of
     the chrome above it would break the next time the header changes. */
  @media (min-width: 981px) {
    .ib-msgs { max-height:58vh; }
  }""")

# ---------------------------------------------------------------- mobile CSS
old = """    .ib-msg { max-width:80%; padding:10px 13px; border-radius:15px; font-size:14px; }
    .ib-msg.in  { background:var(--ia-surface-2); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; border-bottom-right-radius:5px; }"""
assert s.count(old) == 1, 'I3 mobile bubble anchor'
s = s.replace(old, """    .ib-msg { max-width:80%; padding:10px 24px 10px 13px; border-radius:15px; font-size:14px; }
    /* MARKER-INBOX-POLISH — --ia-surface-2 does not exist in Intake's themes,
       so this resolved to nothing and inbound bubbles were transparent. */
    .ib-msg.in  { background:rgba(127,127,127,.14); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; box-shadow:none; border-bottom-right-radius:5px; }
    /* Touch has no hover — keep delete permanently visible but quiet. */
    .ib-msg-del { opacity:.32; }""")

# ---------------------------------------------------------------- markup
old = """            <form method=\"POST\" action=\"{{ route('tenant.inbox.message.delete', $m->id) }}\" onsubmit=\"return confirm('Delete this message? It will be hidden from the conversation.')\" style=\"float:right;margin:-2px -2px 0 8px\">
              @csrf
              <button type=\"submit\" title=\"Delete message\" style=\"background:none;border:0;color:inherit;opacity:.3;cursor:pointer;font-size:14px;line-height:1;padding:0\">&times;</button>
            </form>
            @if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}
            <div class=\"ib-msg-time\">@if($cls === 'in' || $cls === 'out'){{ strtoupper($m->channel) }} · @endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>"""
assert s.count(old) == 1, 'I4 bubble markup anchor'
s = s.replace(old, """            <form method=\"POST\" action=\"{{ route('tenant.inbox.message.delete', $m->id) }}\" onsubmit=\"return confirm('Delete this message? It will be hidden from the conversation.')\" class=\"ib-msg-del\">
              @csrf
              <button type=\"submit\" title=\"Delete message\">&times;</button>
            </form>
            <div class=\"ib-msg-body\">@if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}</div>
            <div class=\"ib-msg-time\">@if($cls === 'in' || $cls === 'out')<span class=\"ib-chan\">{{ strtoupper($m->channel) }}</span>@endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- no surviving pre-wrap on the bubble, no --ia-surface-2 ---"
grep -n "white-space:pre-wrap\|ia-surface-2" resources/views/tenant/inbox/index.blade.php || echo "(clean)"

echo
echo "--- glued directive sweep ---"
python3 - <<'PY'
import io, re
s = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/inbox/index.blade.php', encoding='utf-8').read(), flags=re.S)
hits = re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|forelse|empty|endforelse)\b', s)
print('glued:', len(hits), hits[:4])
for a, b in [('@if','@endif'), ('@forelse','@endforelse'), ('@php','@endphp')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo
echo "apply-inbox-thread-polish: OK"
