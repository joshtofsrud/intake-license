#!/usr/bin/env bash
# apply-inbox-search.sh
# MARKER-INBOX-SEARCH — find a conversation by who it's with or what was said.
#
# Ordering already works — the query is orderByDesc('last_message_at') and
# InboxService bumps that column on every inbound and outbound post. The
# threads with no timestamp at the bottom are the blank ones left by the
# contact-form bug; MySQL sorts NULL last in a DESC order.
#
# What's missing is search. Modelled on Messages: one field, matches the
# person OR the words, and searches EVERYTHING — including closed threads —
# because when you're looking for a conversation you don't remember which
# bucket it's in. The status pills are therefore suppressed while a search
# is active rather than silently narrowing the results.
#
# Matching covers first/last name, the two together (so "Josh Tofsrud"
# works, which neither column matches alone), business name, email, phone,
# and message bodies.
#
# When the hit is in a message rather than the name, the row shows THAT
# message instead of the newest one — otherwise you search for a phrase and
# get back a list of rows that don't contain it. One extra query for the
# whole page, not one per row.
#
# SCALE NOTE: body matching is LIKE '%term%', which cannot use an index. It
# is bounded by tenant and capped at 100 rows, so it is fine at current
# size, but the answer at 10K tenants is a FULLTEXT index on
# tenant_messages.body and MATCH...AGAINST. Flagging rather than building
# that now — it changes matching semantics (word-based, minimum lengths)
# and deserves its own pass.
#
# Controller + view: optimize:clear and an fpm cycle.
set -e

python3 <<'PY'
import io

# ============================================================ controller
p = 'app/Http/Controllers/Tenant/InboxController.php'
s = io.open(p, encoding='utf-8').read()

old = """        $threads = TenantThread::where('tenant_id', $tenant->id)
            ->when($filter === 'unread', fn ($q) => $q->where(fn ($qq) => $qq->where('unread_count', '>', 0)->orWhere('status', 'needs_reply')))
            ->when($filter === 'closed', fn ($q) => $q->where('status', 'closed'))
            ->when($filter === 'all', fn ($q) => $q->where('status', '!=', 'closed'))
            ->with(['customer:id,first_name,last_name,phone,sms_opt_out_at', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();"""
assert s.count(old) == 1, 'S1 thread query anchor'
s = s.replace(old, """        // MARKER-INBOX-SEARCH — a search spans every bucket. Narrowing by the
        // status pill while someone is hunting for a conversation is how you
        // get "it's not there" for a thread that is simply closed.
        $q = trim((string) $request->query('q', ''));
        $searching = $q !== '';

        $threads = TenantThread::where('tenant_id', $tenant->id)
            ->when(! $searching && $filter === 'unread', fn ($qb) => $qb->where(fn ($qq) => $qq->where('unread_count', '>', 0)->orWhere('status', 'needs_reply')))
            ->when(! $searching && $filter === 'closed', fn ($qb) => $qb->where('status', 'closed'))
            ->when(! $searching && $filter === 'all', fn ($qb) => $qb->where('status', '!=', 'closed'))
            ->when($searching, function ($qb) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\\\\%', '\\\\_'], $q) . '%';
                $qb->where(function ($w) use ($like) {
                    $w->whereHas('customer', function ($c) use ($like) {
                        $c->where('first_name', 'like', $like)
                          ->orWhere('last_name', 'like', $like)
                          // Neither column alone matches a full name.
                          ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", [$like])
                          ->orWhere('business_name', 'like', $like)
                          ->orWhere('email', 'like', $like)
                          ->orWhere('phone', 'like', $like);
                    })->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $like));
                });
            })
            ->with(['customer:id,first_name,last_name,business_name,customer_type,phone,email,sms_opt_out_at', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        // MARKER-INBOX-SEARCH — when the hit was in a message, show that
        // message rather than the newest one. One query for the page.
        $searchHits = [];
        if ($searching && $threads->isNotEmpty()) {
            $like = '%' . str_replace(['%', '_'], ['\\\\%', '\\\\_'], $q) . '%';
            $searchHits = \\App\\Models\\Tenant\\TenantMessage::query()
                ->whereIn('thread_id', $threads->pluck('id'))
                ->where('body', 'like', $like)
                ->orderByDesc('created_at')
                ->get(['thread_id', 'body', 'created_at'])
                ->groupBy('thread_id')
                ->map(fn ($rows) => $rows->first())
                ->all();
        }""")

