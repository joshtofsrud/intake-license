{{-- MARKER-CONNECTED-POS-SECTION: E-commerce → Connected POS. Only the part that is about the online store:
     sending paid online orders to the shop's own outside POS (not the Intake Register) as sales, and what
     each sale is recorded with. The connection, items, customers and the sends list live in the Connected
     POS section. --}}
@php
  $pc = $pos['conn'];
  $os = (array) ($pc->order_setup ?? []);
  $opt = (array) ($pc->setup_options ?? []);
  $twoWay = $pc ? $pc->isTwoWay() : true;
  $sectionOk = tenant()->connected_pos_enabled && auth('tenant')->user()?->canAccessSection('connected_pos');
@endphp
<style>
.cp-legend{max-width:760px;margin-bottom:18px;font-size:12.5px;color:var(--ia-text-muted);line-height:1.6;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:10px 14px}
.cp-legend summary{cursor:pointer;color:var(--ia-text);font-weight:500;font-size:13px;list-style:none}
.cp-legend summary::-webkit-details-marker{display:none}
.cp-legend summary:after{content:" ▾";color:var(--ia-text-muted)}
.cp-legend[open] summary:after{content:" ▴"}
.cp-legend ul{margin:8px 0 0 16px;padding:0}
.cp-legend b{color:var(--ia-text);font-weight:500}
.cp-movebar{display:flex;justify-content:space-between;align-items:center;gap:12px;max-width:760px;padding:11px 14px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);margin-bottom:22px;font-size:12.5px;color:var(--ia-text-muted);flex-wrap:wrap}
.cp-movebar b{color:var(--ia-text);font-weight:500}
.cp-movebar a{color:var(--ia-accent);text-decoration:none;white-space:nowrap}
</style>

@if(! tenant()->connected_pos_enabled)
  <div class="ec-sec">
    <h3>Connected POS</h3>
    <p class="ec-note">Still running another POS? <b>Connected POS</b> links it to Intake, so online orders are recorded there as sales and its stock is what your online store sells from. It's a free add-on on Branded and up.</p>
    <div class="ec-actions" style="margin-top:10px"><a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.feature_addons.index') }}">Open Add-ons</a></div>
  </div>
@elseif(! $pc)
  <div class="cp-movebar"><span><b>No Connected POS is connected yet.</b> Connect yours first; then the settings for sending online orders appear here.</span>@if($sectionOk)<a href="{{ route('tenant.cpos.home') }}">Open Connected POS →</a>@endif</div>
