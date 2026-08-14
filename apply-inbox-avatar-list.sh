#!/bin/bash
# apply-inbox-avatar-list.sh
#
# MARKER-INBOX-AVATAR — style A from the approved mockup: the inbox thread
# list as a messaging-app row. Initials avatar, name and time on one line,
# two clamped lines of message, unread as weight plus an accent count.
#
# HOW IT AVOIDS THE MISTAKE THAT BROKE THE LAST TWO ATTEMPTS:
#   - the new @media block is inserted LAST in the stylesheet, after
#     MARKER-PATCH-434/435, because media queries add no specificity and the
#     later rule wins at equal weight. 434 sets .ib-thread padding and
#     .ib-thread-name size on mobile; this block has to out-order it.
#   - no layout containers are moved, nothing is made sticky, no page-level
#     .ia-page-head rules. Only the row itself changes.
#
# DESKTOP IS UNCHANGED. The avatar and the channel glyph render in the
# markup but are display:none above 980px, so the sidebar list keeps its
# current compact look.
#
# TWO THINGS THAT DO SHOW ON DESKTOP TOO, deliberately, because they are
# information rather than styling:
#   - "You:" prefixes a snippet whose newest message was outbound, so you
#     can see whose court the ball is in without opening the thread.
#   - the Needs reply pill stays exactly as it was.
#
# Snippet truncation moves from Str::limit(60) to a 2-line CSS clamp: a
# hard character count cuts mid-word at any width, and on a 390px phone 60
# characters left most of the second line empty.
set -e

MARKER="MARKER-INBOX-AVATAR"
V="resources/views/tenant/inbox/index.blade.php"

[ -f "$V" ] || { echo "ERROR: missing $V — run from the repo root"; exit 1; }
if grep -q "$MARKER" "$V" 2>/dev/null; then
  echo "ok: already applied ($MARKER present) — no-op"
  exit 0
fi

python3 - <<'PY'
import io
p = 'resources/views/tenant/inbox/index.blade.php'
src = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------
# 1. Row markup: avatar + body + unread badge
# ---------------------------------------------------------------
old = """        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }}"
           href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null, 'thread' => $t->id])) }}">
          <div class="ib-thread-top">
            <span class="ib-thread-name">
              @if((int) $t->unread_count > 0 || $t->status === 'needs_reply')<span class="ib-dot"></span>@endif
              {{ $t->customer?->fullName() }}
              @if($t->status === 'needs_reply')<span class="ib-nr">Needs reply</span>@endif
            </span>
            <span class="ib-thread-time">{{ $t->last_message_at ? tlocal_datetime($t->last_message_at, 'M j, g:i A') : '' }}</span>
          </div>
          {{-- MARKER-INBOX-SEARCH — show the message that matched, not the newest --}}
          @php $sc_hit = ($searchHits[$t->id] ?? null); @endphp
          <div class="ib-snippet">
            @if($sc_hit)
              <span class="ib-hit">&#9906;</span> {{ Str::limit($sc_hit->body, 58) }}
            @else
              {{ Str::limit($t->latestMessage?->body ?? '—', 60) }}
            @endif
          </div>
        </a>"""
assert src.count(old) == 1, 'thread row markup'

