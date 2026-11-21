{{-- MARKER-POS-SENDS-LIST: everything Intake sends to the Connected POS, and what needs a decision.
     The buttons sit OUTSIDE the area that refreshes (#posSends), so a confirm dialog never ends up pointing
     at a form that was swapped out underneath it. This partial is also what the refresh fetches. --}}
@php
  $pc = $pos['conn'];
  $cnt = $pos['counts'] ?? collect();
  $waiting = (int) ($cnt['pending'] ?? 0);
  $twoWay = $pc->isTwoWay();
  $kindNames = ['sale' => 'Order', 'refund' => 'Refund', 'item_create' => 'New item', 'item_update' => 'Item edit', 'item_details' => 'Item details'];
  $sendSig = ($waiting > 0 ? 1 : 0) . '|' . ((int) ($cnt['skipped'] ?? 0) > 0 ? 1 : 0) . '|' . ((int) ($cnt['failed'] ?? 0) > 0 ? 1 : 0) . '|' . (($pos['details_missing'] ?? 0) > 0 ? 1 : 0) . '|' . count($pos['conflicts']);
@endphp
<div class="ec-sec cpx-wide">
  <h3>Sending</h3>
  @unless($twoWay)
    <p class="ec-note" style="margin-bottom:10px">Your Connected POS is <b>Reading Only</b>, so nothing is sent to it. This is the record of what was sent before you switched over.</p>
  @else
    <p class="ec-note" style="margin-bottom:10px">Online orders and their refunds, items an order needs, items you send from Inventory, and your item edits if you chose to send them. @if(tenant()->online_store_enabled) Sending online orders is switched on in <a class="cpx-lnk" href="{{ route('tenant.storefront.settings', ['tab' => 'pos']) }}">E-commerce › Connected POS</a>.@endif</p>
  @endunless
  @if($twoWay && ($pos['details_missing'] ?? 0) > 0)
    <div class="ec-row" style="margin-bottom:10px">
      <div><div class="t">{{ number_format($pos['details_missing']) }} {{ $pos['details_missing'] === 1 ? 'item' : 'items' }} in your Connected POS without brand, category, supplier and cost</div>
        <div class="d">Fills in what Intake knows. Sent before these went along, or their last try didn't work.@if($pos['details_error']) Last reason: <span style="color:#F26D6D">{{ \Illuminate\Support\Str::limit($pos['details_error'], 160) }}</span>@endif</div></div>
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.fill_details') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Fill in missing details</button></form></div>
    </div>
  @endif
  <div class="cp-actions" style="margin:0 0 6px">
    @if($waiting > 0)
      <form method="POST" action="{{ route('tenant.cpos.stop_sends') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Stop everything still waiting to send? Anything already sent stays in your Connected POS.">Stop sending</button></form>
    @endif
    @if(($cnt['skipped'] ?? 0) > 0)
      @if($twoWay)<form method="POST" action="{{ route('tenant.cpos.sends_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume {{ number_format($cnt['skipped']) }} stopped</button></form>@endif
      <form method="POST" action="{{ route('tenant.cpos.clear_stopped') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Drop the {{ number_format($cnt['skipped']) }} stopped sends? They won't be sent. Nothing changes in your Connected POS.">Drop them</button></form>
    @endif
    @if(($cnt['failed'] ?? 0) > 0)
      <form method="POST" action="{{ route('tenant.cpos.clear_failed') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Clear failed item sends from this list? Nothing changes in your Connected POS. Failed orders and refunds stay until they're sent.">Clear failed items</button></form>
    @endif
  </div>

  <div id="posSends" data-sig="{{ $sendSig }}">
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
      <div class="t" style="margin-top:14px;font-size:13px;font-weight:500;color:#F26D6D">Orders and refunds that didn't send</div>
      <table class="cp-tbl">
        @foreach($pos['money_failed'] as $sd)
          <tr>
            <td class="what">{{ \Illuminate\Support\Str::limit($sd->label, 70) }}</td>
            <td class="err">{{ \Illuminate\Support\Str::limit($sd->last_error, 140) }}</td>
            <td class="when">{{ \Illuminate\Support\Carbon::parse($sd->updated_at)->diffForHumans(null, true) }}</td>
            <td>@if($twoWay)<form method="POST" action="{{ route('tenant.cpos.retry', $sd->id) }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Retry</button></form>@endif</td>
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
            <td class="err" @if($sd->status === 'done') style="color:#EF9F27" @endif>{{ \Illuminate\Support\Str::limit($sd->last_error, 90) }}</td>
            <td class="when">{{ \Illuminate\Support\Carbon::parse($sd->updated_at)->diffForHumans(null, true) }}</td>
            <td>@if($sd->status === 'failed' && $twoWay)<form method="POST" action="{{ route('tenant.cpos.retry', $sd->id) }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Retry</button></form>@endif</td>
          </tr>
        @endforeach
      </table>
    @else
      <p class="ec-note">Nothing sent yet. Online orders, refunds and items sent to your Connected POS show here. To send items, tick them on Inventory and choose Send to Connected POS.</p>
    @endif
  </div>
</div>

{{-- needs review --}}
<div class="ec-sec cpx-wide">
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
            @continue($k === 'intake' && ! $twoWay)
            <form method="POST" action="{{ route('tenant.cpos.resolve') }}">@csrf<input type="hidden" name="pos_item" value="{{ $cf->id }}"><input type="hidden" name="field" value="{{ $field }}"><input type="hidden" name="keep" value="{{ $k }}"><button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">{{ $lbl }}</button></form>
          @endforeach
        </div>
      </div>
    @endforeach
  @empty
    <p class="ec-note">Nothing waiting. When Intake and your Connected POS both change the same field on an item, it shows here for you to choose.</p>
  @endforelse
</div>

@unless(request('part') === 'sending')
<script>
/* MARKER-POS-SENDS-LIST: keep the list current — every 3s while anything is waiting, every 15s otherwise,
   so a new order shows up without a reload. Never while a confirm dialog is open. When what can be done
   changes (waiting / stopped / failed / needs review), the page reloads so the buttons match. */
(function(){
  var box = document.getElementById('posSends'); if (!box) { return; }
  var sig = box.dataset.sig, busy = !!box.querySelector('.cp-live');
  var url = @json(route('tenant.cpos.home', ['tab' => 'sending', 'part' => 'sending']));
  function open(){ return !!document.querySelector('.ia-confirm-backdrop'); }
  function refresh(){
    if (open() || document.hidden) { setTimeout(refresh, 2000); return; }
    fetch(url, {headers: {'X-Intake-Background': '1'}, credentials: 'same-origin'})
      .then(function(r){ return r.ok ? r.text() : null; })
      .then(function(html){
        if (!html || open()) { return; }
        var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('posSends');
        var cur = document.getElementById('posSends');
        if (!fresh || !cur) { return; }
        if (fresh.dataset.sig !== sig) { location.reload(); return; }
        cur.replaceWith(fresh);
        busy = !!fresh.querySelector('.cp-live');
      })
      .catch(function(){})
      .finally(function(){ setTimeout(refresh, busy ? 3000 : 15000); });
  }
  setTimeout(refresh, busy ? 3000 : 15000);
})();
</script>
@endunless
