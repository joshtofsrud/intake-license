{{-- MARKER-POS-TAB-V2: E-commerce → Connected POS. The shop's own outside POS (not the Intake Register).
     Order on the page: what this is (collapsible) · the connection · bringing in · sending out · needs review · sending activity. --}}
@php
  $pc = $pos['conn'];
  $ss = (array) ($pc->sync_state ?? []);
  $os = (array) ($pc->order_setup ?? []);
  $opt = (array) ($pc->setup_options ?? []);
  $cnt = $pos['counts'] ?? collect();
  $waiting = (int) ($cnt['pending'] ?? 0);
  $importWaiting = ($ss['running'] ?? false) && (int) ($ss['pages'] ?? 0) === 0;
@endphp
<style>
.cp-legend{max-width:760px;margin-bottom:18px;font-size:12.5px;color:var(--ia-text-muted);line-height:1.6;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 14px}
.cp-legend summary{cursor:pointer;color:var(--ia-text);font-weight:500;font-size:13px;list-style:none}
.cp-legend summary::-webkit-details-marker{display:none}
.cp-legend summary:after{content:" ▾";color:var(--ia-text-muted)}
.cp-legend[open] summary:after{content:" ▴"}
.cp-legend ul{margin:8px 0 0 16px;padding:0}
.cp-legend b{color:var(--ia-text);font-weight:500}
.cp-chips{display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:var(--ia-text-muted)}
.cp-tbl{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:6px}
.cp-tbl td{padding:6px 8px 6px 0;border-bottom:.5px solid var(--ia-border);vertical-align:top}
.cp-tbl td.what{max-width:420px}
.cp-tbl td.err{color:#A32D2D;max-width:340px}
.cp-tbl td.when{white-space:nowrap;color:var(--ia-text-muted)}
.cp-tbl .ia-btn{padding:2px 9px;height:auto}
.cp-live{display:flex;align-items:center;gap:8px;font-size:13px;margin:4px 0 10px}
.cp-dot{width:8px;height:8px;border-radius:50%;background:#7aa82a;animation:cpp 1s infinite}
@keyframes cpp{0%,100%{opacity:1}50%{opacity:.25}}
.cp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
</style>

<details class="cp-legend">
  <summary>What Connected POS does</summary>
  <ul>
    <li><b>Connected POS</b> is the outside POS your shop already uses — not the Intake Register.</li>
    <li><b>Comes in from your Connected POS</b> (with sync on): products, prices and stock, about every minute, plus a full re-read nightly. Stock lands only at the Intake locations you map. Its price replaces the Intake sell price.</li>
    <li><b>Goes out to your Connected POS</b> (only what you turn on under Sending): paid online orders as completed sales, their refunds, items an order needs that it doesn't have, items you choose to send from Inventory, and — if you choose — your edits to names, SKUs, barcodes and descriptions. Price is never sent except for a brand-new item.</li>
    <li>When both sides changed the same field, nothing is overwritten; it waits under <b>Needs review</b>.</li>
    <li>Items new to Intake start <b>off</b> the online store — choose what to sell under Availability.</li>
    <li><b>Customers</b> (with Bring in customers on): matched to yours by email, then phone. A match only has its blank fields filled; nobody is opted in to marketing.</li>
    <li><b>Not yet:</b> purchase history.</li>
  </ul>
</details>

@if($pc)
  {{-- the connection --}}
  <div class="ec-sec">
    <h3>{{ $pos['label'] }}</h3>
    <div class="ec-row">
      <div>
        <div class="t">{{ $pc->external_account_name ?: 'POS account' }} @if($pc->status === 'connected')<span class="src-badge src-shop" style="margin-left:6px">Connected</span>@else<span class="src-badge src-out" style="margin-left:6px">Needs attention</span>@endif</div>
        <div class="d">Connected {{ $pc->connected_at?->diffForHumans() }}@if($pc->connectedByUser) by {{ $pc->connectedByUser->name }}@endif · last checked {{ $pc->last_checked_at ? $pc->last_checked_at->diffForHumans() : 'never' }}</div>
        @if($pc->last_error)<div class="d" style="color:#A32D2D">{{ $pc->last_error }}</div>@endif
        <div class="cp-chips">
          <span>Outlets: @forelse($pc->locations ?? [] as $l)<span class="dw-chip">{{ $l['name'] }}</span> @empty none found @endforelse</span>
          <span>Payment types: @forelse($pc->payment_types ?? [] as $t)<span class="dw-chip">{{ $t['name'] }}</span> @empty none found @endforelse</span>
        </div>
      </div>
      <div class="ctl">
        <form method="POST" action="{{ route('tenant.storefront.pos.check') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Check connection</button></form>
        @if($pc->status !== 'connected')
          <form method="POST" action="{{ route('tenant.storefront.pos.connect', $pc->adapter_code) }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Reconnect</button></form>
        @endif
        <form method="POST" action="{{ route('tenant.storefront.pos.disconnect') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Disconnect your Connected POS? Intake's access is deleted and sync stops. Items already brought in stay. You can connect again any time.">Disconnect</button></form>
      </div>
    </div>
  </div>

  {{-- coming in --}}
  <form method="POST" action="{{ route('tenant.storefront.pos.settings') }}" class="ec-sec">
    @csrf
    <h3>Bringing in from your Connected POS</h3>
    <div class="ec-row">
      <div><div class="t">Bring in products, prices and stock</div><div class="d">About every minute, and a full re-read each night.</div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="sync_enabled" value="0"><input type="checkbox" name="sync_enabled" value="1" @checked($pc->sync_enabled)><i></i></label></div>
    </div>
    @foreach($pc->locations ?? [] as $l)
      <div class="ec-row">
        <div><div class="t">{{ $l['name'] }}</div><div class="d">Which Intake location this Connected POS outlet's stock goes to.</div></div>
        <div class="ctl"><select class="ia-select" name="map[{{ $l['id'] }}]">
          <option value="">Don't bring in this outlet</option>
          @foreach($pos['intake_locations'] as $il)
            <option value="{{ $il->id }}" @selected((($pc->location_map ?? [])[$l['id']] ?? null) === (string) $il->id)>{{ $il->name }}</option>
          @endforeach
        </select></div>
      </div>
    @endforeach
    <div class="ec-row">
      <div><div class="t">When an item is removed from your Connected POS</div><div class="d">Nothing is deleted. Its Connected POS stock goes to zero either way.</div></div>
      <div class="ctl"><select class="ia-select" name="removal_mode">
        <option value="keep" @selected(($pc->removal_mode ?? 'keep') === 'keep')>Keep selling it from suppliers</option>
        <option value="hide" @selected($pc->removal_mode === 'hide')>Hide it from the online store</option>
        <option value="deactivate" @selected($pc->removal_mode === 'deactivate')>Deactivate it in Intake</option>
      </select></div>
    </div>
    <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save</button></div>
  </form>

  {{-- import status, live --}}
  <div class="ec-sec" id="posSync" data-status="{{ route('tenant.storefront.pos.status') }}" data-running="{{ ($ss['running'] ?? false) ? 1 : 0 }}">
    <h3>Import</h3>
    <div class="ec-row">
      <div>
        <div class="t" id="psHead">
          @if($importWaiting) Waiting for a worker to start… @elseif($ss['running'] ?? false) Importing… @elseif($ss['stopped'] ?? false) Stopped — automatic sync is paused @elseif(!empty($ss['error'])) Last run stopped with an error @elseif($pc->last_synced_at) Up to date @else Not imported yet @endif
        </div>
        <div class="d" id="psLine">{{ number_format($pos['linked']) }} items linked to your Connected POS.@if($pc->last_synced_at) Last run finished {{ $pc->last_synced_at->diffForHumans() }}.@endif @if(!$pc->sync_enabled) Turn on Bring in products and save to import.@endif</div>
        <div class="d" id="psErr" style="color:#A32D2D">{{ $ss['error'] ?? '' }}</div>
      </div>
      <div class="ctl">
        {{-- MARKER-POS-STOP-RESUME --}}
        @if($ss['running'] ?? false)
          <form method="POST" action="{{ route('tenant.storefront.pos.import_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form>
        @elseif($ss['stopped'] ?? false)
          <form method="POST" action="{{ route('tenant.storefront.pos.import_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume from page {{ number_format($ss['pages'] ?? 0) }}</button></form>
        @endif
        @unless($ss['running'] ?? false)
          <form method="POST" action="{{ route('tenant.storefront.pos.import') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" @disabled(!$pc->sync_enabled)>{{ $pc->last_synced_at ? 'Re-read everything' : 'Start import' }}</button></form>
        @endunless
      </div>
    </div>
    <div class="d" style="margin:6px 0 2px">This run:</div>
    <div class="av-sum" id="psNums">
      <div>Read<b data-k="items_seen">{{ number_format($ss['items_seen'] ?? 0) }}</b></div>
      <div>Matched<b data-k="matched">{{ number_format($ss['matched'] ?? 0) }}</b></div>
      <div>New<b data-k="created">{{ number_format($ss['created'] ?? 0) }}</b></div>
      <div>Prices<b data-k="price_updates">{{ number_format($ss['price_updates'] ?? 0) }}</b></div>
      <div>Stock changes<b data-k="stock_changes">{{ number_format($ss['stock_changes'] ?? 0) }}</b></div>
      <div class="w">Removed<b data-k="removed">{{ number_format($ss['removed'] ?? 0) }}</b></div>
      <div class="w">Need review<b data-k="conflicts">{{ number_format($ss['conflicts'] ?? 0) }}</b></div>
    </div>
    <p class="ec-note" id="psPhase">@if(($ss['running'] ?? false) && !$importWaiting) Step: {{ ($ss['phase'] ?? '') === 'stock' ? 'reading stock counts' : 'reading products' }} · page {{ $ss['pages'] ?? 0 }} @endif</p>
  </div>

  {{-- MARKER-POS-CUSTOMERS-IN: customers coming in, with live progress and Stop/Resume --}}
  @php $cs = (array) ($pc->customer_sync ?? []); $csRunning = (bool) ($cs['running'] ?? false); @endphp
  <div class="ec-sec" id="posCust" data-running="{{ $csRunning ? 1 : 0 }}">
    <h3>Customers from your Connected POS</h3>
    <form method="POST" action="{{ route('tenant.storefront.pos.customers') }}" class="ec-row">
      @csrf
      <div><div class="t">Bring in customers</div><div class="d">The first time, every customer is read; after that, new and changed ones come in about every minute. Each is matched to an Intake customer by email, then phone, and only blank fields are filled. No one is opted in to marketing, and "do not email" there means opted out here.</div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="customers_in" value="0"><input type="checkbox" name="customers_in" value="1" @checked($pc->customers_in) onchange="this.form.submit()"><i></i></label></div>
    </form>
    @if($pc->customers_in || $cs)
      <div class="ec-row">
        <div>
          <div class="t" id="pcHead">@if($csRunning) Bringing in customers… @elseif($cs['stopped'] ?? false) Stopped @elseif(! empty($cs['error'])) Last run stopped with an error @elseif(! empty($cs['last_done'])) Up to date @else Not started @endif</div>
          <div class="av-sum" style="margin-top:8px" id="pcNums">
            <div>Read<b data-k="seen">{{ number_format($cs['seen'] ?? 0) }}</b></div>
            <div>Matched<b data-k="matched">{{ number_format($cs['matched'] ?? 0) }}</b></div>
            <div>New<b data-k="created">{{ number_format($cs['created'] ?? 0) }}</b></div>
            <div>Filled in<b data-k="filled">{{ number_format($cs['filled'] ?? 0) }}</b></div>
            <div>Skipped<b data-k="skipped">{{ number_format($cs['skipped'] ?? 0) }}</b></div>
          </div>
          <div class="d" id="pcErr" style="color:#A32D2D">{{ $cs['error'] ?? '' }}</div>
          <div class="d">Skipped: deleted in your Connected POS, or no name, email or phone to know them by.</div>
        </div>
        <div class="ctl">
          @if($csRunning)
            <form method="POST" action="{{ route('tenant.storefront.pos.customers_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form>
          @elseif($cs['stopped'] ?? false)
            <form method="POST" action="{{ route('tenant.storefront.pos.customers_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume from page {{ number_format($cs['pages'] ?? 0) }}</button></form>
          @endif
          @if($pc->customers_in && ! $csRunning)
            <form method="POST" action="{{ route('tenant.storefront.pos.customers_import') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Read every customer again</button></form>
          @endif
        </div>
      </div>
    @endif
  </div>
  <script>
  /* MARKER-POS-CUSTOMERS-IN: live progress while customers come in */
  (function(){
    var box=document.getElementById('posCust'); if(!box || box.dataset.running!=='1') return;
    var url=@json(route('tenant.storefront.pos.status'));
    function tick(){
      fetch(url,{headers:{'Accept':'application/json','X-Intake-Background':'1'},credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(j){
          var s=j && j.customers; if(!s) return;
          box.querySelectorAll('#pcNums [data-k]').forEach(function(b){ b.textContent=(s[b.dataset.k]||0).toLocaleString(); });
          document.getElementById('pcErr').textContent=s.error||'';
          if(!s.running){ location.reload(); return; }
          setTimeout(tick,2000);
        }).catch(function(){ setTimeout(tick,4000); });
    }
    setTimeout(tick,1500);
  })();
  </script>

  {{-- going out --}}
  <form method="POST" action="{{ route('tenant.storefront.pos.push_settings') }}" class="ec-sec">
    @csrf
    <h3>Sending to your Connected POS</h3>
    <div class="ec-row">
      <div><div class="t">Send paid online orders as sales</div><div class="d">Recorded as completed sales at the outlet mapped to the order's location, with their refunds.</div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="orders_out" value="0"><input type="checkbox" name="orders_out" value="1" @checked($pc->orders_out)><i></i></label></div>
    </div>
    @php $setupMissing = !count($opt['registers'] ?? []) || !count($opt['users'] ?? []); @endphp
    @if($setupMissing)
      <p class="ec-note" style="color:#854F0B;margin:4px 0 8px">Your Connected POS hasn't shared its registers and staff with Intake yet. Click <b>Disconnect</b>, then <b>Connect</b> again to give Intake that permission, then <b>Check connection</b>.</p>
    @endif
    <div class="ec-sub">
      @foreach([['register_id', 'Register', $opt['registers'] ?? []], ['user_id', 'Recorded by', $opt['users'] ?? []], ['payment_type_id', 'Payment type', $pc->payment_types ?? []], ['tax_id', 'Tax', $opt['taxes'] ?? []]] as [$fld, $lbl, $list])
        @if($fld !== 'tax_id' || count($list))
          <div class="ec-row">
            <div><div class="t">{{ $lbl }}</div>@if($fld === 'payment_type_id')<div class="d">The one you made for web sales (for example "Intake Online"), so online money stays separate from the till.</div>@endif</div>
            <div class="ctl">
              <select class="ia-select" name="{{ $fld }}" @disabled(!count($list))>
                <option value="">{{ count($list) ? 'Choose…' : 'None from your Connected POS yet' }}</option>
                @foreach($list as $o)<option value="{{ $o['id'] }}" @selected(($os[$fld] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>@endforeach
              </select>
            </div>
          </div>
        @endif
      @endforeach
      {{-- MARKER-POS-REGISTER-PER-OUTLET: with more than one mapped outlet, each takes its own register --}}
      @php
        $mappedOutlets = collect($pc->locations ?? [])->filter(fn ($l) => isset(((array) $pc->location_map)[$l['id']]));
        $regsKnowOutlet = collect($opt['registers'] ?? [])->contains(fn ($r) => ! empty($r['outlet_id']));
      @endphp
      @if($mappedOutlets->count() > 1)
        @foreach($mappedOutlets as $mo)
          @php $moRegs = collect($opt['registers'] ?? [])->filter(fn ($r) => ! $regsKnowOutlet || (string) ($r['outlet_id'] ?? '') === (string) $mo['id']); @endphp
          <div class="ec-row">
            <div><div class="t">Register at {{ $mo['name'] }}</div><div class="d">Online orders for this outlet are recorded on it. Left on Automatic, Intake uses the register above when it belongs here, otherwise this outlet's first register.</div></div>
            <div class="ctl">
              <select class="ia-select" name="registers[{{ $mo['id'] }}]">
                <option value="">Automatic</option>
                @foreach($moRegs as $o)<option value="{{ $o['id'] }}" @selected((($os['registers'] ?? [])[$mo['id']] ?? '') === (string) $o['id'])>{{ $o['name'] }}</option>@endforeach
              </select>
            </div>
          </div>
        @endforeach
        @unless($regsKnowOutlet)<p class="ec-note">Click <b>Check connection</b> so Intake learns which outlet each register belongs to.</p>@endunless
      @endif
      <p class="ec-note">Shipping and delivery charges are recorded on each sale against one item Intake makes in your Connected POS, "Online order shipping and charges". You can rename it there.</p>
    </div>
    <div class="ec-row">
      <div><div class="t">Create items in your Connected POS</div><div class="d">An item an order needs is always created first, so the sale is complete.</div></div>
      <div class="ctl"><select class="ia-select" name="items_create">
        <option value="orders" @selected(($pc->items_create ?? 'orders') === 'orders')>Only when an order needs one</option>
        <option value="always" @selected($pc->items_create === 'always')>Also whenever an item is added in Intake</option>
      </select></div>
    </div>
    <div class="ec-row">
      <div><div class="t">Send my item edits</div><div class="d">Name, SKU, barcode and description changes made in Intake go to your Connected POS. Price stays with your Connected POS.</div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="items_edits" value="0"><input type="checkbox" name="items_edits" value="1" @checked($pc->items_edits)><i></i></label></div>
    </div>
    <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save</button></div>
  </form>

  {{-- needs review --}}
  <div class="ec-sec">
    <h3>Needs review @if(count($pos['conflicts']))<span class="src-badge src-special">{{ count($pos['conflicts']) }}</span>@endif</h3>
    @forelse($pos['conflicts'] as $cf)
      @foreach((array) json_decode($cf->conflict, true) as $field => $v)
        <div class="ec-row">
          <div>
            <div class="t"><a href="{{ route('tenant.inventory.edit', $cf->item_id) }}">{{ $cf->item_name }}</a> — {{ ['name' => 'Name', 'sku' => 'SKU', 'upc' => 'Barcode', 'description' => 'Description'][$field] ?? $field }}</div>
            <div class="d">Intake: <b>{{ \Illuminate\Support\Str::limit($v['intake'] ?? '', 80) ?: '(blank)' }}</b> · Connected POS: <b>{{ \Illuminate\Support\Str::limit($v['pos'] ?? '', 80) ?: '(blank)' }}</b></div>
          </div>
          <div class="ctl">
            @foreach(['intake' => "Keep Intake's", 'pos' => "Keep the Connected POS's"] as $k => $lbl)
              <form method="POST" action="{{ route('tenant.storefront.pos.resolve') }}">@csrf<input type="hidden" name="pos_item" value="{{ $cf->id }}"><input type="hidden" name="field" value="{{ $field }}"><input type="hidden" name="keep" value="{{ $k }}"><button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">{{ $lbl }}</button></form>
            @endforeach
          </div>
        </div>
      @endforeach
    @empty
      <p class="ec-note">Nothing waiting. When Intake and your Connected POS both change the same field on an item, it shows here for you to choose.</p>
    @endforelse
  </div>

  {{-- sending activity. MARKER-POS-SENDS-LIST: the buttons sit OUTSIDE the area that refreshes,
       so a confirm dialog never ends up pointing at a form that was swapped out underneath it. --}}
  @php $kindNames = ['sale' => 'Order', 'refund' => 'Refund', 'item_create' => 'New item', 'item_update' => 'Item edit', 'item_details' => 'Item details']; @endphp
  <div class="ec-sec">
    <h3>Sending</h3>
    @if(($pos['catchup'] ?? 0) > 0 && $pc->orders_out)
      <div class="ec-row" style="border:1px solid rgba(245,158,11,.4);background:rgba(245,158,11,.08);border-radius:10px;padding:10px 12px;margin-bottom:10px">
        <div><div class="t">{{ $pos['catchup'] }} paid {{ $pos['catchup'] === 1 ? 'order' : 'orders' }} from the last 7 days {{ $pos['catchup'] === 1 ? "wasn't" : "weren't" }} sent</div><div class="d">They were paid before Send paid online orders was on. Sending records each one as a completed sale.</div></div>
        <div class="ctl"><form method="POST" action="{{ route('tenant.storefront.pos.send_recent_orders') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Send {{ $pos['catchup'] === 1 ? 'it' : 'them' }}</button></form></div>
      </div>
    @endif
    @if(($pos['details_missing'] ?? 0) > 0)
      <div class="ec-row" style="margin-bottom:10px">
        <div><div class="t">{{ number_format($pos['details_missing']) }} {{ $pos['details_missing'] === 1 ? 'item' : 'items' }} in your Connected POS without brand, category, supplier and cost</div>
          <div class="d">Sent before these went along, or their last try didn't work.@if($pos['details_error']) Last reason: <span style="color:#A32D2D">{{ \Illuminate\Support\Str::limit($pos['details_error'], 160) }}</span>@endif</div></div>
        <div class="ctl"><form method="POST" action="{{ route('tenant.storefront.pos.fill_details') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Fill in missing details</button></form></div>
      </div>
    @endif
    <div class="cp-actions" style="margin:0 0 6px">
      @if($waiting > 0)
        <form method="POST" action="{{ route('tenant.storefront.pos.stop_sends') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Stop everything still waiting to send? Anything already sent stays in your Connected POS.">Stop sending</button></form>
      @endif
      @if(($cnt['skipped'] ?? 0) > 0)
        <form method="POST" action="{{ route('tenant.storefront.pos.sends_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume {{ number_format($cnt['skipped']) }} stopped</button></form>
        <form method="POST" action="{{ route('tenant.storefront.pos.clear_stopped') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Drop the {{ number_format($cnt['skipped']) }} stopped sends? They won't be sent. Nothing changes in your Connected POS.">Drop them</button></form>
      @endif
      @if(($cnt['failed'] ?? 0) > 0)
        <form method="POST" action="{{ route('tenant.storefront.pos.clear_failed') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Clear failed item sends from this list? Nothing changes in your Connected POS. Failed orders and refunds stay until they're sent.">Clear failed items</button></form>
      @endif
    </div>

  <div id="posSends">
    @if($waiting > 0)
      <div class="cp-live"><span class="cp-dot"></span>Sending {{ number_format($waiting) }} to your Connected POS — this list updates as they go.</div>
    @endif
    <div class="av-sum" style="margin-bottom:4px">
      <div>Sent<b>{{ number_format($cnt['done'] ?? 0) }}</b></div>
      <div>Waiting<b>{{ number_format($waiting) }}</b></div>
      <div class="w">Failed<b>{{ number_format($cnt['failed'] ?? 0) }}</b></div>
      <div>Stopped<b>{{ number_format($cnt['skipped'] ?? 0) }}</b></div>
    </div>

    @if(count($pos['money_failed'] ?? []))
      <div class="t" style="margin-top:14px;font-size:13px;font-weight:500;color:#A32D2D">Orders and refunds that didn't send</div>
      <table class="cp-tbl">
        @foreach($pos['money_failed'] as $sd)
          <tr>
            <td class="what">{{ \Illuminate\Support\Str::limit($sd->label, 70) }}</td>
            <td class="err">{{ \Illuminate\Support\Str::limit($sd->last_error, 140) }}</td>
            <td class="when">{{ \Illuminate\Support\Carbon::parse($sd->updated_at)->diffForHumans(null, true) }}</td>
            <td><form method="POST" action="{{ route('tenant.storefront.pos.retry', $sd->id) }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Retry</button></form></td>
          </tr>
        @endforeach
      </table>
    @endif

    @if(count($pos['latest']))
      <div class="t" style="margin-top:14px;font-size:13px;font-weight:500">Latest</div>
      <table class="cp-tbl">
        @foreach($pos['latest'] as $sd)
          @php
            [$badge, $cls] = match ($sd->status) {
              'done'    => ['Sent', 'src-shop'],
              'failed'  => ['Failed', 'src-special'],
              'skipped' => ['Stopped', 'src-supplier'],
              default   => [$sd->last_error ? 'Retrying' : 'Waiting', 'src-supplier'],
            };
          @endphp
          <tr>
            <td class="when">{{ $kindNames[$sd->kind] ?? $sd->kind }}</td>
            <td class="what">{{ \Illuminate\Support\Str::limit($sd->label, 70) }}</td>
            <td><span class="src-badge {{ $cls }}">{{ $badge }}</span></td>
            <td class="err" @if($sd->status === 'done') style="color:#854F0B" @endif>{{ \Illuminate\Support\Str::limit($sd->last_error, 90) }}</td>
            <td class="when">{{ \Illuminate\Support\Carbon::parse($sd->updated_at)->diffForHumans(null, true) }}</td>
            <td>@if($sd->status === 'failed')<form method="POST" action="{{ route('tenant.storefront.pos.retry', $sd->id) }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Retry</button></form>@endif</td>
          </tr>
        @endforeach
      </table>
    @else
      <p class="ec-note">Nothing sent yet. Online orders, refunds and items sent to your Connected POS show here. To send items, pick them on Inventory and choose Send to Connected POS.</p>
    @endif
  </div>
  </div>

  <script>
  /* MARKER-POS-SENDS-LIST: keep the Sending list current — every 3s while anything is waiting,
     every 15s otherwise, so a new order shows up without a reload. Never while a confirm
     dialog is open. When what can be done changes (waiting/stopped/failed), the page reloads
     so the buttons match. */
  (function(){
    var sig = '{{ $waiting > 0 ? 1 : 0 }}|{{ (int) ($cnt['skipped'] ?? 0) > 0 ? 1 : 0 }}|{{ (int) ($cnt['failed'] ?? 0) > 0 ? 1 : 0 }}|{{ (int) ($pos['catchup'] ?? 0) }}|{{ ($pos['details_missing'] ?? 0) > 0 ? 1 : 0 }}';
    var busy = {{ $waiting > 0 ? 'true' : 'false' }};
    var dirty = false; /* never reload over settings someone is part-way through changing */
    document.addEventListener('input', function(){ dirty = true; }, true);
    document.addEventListener('change', function(){ dirty = true; }, true);
    function dialogOpen(){ return !!document.querySelector('.ia-confirm-backdrop'); }
    function refresh(){
      if (dialogOpen() || document.hidden) { setTimeout(refresh, 2000); return; }
      fetch(location.pathname + '?panel=pos', {headers:{'X-Intake-Background':'1'}, credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.text() : null; })
        .then(function(html){
          if(!html || dialogOpen()) return;
          var doc = new DOMParser().parseFromString(html,'text/html');
          var fresh = doc.getElementById('posSends'), box = document.getElementById('posSends');
          var m = html.match(/var sig = '([^']*)'/);
          if (m && m[1] !== sig && !dirty) { location.reload(); return; }
          if(fresh && box){ box.replaceWith(fresh); }
          busy = !!(fresh && fresh.querySelector('.cp-live'));
        })
        .catch(function(){})
        .finally(function(){ setTimeout(refresh, busy ? 3000 : 15000); });
    }
    setTimeout(refresh, busy ? 3000 : 15000);
  })();
  /* MARKER-CONNECTED-POS-CATALOG: live import progress (background poll) */
  (function(){
    var box=document.getElementById('posSync'); if(!box) return;
    var url=box.dataset.status, live=box.dataset.running==='1';
    function fmt(n){ return (n||0).toLocaleString(); }
    function tick(){
      fetch(url,{headers:{'Accept':'application/json','X-Intake-Background':'1'},credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(j){
          if(!j || !j.state) return;
          var s=j.state, wait=s.running && !(s.pages>0);
          box.querySelectorAll('[data-k]').forEach(function(b){ b.textContent=fmt(s[b.dataset.k]); });
          document.getElementById('psHead').textContent = wait ? 'Waiting for a worker to start…' : (s.running ? 'Importing…' : (s.stopped ? 'Stopped — automatic sync is paused' : (s.error ? 'Last run stopped with an error' : 'Up to date')));
          document.getElementById('psPhase').textContent = (s.running && !wait) ? ('Step: ' + (s.phase==='stock' ? 'reading stock counts' : 'reading products') + ' · page ' + (s.pages||0)) : '';
          document.getElementById('psErr').textContent = s.error || '';
          document.getElementById('psLine').textContent = fmt(j.linked) + ' items linked to your Connected POS.' + (j.last_synced_at ? ' Last run finished ' + j.last_synced_at + '.' : '');
          live = !!s.running;
        })
        .catch(function(){})
        .finally(function(){ setTimeout(tick, live ? 2000 : 15000); });
    }
    tick();
  })();
  </script>
@elseif(count($pos['available']))
  <div class="ec-sec">
    <h3>Connect your outside POS</h3>
    @foreach($pos['available'] as $a)
      <div class="ec-row">
        <div><div class="t">{{ $a->displayLabel() }}</div><div class="d">You'll sign in to your Connected POS and approve Intake, then come straight back here.</div></div>
        <div class="ctl"><form method="POST" action="{{ route('tenant.storefront.pos.connect', $a->code) }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Connect</button></form></div>
      </div>
    @endforeach
  </div>
@else
  <div class="ec-sec">
    <h3>Connect your outside POS</h3>
    <p class="ec-note">No POS connections are switched on for your account yet. Reach out to Intake support and we'll turn on the one you use.</p>
  </div>
@endif