new = """        {{-- MARKER-INBOX-AVATAR --}}
        @php
          $ibName    = trim((string) ($t->customer?->fullName() ?? ''));
          // Initials from the first two words; a business name gives its
          // first two words, a person gives first + last.
          $ibParts   = preg_split('/\\s+/', $ibName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
          $ibInit    = strtoupper(implode('', array_map(
                         fn ($w) => mb_substr($w, 0, 1),
                         array_slice($ibParts, 0, 2)
                       ))) ?: '?';
          // Stable per-customer colour: same person, same swatch every time.
          $ibHue     = (crc32((string) ($t->customer_id ?? $t->id)) % 6) + 1;
          $ibUnread  = (int) $t->unread_count > 0 || $t->status === 'needs_reply';
          $ibOut     = ($t->latestMessage?->direction ?? null) === 'out';
        @endphp
        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }} {{ $ibUnread ? 'is-unread' : '' }}"
           href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null, 'thread' => $t->id])) }}">
          <span class="ib-av ib-av-{{ $ibHue }}" aria-hidden="true">
            {{ $ibInit }}
            @if($t->channel === 'email')<span class="ib-av-ch">&#9993;</span>@endif
          </span>
          <span class="ib-thread-body">
            <div class="ib-thread-top">
              <span class="ib-thread-name">
                @if($ibUnread)<span class="ib-dot"></span>@endif
                {{ $ibName }}
                @if($t->status === 'needs_reply')<span class="ib-nr">Needs reply</span>@endif
              </span>
              <span class="ib-thread-time">{{ $t->last_message_at ? tlocal_datetime($t->last_message_at, 'M j, g:i A') : '' }}</span>
            </div>
            {{-- MARKER-INBOX-SEARCH — show the message that matched, not the newest --}}
            @php $sc_hit = ($searchHits[$t->id] ?? null); @endphp
            <div class="ib-snippet">
              @if($sc_hit)
                <span class="ib-hit">&#9906;</span> {{ Str::limit($sc_hit->body, 58) }}
              @else
                @if($ibOut)<span class="ib-you">You:</span> @endif{{ Str::limit($t->latestMessage?->body ?? '—', 140) }}
              @endif
            </div>
          </span>
          @if((int) $t->unread_count > 0)
            <span class="ib-count">{{ (int) $t->unread_count > 9 ? '9+' : (int) $t->unread_count }}</span>
          @endif
        </a>"""
src = src.replace(old, new, 1)

# ---------------------------------------------------------------
# 2. Styles — appended LAST, after MARKER-PATCH-434/435
# ---------------------------------------------------------------
close = src.rindex('</style>')
src = src[:close] + """
  /* MARKER-INBOX-AVATAR — style A. This block MUST remain the last thing in
     the stylesheet: media queries carry no specificity, so it has to
     out-order MARKER-PATCH-434's .ib-thread rules above at equal weight. */
  .ib-av, .ib-count, .ib-you { display:none; }   /* desktop keeps its compact rows */

  @media (max-width: 980px) {
    .ib-thread { display:flex; align-items:flex-start; gap:12px; padding:11px 14px; }
    .ib-thread-body { flex:1; min-width:0; }

    .ib-av {
      display:flex; align-items:center; justify-content:center; position:relative;
      flex:0 0 42px; width:42px; height:42px; border-radius:50%;
      font-size:14.5px; font-weight:650; color:#0d0d0d; letter-spacing:.01em;
    }
    .ib-av-1{background:#8FB8DE} .ib-av-2{background:#C9A96A} .ib-av-3{background:#9FC49A}
    .ib-av-4{background:#D3A0A0} .ib-av-5{background:#B0A5CE} .ib-av-6{background:#8FC7C2}
    .ib-av-ch {
      position:absolute; right:-2px; bottom:-2px; width:16px; height:16px;
      border-radius:50%; background:var(--ia-bg); border:1px solid var(--ia-border);
      display:flex; align-items:center; justify-content:center;
      font-size:8.5px; color:var(--ia-text-dim);
    }

    .ib-thread-name { font-size:15px; font-weight:500; }
    .ib-thread.is-unread .ib-thread-name { font-weight:700; }
    .ib-thread.is-unread .ib-thread-time { color:var(--ia-accent); opacity:1; }

    /* two-line clamp beats a character count: it fills the width it has */
    .ib-snippet {
      font-size:13.5px; margin-top:3px; line-height:1.35; white-space:normal;
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }
    .ib-you { display:inline; color:rgba(255,255,255,.38); }

    .ib-count {
      display:flex; align-items:center; justify-content:center; align-self:center;
      flex:0 0 auto; min-width:19px; height:19px; padding:0 6px; border-radius:99px;
      background:var(--ia-accent); color:var(--ia-accent-text);
      font-size:11px; font-weight:700;
    }
    /* the dot and the count say the same thing; the count is the richer one */
    .ib-thread.is-unread .ib-dot { display:none; }
  }
""" + src[close:]

io.open(p, 'w', encoding='utf-8').write(src)
print('ok: avatar rows + styles appended last')
PY

echo ""
echo "-- order check: the new block must be the LAST @media in the file --"
awk '/MARKER-PATCH-434/ && !p434 {p434=NR} /MARKER-INBOX-AVATAR — style A/ {av=NR} END{print "   patch-434:", p434, "| avatar block:", av, (av>p434 ? "OK" : "STILL WRONG")}' \
  resources/views/tenant/inbox/index.blade.php

echo ""
echo "== inbox avatar list applied =="
echo "Post-deploy: php artisan optimize:clear"
