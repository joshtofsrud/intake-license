{{-- MARKER-CONNECTED-POS-SECTION: Items — the catalog read, and the way into Inventory › Import --}}
@php
  $pc = $pos['conn'];
  $it = $pos['items'];
  $ss = (array) ($pc->sync_state ?? []);
  $im = (array) ($pc->import_state ?? []);
  $reading = (bool) ($ss['running'] ?? false);
  $readWaiting = $reading && (int) ($ss['pages'] ?? 0) === 0;
  $switching = $reading && ! empty($ss['switch_after']);
  $how = ['barcode' => 'by barcode', 'part' => 'by part number', 'sku' => 'by SKU · check'];
@endphp
<div class="av-sum">
  <div>In your Connected POS<b data-live="items.total">{{ number_format($it['total']) }}</b></div>
  <div>Linked to your items<b data-live="items.linked">{{ number_format($it['linked']) }}</b></div>
  <div>Waiting in Import<b data-live="items.waiting">{{ number_format($it['waiting']) }}</b></div>
  <div>New since your last import<b data-live="items.new">{{ number_format($it['new']) }}</b></div>
  <div class="w">Removed there<b data-live="items.removed">{{ number_format($it['removed']) }}</b></div>
</div>

<div class="ec-sec">
  <h3>Catalog</h3>
  <div class="ec-row">
    <div>
      <div class="t">
        @if($readWaiting) Waiting for a worker to start… @elseif($switching) Switching over: reading one last time… @elseif($reading) Reading your catalog… @elseif($ss['stopped'] ?? false) Stopped — automatic reading is paused @elseif(! empty($ss['error'])) The last read stopped with an error @elseif($pc->last_synced_at) Read {{ $pc->last_synced_at->diffForHumans() }} @else Not read yet @endif
        <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Reads every product, links the ones that are plainly items you already carry, and adds the rest to the Import list. Nothing is created. @if($pc->sync_enabled) New and changed products are picked up about every minute, with a full read each night. @else Automatic reading is off; turn it on under Settings, or read when you need to. @endif</span>
      </div>
      @if(! empty($ss['error']))<div class="d" style="color:#F26D6D">{{ $ss['error'] }}</div>@endif
      @if($reading && ! $readWaiting)<div class="d">Step: {{ ['stock' => 'reading stock counts', 'link' => 'linking the ones you already carry'][$ss['phase'] ?? ''] ?? 'reading products' }} · page <span data-live="state.pages">{{ $ss['pages'] ?? 0 }}</span></div>@endif
    </div>
    <div class="ctl">
      {{-- MARKER-POS-STOP-RESUME --}}
      @if($reading)
        <form method="POST" action="{{ route('tenant.cpos.read_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form>
      @else
        @if($ss['stopped'] ?? false)
          <form method="POST" action="{{ route('tenant.cpos.read_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume from page {{ number_format($ss['pages'] ?? 0) }}</button></form>
        @endif
        <form method="POST" action="{{ route('tenant.cpos.read') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" @disabled($pc->status !== 'connected')>{{ $pc->last_synced_at ? 'Read everything again' : 'Read now' }}</button></form>
      @endif
    </div>
  </div>
  @if($reading || ! empty($ss['finished_at']))
    <div class="d" style="font-size:12px;color:var(--ia-text-muted);margin:8px 0 2px">{{ $reading ? 'This read' : 'The last read' }} <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Linked and Waiting count the products this read met without a link; ones already linked aren't counted again. Prices and stock change only on linked items, and only in 2 Way Data with reading on.</span></div>
    <div class="av-sum">
      <div>Read<b data-live="state.items_seen">{{ number_format($ss['items_seen'] ?? 0) }}</b></div>
      <div>Linked<b data-live="state.matched">{{ number_format($ss['matched'] ?? 0) }}</b></div>
      <div>Waiting<b data-live="state.waiting">{{ number_format($ss['waiting'] ?? 0) }}</b></div>
      <div>Prices<b data-live="state.price_updates">{{ number_format($ss['price_updates'] ?? 0) }}</b></div>
      <div>Stock changes<b data-live="state.stock_changes">{{ number_format($ss['stock_changes'] ?? 0) }}</b></div>
      <div class="w">Removed<b data-live="state.removed">{{ number_format($ss['removed'] ?? 0) }}</b></div>
      <div class="w">Need review<b data-live="state.conflicts">{{ number_format($ss['conflicts'] ?? 0) }}</b></div>
    </div>
  @endif

  <div class="ec-row">
    <div><div class="t">Add items <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Search, filter, preview and add from your Connected POS with the same tools as your distributors: one product, the ones you tick, or everything a filter matches.</span></div>
      @if($im['running'] ?? false)
        <div class="cpx-prog"><i data-live-bar="import" style="width:{{ ($im['total'] ?? 0) ? round(100 * ($im['done'] ?? 0) / $im['total']) : 0 }}%"></i></div>
        <div class="d">Importing <span data-live="import.done">{{ number_format($im['done'] ?? 0) }}</span> of {{ number_format($im['total'] ?? 0) }} — Stop and the details are in Import.</div>
      @elseif($im['stopped'] ?? false)
        <div class="d">An import is stopped at {{ number_format($im['done'] ?? 0) }} of {{ number_format($im['total'] ?? 0) }}. Resume it in Import.</div>
      @endif
    </div>
    <div class="ctl"><a class="ia-btn ia-btn--primary ia-btn--sm" href="{{ route('tenant.inventory.cpos') }}">Open in Inventory › Import</a></div>
  </div>
