@extends('layouts.tenant.app')
@php $pageTitle = 'Inbox'; @endphp

{{-- MARKER-PATCH-221 — unified inbox: two-pane SMS conversations. --}}

@push('styles')
<style>
  .ib-wrap { display:grid; grid-template-columns:340px 1fr; gap:0; border-radius:12px; overflow:hidden;
             box-shadow:inset 0 0 0 .5px var(--ia-border); background:var(--ia-surface); min-height:560px; }
  @media (max-width: 980px) { .ib-wrap { grid-template-columns:1fr; } .ib-conv { display:none; } .ib-conv.has-sel { display:flex; } }
  .ib-list { border-right:.5px solid var(--ia-border); display:flex; flex-direction:column; }
  .ib-filters { display:flex; gap:6px; padding:12px; border-bottom:.5px solid var(--ia-border); }
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
  .ib-hit { color:var(--ia-accent); }
  .ib-pill { font-size:11.5px; padding:4px 10px; border-radius:999px; box-shadow:inset 0 0 0 .5px var(--ia-border);
             text-decoration:none; color:inherit; opacity:.7; }
  .ib-pill.is-active { background:var(--ia-text); color:var(--ia-bg, #fff); opacity:1; }
  .ib-thread { display:block; padding:12px 14px; border-bottom:.5px solid var(--ia-border); text-decoration:none; color:inherit; }
  .ib-thread:hover, .ib-thread.is-sel { background:rgba(127,127,127,.06); }
  .ib-thread-top { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
  .ib-thread-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-thread-time { font-size:10.5px; opacity:.45; white-space:nowrap; }
  .ib-snippet { font-size:12px; opacity:.55; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-dot { width:8px; height:8px; border-radius:50%; background:#B8801A; display:inline-block; margin-right:6px; }
  .ib-conv { display:flex; flex-direction:column; min-width:0; }
  .ib-conv-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px 16px; border-bottom:.5px solid var(--ia-border); }
  .ib-msgs { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
  /* MARKER-INBOX-POLISH — pre-wrap belongs on the body only. On the bubble it
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
             background:rgba(127,127,127,.22); vertical-align:1px; }
  .ib-msg.note { align-self:stretch; max-width:none; background:#FAEEDA; color:#854F0B; font-size:12.5px; }
  .ib-msg.sys  { align-self:center; max-width:none; background:transparent; box-shadow:inset 0 0 0 .5px var(--ia-border); font-size:11.5px; opacity:.7; }
  .ib-msg-time { font-size:10px; opacity:.45; margin-top:5px; }
  /* MARKER-INBOX-VIEWPORT — give the shell a ceiling so the inner scrollers
     (.ib-msgs and the thread-list wrapper) actually have somewhere to scroll.
     min-height:0 on each flex ancestor is the load-bearing part: without it a
     flex item refuses to shrink below its content and the overflow escapes
     upward instead of scrolling. Scoped to this page by the pushed styles. */
  @media (min-width: 981px) {
    .ia-shell   { height:100dvh; min-height:0; }
    .ia-main    { min-height:0; }
    .ia-content { min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    /* Everything above the inbox keeps its natural height; only the inbox
       takes the slack. Guards against a flash banner getting squashed. */
    .ia-content > * { flex:0 0 auto; }
    .ib-wrap { flex:1 1 auto; min-height:360px; }
    /* MARKER-INBOX-GRID-ROWS — the grid's implicit row is `auto`, so it sizes
       to the tallest column and blows past the height we just gave .ib-wrap.
       minmax(0,1fr) pins it to the container AND allows it to shrink below
       its content; min-height:0 does the same for the two columns. Without
       both, .ib-msgs and the thread-list wrapper see no overflow and never
       scroll — the content just gets clipped by .ib-wrap's overflow:hidden. */
    .ib-wrap { grid-template-rows: minmax(0, 1fr); }
    .ib-list, .ib-conv { min-height: 0; }
    /* .ib-msgs goes back to plain flex:1 — with a bounded parent it fills the
       space and scrolls, so the composer sits directly under the last
       message instead of after a gap. */
    .ib-msgs { max-height:none; }
  }
  .ib-compose { border-top:.5px solid var(--ia-border); padding:12px 16px; }
  .ib-empty { display:flex; align-items:center; justify-content:center; flex:1; font-size:13px; opacity:.5; padding:40px; text-align:center; }
  /* MARKER-PATCH-433 — mobile: full-screen conversation + back arrow */
  .ib-back { display:none; }
  @media (max-width: 980px) {
    .ib-conv.has-sel { position:fixed; inset:0; z-index:500; background:var(--ia-surface); border-radius:0; }
    .ib-conv.has-sel .ib-conv-head { padding-top:max(12px, env(safe-area-inset-top)); }
    .ib-conv.has-sel .ib-msgs { overscroll-behavior:contain; }
    .ib-conv.has-sel .ib-compose { padding-bottom:max(12px, env(safe-area-inset-bottom)); }
    .ib-conv-head-left { display:flex; align-items:center; gap:10px; min-width:0; }
    .ib-back { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; flex:0 0 auto; margin:-4px 2px -4px -6px; border-radius:8px; text-decoration:none; color:inherit; font-size:21px; line-height:1; opacity:.75; }
    .ib-back:active, .ib-back:hover { background:rgba(127,127,127,.12); opacity:1; }
  }
  /* MARKER-PATCH-434 — mobile inbox styling to match the approved mockup */
  .ib-nr { display:none; }
  .ib-conv-name { font-size:14px; }
  .ib-compose-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
  .ib-compose-row { display:flex; gap:10px; align-items:flex-end; }
  .ib-compose-field { flex:1 1 auto; min-width:0; }
  .ib-compose-send { flex:0 0 auto; }
  .ib-send-ar { display:none; }
  @media (max-width: 980px) {
    .ib-sub-more { display:none; }
    /* thread list — airier rows, mockup sizing */
    .ib-thread { padding:15px 16px; }
    .ib-thread-name { font-size:16px; }
    .ib-thread-time { font-size:12px; }
    .ib-snippet { font-size:14px; margin-top:4px; }
    .ib-dot { width:9px; height:9px; background:var(--ia-accent); margin-right:8px; }
    .ib-nr { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em; color:#B8801A; border:1px solid rgba(184,128,26,.45); border-radius:6px; padding:1px 6px; margin-left:8px; vertical-align:middle; }
    /* conversation — bigger text, green outbound bubbles */
    .ib-conv-name { font-size:17px; }
    .ib-msgs { padding:14px 16px; }
    .ib-msg { max-width:80%; padding:10px 24px 10px 13px; border-radius:15px; font-size:14px; }
    /* MARKER-INBOX-POLISH — --ia-surface-2 does not exist in Intake's themes,
       so this resolved to nothing and inbound bubbles were transparent. */
    .ib-msg.in  { background:rgba(127,127,127,.14); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; box-shadow:none; border-bottom-right-radius:5px; }
    /* Touch has no hover — keep delete permanently visible but quiet. */
    .ib-msg-del { opacity:.32; }
    /* composer — pill field + round send */
    .ib-compose-field { border-radius:20px; min-height:44px; padding:11px 16px; }
    .ib-compose-row { gap:8px; }
    .ib-compose-send { width:44px; height:44px; min-width:44px; border-radius:50%; padding:0; display:flex; align-items:center; justify-content:center; }
    .ib-send-txt { display:none; }
    .ib-send-ar { display:inline; font-size:19px; line-height:1; }
  }
  /* MARKER-PATCH-435 — mobile: hide the empty pane, edge-to-edge list, fix row overflow */
  @media (max-width: 980px) {
    .ib-conv { display:none; }            /* empty "pick a conversation" pane stays hidden on phones */
    .ib-conv.has-sel { display:flex; }    /* a selected conversation still shows (full-screen overlay) */
    .ib-wrap { min-width:0; border-radius:0; box-shadow:none; min-height:0; background:transparent; }
    .ib-list { min-width:0; border-right:0; }
    .ib-thread-name { min-width:0; }
  }
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

  /* MARKER-INBOX-MOBILE ---------------------------------------------- */
  .ib-more { display:none; width:100%; margin:10px 0 4px; padding:12px;
    background:transparent; border:.5px solid var(--ia-border);
    border-radius:var(--ia-r-md); color:var(--ia-text); font-size:13px;
    font-family:inherit; cursor:pointer; }
  .ib-more:hover { background:rgba(127,127,127,.06); }
  .ib-more.on { display:block; }
  .ib-capnote { display:none; padding:12px 16px; font-size:12px; opacity:.5;
    text-align:center; line-height:1.5; }
  .ib-capnote.on { display:block; }

  /* MARKER-INBOX-MOBILE-2 — the sticky controls and header overrides that
     lived here are removed: they left a dead gap under the header and clipped
     the first conversation. The list keeps its original layout. */
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inbox</h1>
    <p class="ia-page-subtitle">Every customer text in one place.<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
  {{-- MARKER-INBOX-NEW --}}
  <div class="ia-page-actions">
    <button type="button" class="ia-btn ia-btn--primary" id="ib-new-btn">+ New conversation</button>
  </div>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif
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


<div class="ib-wrap">
  <div class="ib-list">
    {{-- MARKER-INBOX-SEARCH --}}
    <form class="ib-search" method="GET" action="{{ route('tenant.inbox.index') }}" id="ib-search-form">
      <span class="ib-search-ico">&#9906;</span>
      <input type="search" name="q" value="{{ $q ?? '' }}" autocomplete="off"
             placeholder="Search names and messages" id="ib-search-input">
      @if(!empty($searching))
        <a class="ib-search-clear" href="{{ route('tenant.inbox.index') }}" title="Clear search">&times;</a>
      @endif
    </form>

    @if(!empty($searching))
      <div class="ib-search-note">
        {{ $threads->count() }} {{ Str::plural('conversation', $threads->count()) }} matching &ldquo;{{ $q }}&rdquo; &middot; searching all, including closed
      </div>
    @else
    <div class="ib-filters">
      <a class="ib-pill {{ $filter === 'all' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index') }}">Open</a>
      <a class="ib-pill {{ $filter === 'unread' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'unread']) }}">Needs reply{{ $needsReplyCount > 0 ? ' (' . $needsReplyCount . ')' : '' }}</a>
      <a class="ib-pill {{ $filter === 'closed' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'closed']) }}">Closed</a>
    </div>
    @endif
    {{-- MARKER-INBOX-MOBILE-2 — original container; the id is retained only
         so the chunking script can find it. --}}
    <div id="ib-scroll" style="overflow-y:auto;flex:1">
      @forelse($threads as $t)
        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }}"
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
        </a>
      @empty
        <div style="padding:30px 16px;font-size:12.5px;opacity:.5;text-align:center">
          No conversations here yet. Inbound texts to your business number land here automatically &mdash; or start one with &ldquo;+ New conversation&rdquo;.
        </div>
      @endforelse

      {{-- MARKER-INBOX-MOBILE --}}
      <button type="button" class="ib-more" id="ib-more"></button>

      {{-- MARKER-INBOX-MOBILE — the controller stops at 100. Saying so beats
           a list that quietly ends. --}}
      @if($threads->count() >= 100)
        <div class="ib-capnote on">
          Showing the 100 most recent conversations. Search to reach older ones.
        </div>
      @endif
    </div>
  </div>

  <div class="ib-conv {{ $selected ? 'has-sel' : '' }}">
    @if(!$selected)
      <div class="ib-empty">Pick a conversation — or text your business number to see one arrive.</div>
    @else
      <div class="ib-conv-head">
        <div class="ib-conv-head-left">
        <a class="ib-back" href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null])) }}" aria-label="Back to conversations">&lsaquo;</a>
        <div style="min-width:0">
          <a href="{{ route('tenant.customers.show', $selected->customer_id) }}" class="ib-conv-name" style="font-weight:700;text-decoration:none;color:inherit">{{ $selected->customer?->fullName() }}</a>
          <div style="font-size:11.5px;opacity:.55">
            {{ $selected->customer?->phone ?? 'no phone' }}
            @if($selected->customer?->email) · {{ $selected->customer?->email }}@endif
            @if($selected->customer?->sms_opt_out_at) · <span style="color:#A32D2D;font-weight:600">opted out (STOP)</span>@endif
          </div>
        </div>
        </div>
        <form method="POST" action="{{ route('tenant.inbox.status', $selected->id) }}">@csrf
          {{-- MARKER-INBOX-CLOSE — on a phone "Close" reads as "close this message",
     which is the opposite of what it does. --}}
