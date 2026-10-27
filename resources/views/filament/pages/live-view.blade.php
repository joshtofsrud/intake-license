<x-filament-panels::page>
@include('filament.partials.dashboards-style')
<link rel="stylesheet" href="{{ asset('js/vendor/rrweb-2.1.7.min.css') }}">
<style>
  .lv-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; align-items:end; max-width:760px; }
  .lv-grid label { display:block; font-size:12px; color:var(--sx-dim); margin-bottom:4px; }
  .lv-grid select { width:100%; }
  .lv-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--sx-lime); margin-right:6px; vertical-align:1px; }
  .lv-state { display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 0; }
  .lv-state .lbl { font-weight:600; color:var(--sx-text, inherit); }
  .lv-stage { position:relative; border:1px solid var(--sx-line); border-radius:8px; overflow:hidden; background:var(--sx-line); min-height:220px; }
  .lv-stage .lv-wait { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; text-align:center; padding:20px; color:var(--sx-faint); font-size:13px; }
  .lv-root { transform-origin:0 0; }
  .lv-root .replayer-wrapper { transform-origin:0 0; }
  .lv-root iframe { border:0; background:#fff; pointer-events:none; }
</style>

<div class="sx-root">
  <div class="db-legend">
    <b>Live view</b> shows one Intake tab at a shop as they see it, about a quarter to half a second behind. It's view only: you see the page and their pointer, and can't click or type for them.
    Pick a shop and either <b>anyone signed in</b> (the first person who answers) or one person. Their open Intake tabs check for a request every {{ \App\Services\LiveView::POLL }} seconds, so it can take that long to appear, and it lapses if nobody answers within {{ \App\Services\LiveView::ASK_FOR / 60 }} minutes.
    Nothing shows until they press <b>Allow</b>. While it runs they see a bar saying Intake support is viewing the tab, with a Stop button.
    It ends when either of you presses Stop or End, when their tab is closed (a minute with nothing from it), or after {{ \App\Services\LiveView::LASTS }} minutes.
    Passwords, PINs and card fields are masked in their browser before anything leaves it. Nothing on screen is saved; who asked, who allowed it and when it ended is kept in the list below and the audit log.
    <span class="lv-dot"></span>means that person has Intake open now.
  </div>

  @if ($sess)
    <div class="lv-state" wire:ignore>
      <span class="lbl" id="lv-label">{{ $state['label'] }}</span>
      <span class="db-sub" style="display:inline">{{ $sess->tenant?->name }} · asked of {{ $whom }} by {{ $sess->requested_by_name }} · {{ $sess->created_at?->diffForHumans() }}</span>
      <span id="lv-left" class="db-sub" style="display:inline"></span>
      <span style="margin-left:auto"></span>
      <button type="button" id="lv-end" class="db-btn" wire:click="stop" @if (! $sess->isOpen()) style="display:none" @endif
        data-confirm="End this live view?&#10;Their tab stops sending straight away and the bar goes from their screen.">End</button>
      <button type="button" id="lv-again" class="db-btn p" wire:click="again" @if ($sess->isOpen()) style="display:none" @endif>Ask again</button>
      <a class="db-btn" href="{{ \App\Filament\Pages\LiveView::getUrl(['shop' => $sess->tenant_id]) }}">New request</a>
    </div>

    <div class="lv-stage" id="lv-stage" wire:ignore
      data-stream="{{ $stream }}" data-open="{{ $sess->isOpen() ? 1 : 0 }}">
      <div class="lv-wait" id="lv-wait">{{ $sess->isOpen() ? 'Their screen shows here once they allow it.' : 'This live view has ended. Nothing from it was kept.' }}</div>
      <div class="lv-root" id="lv-root"></div>
    </div>
  @else
    <div class="lv-grid" style="padding:14px 0" wire:poll.10s>
      <div>
        <label for="lv-shop">Shop</label>
        <select id="lv-shop" class="db-in" wire:model.live="shop">
          <option value="">Choose a shop</option>
          @foreach ($shops as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
      </div>
      <div>
        <label for="lv-person">Ask</label>
        <select id="lv-person" class="db-in" wire:model="person" @disabled(! $tenant)>
          <option value="">Anyone signed in{{ $tenant ? ' (' . count($online) . ' open now)' : '' }}</option>
          @foreach ($people as $id => $name)
            <option value="{{ $id }}">{{ isset($online[$id]) ? '● ' : '' }}{{ $name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <button type="button" class="db-btn p" wire:click="ask" @disabled(! $tenant)>Request live view</button>
      </div>
    </div>
    @if ($tenant)
      <div class="db-note" style="padding-bottom:12px">
        @if ($online)
          Open now: @foreach ($online as $o)<span class="lv-dot"></span>{{ $o['name'] }}{{ ! $loop->last ? ', ' : '' }}@endforeach
        @else
          Nobody at {{ $tenant->name }} has Intake open right now, so a request would wait until someone does (up to {{ \App\Services\LiveView::ASK_FOR / 60 }} minutes).
        @endif
      </div>
    @endif
  @endif

  <h3 class="db-h" style="margin-top:22px">Recent</h3>
  <table class="db-t">
    <thead><tr><th>Shop</th><th class="db-hide-m">Asked</th><th>Allowed by</th><th class="db-hide-m">Started</th><th>How it went</th><th></th></tr></thead>
    <tbody>
      @forelse ($recent as $r)
        <tr>
          <td>{{ $r->tenant?->name ?? '(deleted shop)' }}</td>
          <td class="db-hide-m">{{ $r->requested_by_name }}<div class="db-sub">{{ $r->created_at?->format('M j, g:i a') }}</div></td>
          <td>{{ $r->status === 'declined' ? '' : ($r->accepted_by_name ?? '') }}</td>
          <td class="db-hide-m">{{ $r->accepted_at?->format('g:i a') }}</td>
          <td>
            @php $tag = ['requested' => 'pend', 'active' => 'live'][$r->status] ?? 'off'; @endphp
            <span class="db-tag {{ $tag }}">{{ ucfirst($r->status) }}</span>
            @if ($r->end_reason && $r->status !== 'declined')<div class="db-sub">{{ $r->end_reason }}@if ($r->accepted_at && $r->ended_at) · {{ max(1, (int) round($r->accepted_at->diffInMinutes($r->ended_at))) }} min @endif</div>@endif
          </td>
          <td class="r"><a class="db-lnk" href="{{ \App\Filament\Pages\LiveView::getUrl(['s' => $r->id]) }}">{{ $r->isOpen() ? 'Open' : 'Details' }}</a></td>
        </tr>
      @empty
        <tr><td colspan="6" class="db-note" style="padding:18px 10px">No live views yet.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

@if ($sess && $sess->isOpen())
<script src="{{ asset('js/vendor/rrweb-2.1.7.min.js') }}"></script>
<script>
(function () {
  var stage = document.getElementById('lv-stage');
  var root = document.getElementById('lv-root');
  var wait = document.getElementById('lv-wait');
  var label = document.getElementById('lv-label');
  var left = document.getElementById('lv-left');
  var r = null, size = null, ends = null, es;

  function fit() {
    if (!r || !size) return;
    var s = Math.min(1, stage.parentNode.clientWidth / size.width);
    r.wrapper.style.transform = 'scale(' + s + ')';
    stage.style.height = Math.ceil(size.height * s) + 'px';
    stage.style.width = Math.ceil(size.width * s) + 'px';
    stage.style.maxWidth = '100%';
  }
  window.addEventListener('resize', fit);

  function play(events) {
    // Play on arrival: their clock and ours needn't agree, and a backlog shouldn't replay at recorded speed.
    var now = Date.now();
    events.forEach(function (e) { e.timestamp = now; });
    if (!r) {
      r = new rrweb.Replayer([], { root: root, liveMode: true, mouseTail: false, showWarning: false,
        // their "being viewed" bar isn't recorded; hide the empty space it leaves
        insertStyleRules: ['[data-lv-self]{display:none!important}'] });
      r.on('resize', function (d) { size = d; fit(); });
      r.startLive(now);
      wait.style.display = 'none';
    }
    events.forEach(function (e) { r.addEvent(e); });
  }

  function tick() {
    if (ends) {
      var sec = Math.max(0, ends - Math.floor(Date.now() / 1000));
      left.textContent = '· ends in ' + Math.ceil(sec / 60) + ' min';
    }
  }
  setInterval(tick, 15000);

  es = new EventSource(stage.dataset.stream);
  es.onmessage = function (m) { try { play(JSON.parse(m.data)); } catch (e) { console.warn('live view', e); } };
  es.addEventListener('status', function (m) {
    var d = JSON.parse(m.data);
    label.textContent = d.label;
    ends = d.ends; tick();
    if (d.status !== 'requested' && d.status !== 'active') {
      es.close();
      left.textContent = '';
      document.getElementById('lv-end').style.display = 'none';
      document.getElementById('lv-again').style.display = '';
      wait.textContent = 'This live view has ended. Nothing from it was kept.';
      wait.style.display = '';
      if (r) { try { r.destroy(); } catch (e) {} r = null; root.innerHTML = ''; stage.style.height = ''; stage.style.width = ''; }
    }
  });
})();
</script>
@endif
</x-filament-panels::page>