</div>

<div class="ec-sec cpx-wide" id="cpNew">
  <h3>New since your last import @if($it['new'])<span class="cpx-pip">{{ number_format($it['new']) }}</span>@endif</h3>
  @if($new->count())
    <table class="ip-tbl">
      <thead><tr><th>Connected POS item</th><th>What Import does</th><th class="r">Price</th><th class="r">Stock there</th><th></th></tr></thead>
      <tbody>
        @foreach($new as $p)
          <tr data-row="{{ $p->id }}">
            <td><div>{{ $p->name ?: $p->sku ?: 'Unnamed product' }}</div>
              <div class="m">{{ collect([$p->brand, $p->sku ? 'SKU ' . $p->sku : null, $p->mpn ? 'MPN ' . $p->mpn : null, $p->upc ?: $p->ean])->filter()->implode(' · ') }}</div></td>
            <td class="ip-out" data-out>
              @if($p->twin) In your Connected POS more than once <span class="ip-tag part">you choose</span>
              @elseif($p->hit) Links to <a href="{{ route('tenant.inventory.show', $p->hit['id']) }}">{{ $p->hit['name'] ?: 'your item' }}</a><span class="ip-tag {{ $p->hit['how'] === 'sku' ? 'part' : '' }}">{{ $how[$p->hit['how']] ?? '' }}</span>
              @else New item @if(! $p->track_stock)<span class="ip-tag">non-stock</span>@endif @endif
            </td>
            <td class="r">{{ $p->price_cents !== null ? '$' . number_format($p->price_cents / 100, 2) : '—' }}</td>
            <td class="r">{{ $p->stock === null ? '—' : number_format($p->stock) }}</td>
            <td class="r" data-btn>
              @if($p->twin)
                <a class="im-btn" href="{{ route('tenant.inventory.cpos', ['new' => 1]) }}" style="text-decoration:none">Choose in Import</a>
              @else
                <button type="button" class="im-btn" data-one="{{ $p->id }}">{{ $p->hit ? 'Link' : 'Import' }}</button>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <div class="ip-pager">
      <span>Showing {{ number_format($new->count()) }} of {{ number_format($it['new']) }}</span>
      <a class="cpx-lnk" href="{{ route('tenant.inventory.cpos', ['new' => 1]) }}">See all in Import →</a>
      <form method="POST" action="{{ route('tenant.cpos.mark_seen') }}">@csrf<button class="im-btn" type="submit" data-confirm="Mark these {{ number_format($it['new']) }} as seen? They stop showing as new. They stay in Inventory › Import, waiting, until you add them.">Mark as seen</button></form>
    </div>
  @else
    <p class="ec-note">Nothing new. When a product is added in your Connected POS it shows here within a few minutes, and waits in <a class="cpx-lnk" href="{{ route('tenant.inventory.cpos') }}">Inventory › Import</a> until you add it.</p>
  @endif
</div>

<script>
/* MARKER-CONNECTED-POS-SECTION: bring in one new product from this list (same call the Import page makes) */
(function(){
  var box = document.getElementById('cpNew'); if (!box) { return; }
  var url = @json(route('tenant.inventory.cpos.one')), csrf = @json(csrf_token());
  box.addEventListener('click', function(e){
    var b = e.target.closest('[data-one]'); if (!b) { return; }
    var tr = b.closest('tr'), out = tr.querySelector('[data-out]'), was = b.textContent;
    b.disabled = true; b.textContent = 'Working…';
    fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({id: parseInt(b.dataset.one, 10)})})
      .then(function(r){ return r.json().then(function(j){ return {ok: r.ok, j: j}; }); })
      .then(function(res){
        if (!res.ok || !res.j.ok) { throw new Error((res.j && res.j.error) || 'That product could not be brought in.'); }
        out.textContent = res.j.did === 'created' ? 'Imported as a new item' : 'Linked';
        tr.querySelector('[data-btn]').innerHTML = res.j.url ? '<a class="im-btn" href="' + res.j.url + '">Open</a>' : '';
      })
      .catch(function(err){
        b.disabled = false; b.textContent = was;
        out.innerHTML = ''; var s = document.createElement('span'); s.style.color = '#F26D6D'; s.textContent = err.message; out.appendChild(s);
      });
  });
})();
</script>
@include('tenant.connected-pos._live')