old = """        return view('tenant.inbox.index', compact('threads', 'filter', 'selected', 'needsReplyCount'));"""
assert s.count(old) == 1, 'S2 view return anchor'
s = s.replace(old, """        return view('tenant.inbox.index', compact('threads', 'filter', 'selected', 'needsReplyCount', 'q', 'searching', 'searchHits'));""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)

# ============================================================ view — styles
p = 'resources/views/tenant/inbox/index.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """  .ib-filters { display:flex; gap:6px; padding:12px; border-bottom:.5px solid var(--ia-border); }"""
assert s.count(old) == 1, 'S3 filters CSS anchor'
s = s.replace(old, """  .ib-filters { display:flex; gap:6px; padding:12px; border-bottom:.5px solid var(--ia-border); }
  /* MARKER-INBOX-SEARCH */
  .ib-search { padding:12px 12px 0; position:relative; }
  .ib-search input { width:100%; background:rgba(127,127,127,.10); border:0; border-radius:9px;
                     padding:9px 30px 9px 32px; font-size:13px; font-family:inherit; color:inherit; }
  .ib-search input:focus { outline:none; box-shadow:inset 0 0 0 1px var(--ia-border); }
  .ib-search input::placeholder { color:inherit; opacity:.45; }
  .ib-search-ico { position:absolute; left:23px; top:50%; transform:translateY(-30%);
                   font-size:13px; opacity:.4; pointer-events:none; }
  .ib-search-clear { position:absolute; right:22px; top:50%; transform:translateY(-30%);
                     font-size:15px; line-height:1; opacity:.4; text-decoration:none; color:inherit; }
  .ib-search-clear:hover { opacity:.9; }
  .ib-search-note { padding:10px 14px; font-size:11.5px; opacity:.55;
                    border-bottom:.5px solid var(--ia-border); }
  .ib-hit { color:var(--ia-accent); }""")

# ============================================================ view — markup
old = """    <div class=\"ib-filters\">"""
assert s.count(old) == 1, 'S4 filters markup anchor'
s = s.replace(old, """    {{-- MARKER-INBOX-SEARCH --}}
    <form class=\"ib-search\" method=\"GET\" action=\"{{ route('tenant.inbox.index') }}\" id=\"ib-search-form\">
      <span class=\"ib-search-ico\">&#9906;</span>
      <input type=\"search\" name=\"q\" value=\"{{ $q ?? '' }}\" autocomplete=\"off\"
             placeholder=\"Search names and messages\" id=\"ib-search-input\">
      @if(!empty($searching))
        <a class=\"ib-search-clear\" href=\"{{ route('tenant.inbox.index') }}\" title=\"Clear search\">&times;</a>
      @endif
    </form>

    @if(!empty($searching))
      <div class=\"ib-search-note\">
        {{ $threads->count() }} {{ Str::plural('conversation', $threads->count()) }} matching &ldquo;{{ $q }}&rdquo; &middot; searching all, including closed
      </div>
    @else
    <div class=\"ib-filters\">""")

old = """    <div style=\"overflow-y:auto;flex:1\">"""
assert s.count(old) == 1, 'S5 list wrapper anchor'
s = s.replace(old, """    @endif
    <div style=\"overflow-y:auto;flex:1\">""")

# snippet: prefer the matching message when searching
old = """          <div class=\"ib-snippet\">{{ Str::limit($t->latestMessage?->body ?? '—', 60) }}</div>"""
if s.count(old) != 1:
    # fall back to locating the snippet line however it is written
    import re
    m = re.search(r'[ \t]*<div class="ib-snippet">.*?</div>', s)
    assert m, 'S6 could not locate the snippet line'
    old = m.group(0)
    assert s.count(old) == 1, 'S6 snippet line not unique'

s = s.replace(old, """          {{-- MARKER-INBOX-SEARCH — show the message that matched, not the newest --}}
          @php $sc_hit = ($searchHits[$t->id] ?? null); @endphp
          <div class=\"ib-snippet\">
            @if($sc_hit)
              <span class=\"ib-hit\">&#9906;</span> {{ Str::limit($sc_hit->body, 58) }}
            @else
              {{ Str::limit($t->latestMessage?->body ?? '—', 60) }}
            @endif
          </div>""")

# debounced auto-submit
old = """  (function () { var m = document.getElementById('ib-msgs'); if (m) m.scrollTop = m.scrollHeight; })();"""
assert s.count(old) == 1, 'S7 script tail anchor'
s = s.replace(old, """  (function () { var m = document.getElementById('ib-msgs'); if (m) m.scrollTop = m.scrollHeight; })();
  // MARKER-INBOX-SEARCH — submit as you type, but not on every keystroke.
  (function () {
    var f = document.getElementById('ib-search-form');
    var i = document.getElementById('ib-search-input');
    if (!f || !i) { return; }
    var t = null;
    i.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { f.submit(); }, 350);
    });
    // Keep the caret where it was after the reload.
    if (i.value) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); }
  })();""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo "--- blade sweep ---"
python3 - <<'PY'
import io, re
s = re.sub(r'\{\{--.*?--\}\}', '', io.open('resources/views/tenant/inbox/index.blade.php', encoding='utf-8').read(), flags=re.S)
print('glued:', len(re.findall(r'\w@(?:if|endif|foreach|endforeach|elseif|else|unless|php|endphp|forelse|empty|endforelse|push|endpush|section|endsection)\b', s)))
for a, b in [('@if','@endif'), ('@forelse','@endforelse'), ('@php','@endphp'), ('@push','@endpush'), ('@section','@endsection')]:
    o = len(re.findall(r'\B'+a+r'\b', s)); c = len(re.findall(r'\B'+b+r'\b', s))
    print(a, o, b, c, 'OK' if o == c else 'MISMATCH')
PY

echo "--- controller balance ---"
python3 - <<'PY'
import io
s = io.open('app/Http/Controllers/Tenant/InboxController.php', encoding='utf-8').read()
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

echo
echo "apply-inbox-search: OK"