@else
  <div class="cp-movebar"><span>The connection, items, customers and the list of what's been sent are in <b>Connected POS</b>.</span>@if($sectionOk)<a href="{{ route('tenant.cpos.home') }}">Open Connected POS →</a>@endif</div>

  <details class="cp-legend">
    <summary>What sending online orders does</summary>
    <ul>
      <li><b>Connected POS</b> is the outside POS your shop uses, not the Intake Register.</li>
      <li>With this on, each <b>paid</b> online order is recorded there as a completed sale, at the outlet mapped to the order's location, with its customer. Its refunds follow.</li>
      <li>An item the order needs that your Connected POS doesn't have is created there first, so the sale is complete.</li>
      <li>Shipping and delivery charges go on the sale as a line of their own.</li>
      <li>Nothing here changes prices or stock in Intake. Every send, and anything that failed, is listed under Connected POS › Sending.</li>
    </ul>
  </details>

  @unless($twoWay)
    <div class="ec-sec">
      <h3>Online orders to your Connected POS</h3>
      <p class="ec-note">Your Connected POS is <b>Reading Only</b>: you've switched over to Intake, so online orders are no longer sent to it. To send them again, go back to 2 Way Data under Connected POS › Settings.</p>
    </div>
  @else
  <form method="POST" action="{{ route('tenant.storefront.pos.orders') }}" class="ec-sec">
    @csrf
    <h3>Online orders to your Connected POS</h3>
    <div class="ec-row">
      <div><div class="t">Send paid online orders as sales</div><div class="d">Recorded as completed sales at the outlet mapped to the order's location, with their refunds.</div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="orders_out" value="0"><input type="checkbox" name="orders_out" value="1" @checked($pc->orders_out)><i></i></label></div>
    </div>
    @php $setupMissing = ! count($opt['registers'] ?? []) || ! count($opt['users'] ?? []); @endphp
    @if($setupMissing)
      <p class="ec-note" style="color:#854F0B;margin:4px 0 8px">Your Connected POS hasn't shared its registers and staff with Intake yet. In Connected POS, click <b>Disconnect</b>, then <b>Connect</b> again to give Intake that permission, then <b>Check connection</b>.</p>
    @endif
    @if(empty($pc->location_map))
      <p class="ec-note" style="color:#854F0B;margin:4px 0 8px">No outlet is mapped to an Intake location yet. Do that under Connected POS › Settings, so Intake knows which outlet a sale belongs to.</p>
    @endif
    <div class="ec-sub">
      @foreach([['register_id', 'Register', $opt['registers'] ?? []], ['user_id', 'Recorded by', $opt['users'] ?? []], ['payment_type_id', 'Payment type', $pc->payment_types ?? []], ['tax_id', 'Tax', $opt['taxes'] ?? []]] as [$fld, $lbl, $list])
        @if($fld !== 'tax_id' || count($list))
          <div class="ec-row">
            <div><div class="t">{{ $lbl }}</div>@if($fld === 'payment_type_id')<div class="d">The one you made for web sales (for example "Intake Online"), so online money stays separate from the till.</div>@endif</div>
            <div class="ctl">
              <select class="ia-select" name="{{ $fld }}" @disabled(! count($list))>
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
        @unless($regsKnowOutlet)<p class="ec-note">Click <b>Check connection</b> in Connected POS so Intake learns which outlet each register belongs to.</p>@endunless
      @endif
      <p class="ec-note">Shipping and delivery charges are recorded on each sale against one item Intake makes in your Connected POS, "Online order shipping and charges". You can rename it there.</p>
    </div>
    <div class="ec-actions"><button class="ia-btn ia-btn--primary" type="submit">Save</button></div>
  </form>

  <div class="ec-sec">
    <h3>Earlier orders</h3>
    @if(($pos['catchup'] ?? 0) > 0 && $pc->orders_out)
      <div class="ec-row">
        <div><div class="t">{{ $pos['catchup'] }} paid {{ $pos['catchup'] === 1 ? 'order' : 'orders' }} from the last 7 days {{ $pos['catchup'] === 1 ? "wasn't" : "weren't" }} sent</div><div class="d">Paid before sending was on. Sending records each one as a completed sale.</div></div>
        <div class="ctl"><form method="POST" action="{{ route('tenant.storefront.pos.send_recent_orders') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Send {{ $pos['catchup'] === 1 ? 'it' : 'them' }}</button></form></div>
      </div>
    @elseif(($pos['catchup'] ?? 0) > 0)
      <p class="ec-note">{{ $pos['catchup'] }} paid {{ $pos['catchup'] === 1 ? 'order' : 'orders' }} from the last 7 days {{ $pos['catchup'] === 1 ? "hasn't" : "haven't" }} been sent. Turn on sending above and save, then send {{ $pos['catchup'] === 1 ? 'it' : 'them' }} from here.</p>
    @else
      <p class="ec-note">Every paid order from the last 7 days is in your Connected POS, or none were placed.</p>
    @endif
    @php $mf = count($pos['money_failed'] ?? []); @endphp
    @if($mf)
      <p class="ec-note" style="color:#A32D2D;margin-top:8px">{{ $mf }} {{ $mf === 1 ? 'order or refund' : 'orders or refunds' }} didn't send. @if($sectionOk)<a class="ec-link" href="{{ route('tenant.cpos.home', ['tab' => 'sending']) }}">See why and retry</a>@else See Connected POS › Sending.@endif</p>
    @endif
  </div>
  @endunless
@endif
