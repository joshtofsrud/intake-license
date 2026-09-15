<x-filament-panels::page>
{{-- MARKER-INBOX --}}
<style>
  .ib{--ib-line:var(--ia-border,rgba(127,127,127,.22));--ib-accent:#8b7cf6;--ib-warn:#f0c46a}
  .ib-tabs{display:flex;gap:4px;border-bottom:1px solid var(--ib-line);margin-bottom:14px}
  .ib-tab{background:none;border:0;color:inherit;font:inherit;font-size:13.5px;padding:10px 14px;cursor:pointer;opacity:.6;
    border-bottom:2px solid transparent;margin-bottom:-1px;display:flex;gap:8px;align-items:center}
  .ib-tab.on{opacity:1;border-bottom-color:var(--ib-accent)}
  .ib-tab .n{font-size:11px;padding:2px 7px;border-radius:99px;background:rgba(240,196,106,.18);color:var(--ib-warn)}
  .ib-show{display:flex;gap:6px;margin-left:auto;align-items:center;font-size:12px;opacity:.75}
  .ib-show button{background:none;border:1px solid var(--ib-line);border-radius:99px;color:inherit;font:inherit;font-size:12px;padding:3px 10px;cursor:pointer}
  .ib-show button.on{background:rgba(139,124,246,.15);border-color:rgba(139,124,246,.5)}
  .ib-cols{display:grid;grid-template-columns:minmax(320px,1fr) minmax(0,1.4fr);gap:14px;align-items:start}
  @media(max-width:1000px){.ib-cols{grid-template-columns:1fr}}
  .ib-list{border:1px solid var(--ib-line);border-radius:12px;overflow:hidden}
  .ib-row{display:grid;grid-template-columns:1fr auto;gap:10px;padding:11px 14px;border-bottom:1px solid rgba(127,127,127,.12);cursor:pointer}
  .ib-row:last-child{border-bottom:0}
  .ib-row.on{background:rgba(139,124,246,.12);box-shadow:inset 2px 0 0 var(--ib-accent)}
  .ib-row .t{font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ib-row.new .t{font-weight:700}
  .ib-row .m{font-size:11.5px;opacity:.55;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ib-row .when{font-size:11px;opacity:.5;white-space:nowrap}
  .ib-pill{font-size:10.5px;padding:2px 7px;border-radius:99px;border:1px solid var(--ib-line);opacity:.8;margin-left:6px}
  .ib-empty{padding:34px 14px;text-align:center;opacity:.5;font-size:13px}
  .ib-pane{border:1px solid var(--ib-line);border-radius:12px;padding:16px 18px;position:sticky;top:14px}
  .ib-pane h3{font-size:16px;font-weight:700;margin:0 0 4px}
  .ib-from{font-size:12.5px;opacity:.7;line-height:1.6}
  .ib-body{white-space:pre-wrap;font-size:13.5px;line-height:1.6;margin:14px 0;padding:12px 14px;border:1px solid var(--ib-line);border-radius:10px}
  .ib-acts{display:flex;gap:6px;flex-wrap:wrap}
  .ib-btn{border:1px solid var(--ib-line);background:none;color:inherit;font:inherit;font-size:12.5px;padding:6px 11px;border-radius:8px;cursor:pointer}
  .ib-btn.pri{background:var(--ib-accent);border-color:var(--ib-accent);color:#14121f;font-weight:600}
  .ib-reply textarea{width:100%;min-height:110px;background:transparent;border:1px solid var(--ib-line);border-radius:10px;color:inherit;font:inherit;font-size:13.5px;padding:10px 12px;resize:vertical}
  .ib-hint{font-size:11.5px;opacity:.55;margin-top:6px;line-height:1.5}
  .ib-replied{margin-top:12px;padding:10px 12px;border-left:2px solid var(--ib-accent);font-size:13px;white-space:pre-wrap;opacity:.85}
  .ib-legend{font-size:12px;opacity:.6;line-height:1.6;margin-bottom:12px}
</style>

<div class="ib">
  <div class="ib-legend">
    <b>Messages</b> are people writing to you and expect a reply. <b>Alerts</b> are the platform telling you
    something and expect a dismiss. New messages also email
    {{ $notifyAddress ?: 'nobody — set an address on the dashboard alert setting' }}.
  </div>

  <div class="ib-tabs">
    <button type="button" class="ib-tab {{ $tab === 'messages' ? 'on' : '' }}" wire:click="setTab('messages')">
      Messages @if($unreadMsgs)<span class="n">{{ $unreadMsgs }}</span>@endif
    </button>
    <button type="button" class="ib-tab {{ $tab === 'alerts' ? 'on' : '' }}" wire:click="setTab('alerts')">
      Alerts @if($unreadAlerts)<span class="n">{{ $unreadAlerts }}</span>@endif
    </button>
    <div class="ib-show">
      @foreach(['open' => 'Open', 'archived' => 'Archived', 'spam' => 'Spam', 'all' => 'All'] as $k => $l)
        @if($k !== 'spam' || $tab === 'messages')
          <button type="button" class="{{ $show === $k ? 'on' : '' }}" wire:click="setShow('{{ $k }}')">{{ $l }}</button>
        @endif
      @endforeach
      @if($tab === 'alerts' && $show === 'open' && $rows->count())
        <button type="button" wire:click="dismissAllAlerts" style="margin-left:6px">Dismiss all</button>
      @endif
    </div>
  </div>

  <div class="ib-cols">
    <div class="ib-list">
      @forelse($rows as $r)
        <div class="ib-row {{ $r->status === 'new' ? 'new' : '' }} {{ $sel && $sel->id === $r->id ? 'on' : '' }}"
             wire:click="open('{{ $r->id }}')">
          <div style="min-width:0">
            <div class="t">
              {{ $r->subject ?: ($r->name ?: 'Message') }}
              @if($r->tenant)<span class="ib-pill">{{ $r->tenant->name }}</span>@endif
              @if($r->replied_at)<span class="ib-pill">replied</span>@endif
            </div>
            <div class="m">
              @if($r->kind === 'alert')
                {{ $r->ref_id }} · {{ \Illuminate\Support\Str::limit((string) $r->body, 90) }}
              @else
                {{ $r->name }}{{ $r->email ? ' · ' . $r->email : '' }} · {{ \Illuminate\Support\Str::limit((string) $r->body, 70) }}
              @endif
            </div>
          </div>
          <div class="when">{{ $r->created_at->diffForHumans(null, true) }}</div>
        </div>
      @empty
        <div class="ib-empty">
          @if($tab === 'alerts') Nothing needs your attention. @else No messages here. @endif
        </div>
      @endforelse
    </div>

    <div class="ib-pane">
      @if($sel)
        <h3>{{ $sel->subject ?: ($sel->name ?: 'Message') }}</h3>
        <div class="ib-from">
          @if($sel->kind === 'alert')
            {{ $sel->ref_id }} · {{ $sel->created_at->format('M j, g:i A') }}
            @if($sel->tenant) · {{ $sel->tenant->name }} @endif
          @else
            {{ $sel->name }}
            @if($sel->email) · <a href="mailto:{{ $sel->email }}" style="color:inherit">{{ $sel->email }}</a>@endif
            @if($sel->phone) · {{ $sel->phone }}@endif
            @if($sel->company) · {{ $sel->company }}@endif
            @if($sel->tenant) · <b>{{ $sel->tenant->name }}</b>@endif
            <br>{{ $sel->created_at->format('D M j, g:i A') }}
            @if($sel->source_url) · from {{ str_replace(['https://','http://'], '', $sel->source_url) }}@endif
            @if($sel->ip) · {{ $sel->ip }}@endif
          @endif
        </div>

        <div class="ib-body">{{ $sel->body }}</div>

        @if($sel->replied_at)
          <div class="ib-hint">You replied {{ $sel->replied_at->diffForHumans() }}:</div>
          <div class="ib-replied">{{ $sel->reply_body }}</div>
        @endif

        <div class="ib-acts" style="margin-top:14px">
          @if($sel->kind === 'alert')
            @if($sel->status !== 'archived')
              <button type="button" class="ib-btn pri" wire:click="dismiss('{{ $sel->id }}')">Dismiss</button>
            @else
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','new')">Reopen</button>
            @endif
            @if($sel->ref_id)
              <a class="ib-btn" href="{{ url('/admin/debug-logs?activeTab=errors') }}">Open Issues</a>
            @endif
          @else
            @if($sel->status !== 'archived')
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','archived')">Archive</button>
            @else
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','new')">Reopen</button>
            @endif
            @if($sel->status !== 'spam')
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','spam')">Spam</button>
            @else
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','new')">Not spam</button>
            @endif
            @if($sel->status !== 'new')
              <button type="button" class="ib-btn" wire:click="setStatus('{{ $sel->id }}','new')">Mark unread</button>
            @endif
          @endif
        </div>

        @if($sel->isMessage() && $sel->status !== 'spam')
          <div class="ib-reply" style="margin-top:16px">
            <textarea wire:model="reply" placeholder="Write a reply…"></textarea>
            <div class="ib-hint">
              @if($sel->tenant_id)
                Lands on every staff member's bell at <b>{{ $sel->tenant?->name }}</b> and emails their owner.
              @elseif($sel->email)
                Emails <b>{{ $sel->email }}</b> from Intake{{ $notifyAddress ? ', replies to ' . $notifyAddress : '' }}.
              @else
                No tenant and no email on this message — nothing to reply to.
              @endif
            </div>
            @if($sel->tenant_id || $sel->email)
              <div class="ib-acts" style="margin-top:8px">
                <button type="button" class="ib-btn pri" wire:click="sendReply">Send reply</button>
              </div>
            @endif
          </div>
        @endif
      @else
        <div class="ib-empty">Pick something on the left.</div>
      @endif
    </div>
  </div>
</div>
</x-filament-panels::page>