<button type="submit" class="ia-btn" style="font-size:11.5px">{{ $selected->status === 'closed' ? 'Reopen ticket' : 'Close ticket' }}</button>
        </form>
      </div>

      <div class="ib-msgs" id="ib-msgs">
        @forelse($selected->messages as $m)
          @php
            $cls = match (true) {
              $m->kind === 'internal_note' => 'note',
              $m->direction === 'system'   => 'sys',
              $m->direction === 'in'       => 'in',
              default                      => 'out',
            };
          @endphp
          <div class="ib-msg {{ $cls }}">
            {{-- MARKER-PATCH-401 — delete a single message --}}
            <form method="POST" action="{{ route('tenant.inbox.message.delete', $m->id) }}" onsubmit="return confirm('Delete this message? It will be hidden from the conversation.')" class="ib-msg-del">
              @csrf
              <button type="submit" title="Delete message">&times;</button>
            </form>
            <div class="ib-msg-body">@if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}</div>
            <div class="ib-msg-time">@if($cls === 'in' || $cls === 'out')<span class="ib-chan">{{ strtoupper($m->channel) }}</span>@endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>
          </div>
        @empty
          <div class="ib-empty">No messages yet.</div>
        @endforelse
      </div>

      @php
        // MARKER-PATCH-397 — default the reply channel to the customer's last inbound.
        $lastIn = $selected->messages->where('direction', 'in')->last();
        $replyDefault = in_array($lastIn?->channel ?? '', ['web', 'email'], true) ? 'email' : 'sms';
      @endphp
      <div class="ib-compose">
        <form method="POST" action="{{ route('tenant.inbox.send', $selected->id) }}">
          @csrf
          <div class="ib-compose-meta">
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              Reply via
              <select name="reply_channel" class="ia-input" style="font-size:12px;padding:3px 6px;width:auto">
                <option value="sms"   {{ $replyDefault === 'sms'   ? 'selected' : '' }}>Text (SMS)</option>
                <option value="email" {{ $replyDefault === 'email' ? 'selected' : '' }}>Email</option>
              </select>
            </label>
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              <input type="checkbox" name="as_note" value="1"> Internal note
            </label>
          </div>
          <div class="ib-compose-row">
            <textarea name="body" rows="2" maxlength="1200" required placeholder="Type your reply…" class="ia-input ib-compose-field" style="resize:vertical"></textarea>
            <button type="submit" class="ia-btn ia-btn--primary ib-compose-send"><span class="ib-send-txt">Send</span><span class="ib-send-ar" aria-hidden="true">&uarr;</span></button>
          </div>
        </form>
      </div>
    @endif
  </div>
