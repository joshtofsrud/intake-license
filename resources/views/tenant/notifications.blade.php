@extends('layouts.tenant.app')
@php $pageTitle = 'Alerts'; @endphp

{{-- MARKER-PATCH-273 — Layer A: alerts inbox rebuilt to the staff-alerts mock. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Alerts</h1>
    <p class="ia-page-subtitle">Everything that's needed your attention.</p>
  </div>
  @php $canBroadcast = tenant()->staff_alerts_enabled && optional(auth('tenant')->user())->isManager(); @endphp
  {{-- MARKER-PATCH-280 — compose entry point --}}
  <div style="display:flex;gap:8px;align-items:center">
    @if($canBroadcast)
      <button type="button" class="ia-btn ia-btn--primary" onclick="document.getElementById('bc-overlay').classList.add('open')">📣 New announcement</button>
    @endif
    <button type="button" class="ia-btn" id="sa-ack-all">Acknowledge all</button>
    @if(tenant()->staff_alerts_enabled)
      <a href="{{ route('tenant.alerts.prefs') }}" class="ia-btn">Settings</a>
    @endif
  </div>
</div>

@php
  $eventMeta = [
    'booking.created'         => ['Booking', '📅'],
    'rental.overdue'          => ['Rental', '📦'],
    'rental.damage_flagged'   => ['Rental', '📦'],
    'rental.reserved_online'  => ['Rental', '📦'],
    'lease.created'           => ['Lease', '📄'],
    'payment.failed'          => ['Payment', '💳'],
    'payment.link_completed'  => ['Payment', '💳'],
    'payment.link_expired'    => ['Payment', '💳'],
    'payment.refund_external' => ['Payment', '💳'],
    'offer.accepted'          => ['Offer', '🏷'],
    'inbox.needs_reply'       => ['Inbox', '💬'],
  ];
@endphp

@if($alerts->isEmpty())
  <div class="ia-card" style="padding:40px;text-align:center">
    <p style="font-size:14px;opacity:.6">You're all caught up — nothing here yet.</p>
  </div>
@else
  <div class="sa-filter-bar">
    <span class="sa-fl-label">Show</span>
    <button type="button" class="sa-chip active" data-filter="all">All</button>
    <button type="button" class="sa-chip" data-filter="unread">Unread</button>
    <button type="button" class="sa-chip" data-filter="priority">Priority</button>
  </div>

  <div class="sa-alerts" id="sa-alerts">
    @foreach($alerts as $a)
      @php
        [$evLabel, $evIcon] = $eventMeta[$a->event] ?? ['Alert', '🔔'];
        $isRead = (bool) $a->read_at;
      @endphp
      <div class="sa-alert {{ $isRead ? 'read' : 'unread' }}{{ $a->is_critical ? ' high' : '' }}"
           data-id="{{ $a->id }}" data-read="{{ $isRead ? '1' : '0' }}" data-priority="{{ $a->is_critical ? '1' : '0' }}">
        <div class="sa-src-icon system">{{ $evIcon }}</div>
        <div class="sa-content">
          <div class="sa-title">{{ $a->title }}</div>
          @if($a->body)<div class="sa-body">{{ $a->body }}</div>@endif
          <div class="sa-meta">
            <span class="sa-pip"><span class="ic">{{ $evIcon }}</span> {{ $evLabel }}</span>
            @if($a->is_critical)<span class="sa-priority-pill">Priority</span>@endif
            <span class="sa-channel ok">In-app ✓</span>
            @if($a->link)<a href="{{ $a->link }}" class="sa-pip" style="color:var(--ia-accent);text-decoration:none">Open →</a>@endif
          </div>
        </div>
        <div class="sa-right">
          <span class="sa-ts">{{ $a->created_at?->diffForHumans() }}</span>
          <button type="button" class="sa-ack" data-ack="{{ $a->id }}"{!! $isRead ? ' hidden' : '' !!}>Acknowledge</button>
        </div>
      </div>
    @endforeach
  </div>
  <div style="margin-top:16px">{{ $alerts->links() }}</div>
@endif

<style>
  .sa-filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;padding:10px 14px;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:10px}
  .sa-fl-label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-3);font-weight:700}
  .sa-chip{padding:5px 11px;border-radius:99px;background:rgba(255,255,255,.02);border:1px solid var(--ia-border);font-size:11.5px;color:var(--ia-text-3);font-weight:600;cursor:pointer;font-family:inherit}
  .sa-chip:hover{color:var(--ia-text);border-color:var(--ia-border-2)}
  .sa-chip.active{background:rgba(190,242,100,.1);color:var(--ia-accent);border-color:rgba(190,242,100,.3)}
  .sa-alerts{display:flex;flex-direction:column;background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:12px;overflow:hidden}
  .sa-alert{display:grid;grid-template-columns:36px 1fr auto;gap:14px;padding:16px 18px;border-bottom:1px solid var(--ia-border);align-items:flex-start;position:relative}
  .sa-alert:last-child{border-bottom:none}
  .sa-alert:hover{background:rgba(255,255,255,.015)}
  .sa-alert.high::before{content:'';position:absolute;left:0;top:14px;bottom:14px;width:2px;background:#F59E0B;border-radius:0 2px 2px 0}
  .sa-alert.unread .sa-title{color:var(--ia-text);font-weight:700}
  .sa-alert.read .sa-title{color:var(--ia-text-2);font-weight:600}
  .sa-alert.read .sa-body{color:#666}
  .sa-alert.read{opacity:.72}
  .sa-src-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
  .sa-src-icon.system{background:rgba(95,168,220,.1);color:#5fa8dc}
  .sa-src-icon.human{background:rgba(190,242,100,.1);color:var(--ia-accent)}
  .sa-src-icon.scheduled{background:rgba(245,158,11,.1);color:#F59E0B}
  .sa-content{min-width:0}
  .sa-title{font-size:14px;line-height:1.4;margin-bottom:4px}
  .sa-body{font-size:12.5px;color:var(--ia-text-2);line-height:1.5;margin-bottom:8px}
  .sa-meta{display:flex;align-items:center;gap:12px;font-size:11px;color:var(--ia-text-3);flex-wrap:wrap}
  .sa-pip{display:inline-flex;align-items:center;gap:4px}
  .sa-pip .ic{font-size:11px;opacity:.8}
  .sa-priority-pill{background:rgba(245,158,11,.12);color:#F59E0B;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
  .sa-channel{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(255,255,255,.03);color:var(--ia-text-3)}
  .sa-channel.ok{color:#4ade80}
  .sa-right{display:flex;flex-direction:column;gap:6px;align-items:flex-end;flex-shrink:0}
  .sa-ts{font-size:11px;color:var(--ia-text-3);white-space:nowrap}
  .sa-ack{background:transparent;border:1px solid var(--ia-border);color:var(--ia-text-3);padding:4px 10px;border-radius:5px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit}
  .sa-ack:hover{color:var(--ia-accent);border-color:rgba(190,242,100,.3)}
</style>

<script>
(function(){
  var csrf    = '{{ csrf_token() }}';
  var readTpl = '{{ route('tenant.alerts.read', ['id' => 'ID']) }}';
  var readAll = '{{ route('tenant.alerts.read-all') }}';
  var wrap    = document.getElementById('sa-alerts');

  document.querySelectorAll('.sa-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      document.querySelectorAll('.sa-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      var f = chip.getAttribute('data-filter');
      document.querySelectorAll('.sa-alert').forEach(function(row){
        var show = f === 'all'
          || (f === 'unread'   && row.getAttribute('data-read') === '0')
          || (f === 'priority' && row.getAttribute('data-priority') === '1');
        row.style.display = show ? '' : 'none';
      });
    });
  });

  function markRowRead(row){
    if (!row) return;
    row.setAttribute('data-read', '1');
    row.classList.remove('unread'); row.classList.add('read');
    var b = row.querySelector('.sa-ack'); if (b) b.setAttribute('hidden', '');
  }
  function ackOne(row){
    if (!row || row.getAttribute('data-read') === '1') return;
    markRowRead(row);
    fetch(readTpl.replace('ID', row.getAttribute('data-id')),
      { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).catch(function(){});
  }

  if (wrap){
    wrap.addEventListener('click', function(e){
      var b = e.target.closest('[data-ack]');
      if (b){ e.preventDefault(); ackOne(b.closest('.sa-alert')); }
    });
  }
  var allBtn = document.getElementById('sa-ack-all');
  if (allBtn){
    allBtn.addEventListener('click', function(){
      document.querySelectorAll('.sa-alert').forEach(markRowRead);
      fetch(readAll, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).catch(function(){});
    });
  }
})();
</script>

@if(!empty($canBroadcast) && $canBroadcast)
{{-- MARKER-PATCH-280 — shop-wide announcement compose modal --}}
<div id="bc-overlay" class="bc-overlay" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="bc-modal">
    <div class="bc-head">
      <div class="bc-title">📣 Shop-wide announcement</div>
      <button type="button" class="bc-x" onclick="document.getElementById('bc-overlay').classList.remove('open')">&times;</button>
    </div>
    <form method="POST" action="{{ route('tenant.alerts.broadcasts.store') }}">
      @csrf
      <label class="bc-l">Title</label>
      <input name="title" maxlength="160" required class="bc-in" placeholder="e.g. Closing early Friday for inventory">
      <label class="bc-l">Message</label>
      <textarea name="body" maxlength="2000" rows="3" class="bc-in" placeholder="Add details for your staff…"></textarea>
      <label class="bc-l">Priority</label>
      <div class="bc-row">
        <label class="bc-opt"><input type="radio" name="priority" value="low" checked> Low · quiet</label>
        <label class="bc-opt"><input type="radio" name="priority" value="high"> High · banner + sound</label>
      </div>
      <label class="bc-l">Channels</label>
      <div class="bc-row">
        <label class="bc-opt"><input type="checkbox" name="show_banner" value="1" checked> Banner</label>
        <label class="bc-opt"><input type="checkbox" name="send_email" value="1"> Email</label>
      </div>
      <div class="bc-aud">To: <strong>All staff</strong></div>
      <div class="bc-actions">
        <button type="button" class="ia-btn" onclick="document.getElementById('bc-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Send alert →</button>
      </div>
    </form>
  </div>
</div>
<style>
  .bc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:flex-start;justify-content:center;z-index:400;padding:8vh 16px}
  .bc-overlay.open{display:flex}
  .bc-modal{background:var(--ia-surface);border:1px solid var(--ia-border);border-radius:14px;width:100%;max-width:460px;padding:22px;box-shadow:0 24px 70px rgba(0,0,0,.5)}
  .bc-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
  .bc-title{font-size:15px;font-weight:700;color:var(--ia-text)}
  .bc-x{background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:var(--ia-text-3)}
  .bc-l{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--ia-text-3);font-weight:700;margin:14px 0 5px}
  .bc-in{width:100%;padding:9px 11px;border:1px solid var(--ia-border);border-radius:8px;background:rgba(255,255,255,.02);color:var(--ia-text);font:inherit;font-size:13.5px;box-sizing:border-box}
  .bc-in:focus{outline:none;border-color:rgba(190,242,100,.4)}
  .bc-row{display:flex;gap:16px;font-size:13px}
  .bc-opt{display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--ia-text-2)}
  .bc-aud{margin-top:14px;font-size:12.5px;color:var(--ia-text-3)}
  .bc-aud strong{color:var(--ia-text-2)}
  .bc-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
</style>
@endif

@endsection
