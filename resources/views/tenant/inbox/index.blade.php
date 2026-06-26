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
  .ib-msg { max-width:72%; padding:9px 12px; border-radius:12px; font-size:13px; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
  .ib-msg.in  { align-self:flex-start; background:rgba(127,127,127,.10); border-bottom-left-radius:4px; }
  .ib-msg.out { align-self:flex-end; background:var(--ia-text); color:var(--ia-bg, #fff); border-bottom-right-radius:4px; }
  .ib-msg.note { align-self:stretch; max-width:none; background:#FAEEDA; color:#854F0B; font-size:12.5px; }
  .ib-msg.sys  { align-self:center; max-width:none; background:transparent; box-shadow:inset 0 0 0 .5px var(--ia-border); font-size:11.5px; opacity:.7; }
  .ib-msg-time { font-size:10px; opacity:.45; margin-top:4px; }
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
    .ib-msg { max-width:80%; padding:10px 13px; border-radius:15px; font-size:14px; }
    .ib-msg.in  { background:var(--ia-surface-2); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; border-bottom-right-radius:5px; }
    /* composer — pill field + round send */
    .ib-compose-field { border-radius:20px; min-height:44px; padding:11px 16px; }
    .ib-compose-row { gap:8px; }
    .ib-compose-send { width:44px; height:44px; min-width:44px; border-radius:50%; padding:0; display:flex; align-items:center; justify-content:center; }
    .ib-send-txt { display:none; }
    .ib-send-ar { display:inline; font-size:19px; line-height:1; }
  }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inbox</h1>
    <p class="ia-page-subtitle">Every customer text in one place.<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ib-wrap">
  <div class="ib-list">
    <div class="ib-filters">
      <a class="ib-pill {{ $filter === 'all' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index') }}">Open</a>
      <a class="ib-pill {{ $filter === 'unread' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'unread']) }}">Needs reply{{ $needsReplyCount > 0 ? ' (' . $needsReplyCount . ')' : '' }}</a>
      <a class="ib-pill {{ $filter === 'closed' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'closed']) }}">Closed</a>
    </div>
    <div style="overflow-y:auto;flex:1">
      @forelse($threads as $t)
        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }}"
           href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null, 'thread' => $t->id])) }}">
          <div class="ib-thread-top">
            <span class="ib-thread-name">
              @if((int) $t->unread_count > 0 || $t->status === 'needs_reply')<span class="ib-dot"></span>@endif
              {{ $t->customer?->first_name }} {{ $t->customer?->last_name }}
              @if($t->status === 'needs_reply')<span class="ib-nr">Needs reply</span>@endif
            </span>
            <span class="ib-thread-time">{{ $t->last_message_at ? tlocal_datetime($t->last_message_at, 'M j, g:i A') : '' }}</span>
          </div>
          <div class="ib-snippet">{{ \Illuminate\Support\Str::limit($t->latestMessage?->body ?? '', 70) }}</div>
        </a>
      @empty
        <div style="padding:30px 16px;font-size:12.5px;opacity:.5;text-align:center">
          No conversations here yet. Inbound texts to your business number land in this list automatically.
        </div>
      @endforelse
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
          <a href="{{ route('tenant.customers.show', $selected->customer_id) }}" class="ib-conv-name" style="font-weight:700;text-decoration:none;color:inherit">{{ $selected->customer?->first_name }} {{ $selected->customer?->last_name }}</a>
          <div style="font-size:11.5px;opacity:.55">
            {{ $selected->customer?->phone ?? 'no phone' }}
            @if($selected->customer?->email) · {{ $selected->customer?->email }}@endif
            @if($selected->customer?->sms_opt_out_at) · <span style="color:#A32D2D;font-weight:600">opted out (STOP)</span>@endif
          </div>
        </div>
        </div>
        <form method="POST" action="{{ route('tenant.inbox.status', $selected->id) }}">@csrf
          <button type="submit" class="ia-btn" style="font-size:11.5px">{{ $selected->status === 'closed' ? 'Reopen' : 'Close' }}</button>
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
            <form method="POST" action="{{ route('tenant.inbox.message.delete', $m->id) }}" onsubmit="return confirm('Delete this message? It will be hidden from the conversation.')" style="float:right;margin:-2px -2px 0 8px">
              @csrf
              <button type="submit" title="Delete message" style="background:none;border:0;color:inherit;opacity:.3;cursor:pointer;font-size:14px;line-height:1;padding:0">&times;</button>
            </form>
            @if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}
            <div class="ib-msg-time">@if($cls === 'in' || $cls === 'out'){{ strtoupper($m->channel) }} · @endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>
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
</script>

@endsection