</div>

<script>
  (function () { var m = document.getElementById('ib-msgs'); if (m) m.scrollTop = m.scrollHeight; })();
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
  })();
</script>

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

{{-- MARKER-INBOX-MOBILE — cap what is painted, not what is loaded. The
     rows stay in the DOM (display:none) so every href and the selected-thread
     highlight keep working. --}}
<script>
(function () {
  var CHUNK = 20;
  var scroll = document.getElementById('ib-scroll');
  var btn = document.getElementById('ib-more');
  if (!scroll || !btn) return;

  var rows = Array.prototype.slice.call(scroll.querySelectorAll('.ib-thread'));
  var shown = CHUNK;

  function paint() {
    rows.forEach(function (row, i) {
      row.style.display = i < shown ? '' : 'none';
    });
    var remaining = rows.length - shown;
    btn.classList.toggle('on', remaining > 0);
    if (remaining > 0) {
      btn.textContent = 'Show ' + Math.min(CHUNK, remaining) + ' more \u00b7 ' + remaining + ' not shown';
    }
  }

  // A thread opened from a link must never be one of the hidden ones.
  var sel = scroll.querySelector('.ib-thread.is-sel');
  if (sel) {
    var at = rows.indexOf(sel);
    if (at >= shown) shown = Math.ceil((at + 1) / CHUNK) * CHUNK;
  }

  btn.addEventListener('click', function () {
    shown += CHUNK;
    paint();
  });

  paint();
})();
</script>

@endsection
