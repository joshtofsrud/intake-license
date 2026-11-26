{{-- MARKER-POS-SALES-HISTORY: past sales from the Connected POS. Options, then a read with live progress and
     Stop/Resume. Nothing here touches stock, the cash drawer or the ledger; the legend under the heading says so. --}}
@php
  $pc = $pos['conn'];
  $hs = (array) ($pc->sales_state ?? []);
  $ho = \App\Services\Pos\PosSalesSync::options($pc);
  $hsum = \App\Services\Pos\PosSalesSync::summary($pc->tenant_id);
  $hFull = (bool) ($hs['full'] ?? false);
  $hRunning = (bool) ($hs['running'] ?? false) && $hFull;       // a history read the shop is watching
  $hStopped = (bool) ($hs['stopped'] ?? false);
  $hDone = ! empty($hs['last_done']);
  $hEver = $hDone || $hStopped || $hsum['sales'] > 0;
  $hTz = tenant()->timezone();
  $hAt = \App\Services\Pos\PosSalesSync::reached($hs, $hTz);
  $hPct = \App\Services\Pos\PosSalesSync::percent($hs);
  $hLeft = (int) ($hs['ours'] ?? 0) + (int) ($hs['older'] ?? 0) + (int) ($hs['no_customer'] ?? 0) + (int) ($hs['voided'] ?? 0) + (int) ($hs['open'] ?? 0);
  $hDay = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M j, Y') : null;
  $twoWay = $pc->isTwoWay();
@endphp
<div class="ec-sec">
  <h3>Sales history <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Past sales are copied in for customer pages, Buying, reports and Recovery. They don't change stock, the cash drawer or your ledger, and they're marked <b>From Connected POS</b> everywhere they show.</span></h3>

  <details class="cp-legend" style="margin-bottom:10px">
    <summary>What is and isn't affected</summary>
    <ul>
      <li><b>Not touched:</b> stock counts, the cash drawer, tax, payments, your Intake sales and their reports. Nothing is written to your Connected POS.</li>
      <li><b>Where they show:</b> a customer's activity (with their Connected POS spend on its own line), Buying › Moving, the <b>Connected POS sales</b> data in custom reports, and at-risk regulars in Recovery.</li>
      <li><b>Left out:</b> sales Intake sent there itself (your online orders are already Intake sales, so they'd be counted twice), sales that were voided or never finished, and anything older than "How far back".</li>
      <li><b>Customers and items link up on their own.</b> A sale keeps your Connected POS's customer and product. When that customer or product is brought into Intake, before or after, its sales show under it. Nothing has to be read again.</li>
      <li><b>New sales</b> keep coming in about every minute while that is on, until you switch over. After Switch over your Connected POS is Reading Only and sales are made in Intake.</li>
      <li><b>Progress</b> follows the dates of the sales read: your Connected POS hands them over oldest first and doesn't say how many there are.</li>
    </ul>
  </details>

  <form method="POST" action="{{ route('tenant.cpos.sales') }}">
    @csrf
    <div class="ec-row"><div><div class="t">How far back <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>More history makes Buying and Recovery smarter, and takes longer.</span></div></div>
      <div class="ctl"><select class="ia-select" name="range" @disabled($hRunning)>
        @foreach(['24m', '12m', 'all'] as $k)<option value="{{ $k }}" @selected($ho['range'] === $k)>{{ \App\Services\Pos\PosSalesSync::RANGES[$k] }}</option>@endforeach
      </select></div></div>
    <div class="ec-row"><div><div class="t">Sales without a customer <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Counted toward item sales and reports; not shown on any customer page.</span></div></div>
      <div class="ctl"><select class="ia-select" name="no_customer" @disabled($hRunning)>
        <option value="in" @selected($ho['no_customer'] === 'in')>Bring them in</option>
        <option value="skip" @selected($ho['no_customer'] === 'skip')>Skip them</option>
      </select></div></div>
    <div class="ec-row"><div><div class="t">Products you haven't imported <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>The sale line keeps the product name and SKU so nothing is lost; it links up if you import the item later.</span></div></div>
      <div class="ctl"><span class="cpx-pip">Kept as text lines</span></div></div>
    <div class="ec-row"><div><div class="t">Keep new sales coming in <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>@if($twoWay) After the history is in, sales made in your Connected POS arrive about every minute, until you switch over. @else You have switched over, so new sales no longer come in. History can still be read. @endif</span></div></div>
      <div class="ctl"><label class="tg"><input type="hidden" name="sales_in" value="0" @disabled(! $twoWay)><input type="checkbox" name="sales_in" value="1" @checked($pc->sales_options === null ? true : $pc->sales_in) @disabled($hRunning || ! $twoWay)><i></i></label></div></div>
    @unless($hRunning)
      <div class="ec-actions">
        @if($hEver)
          <button class="ia-btn ia-btn--primary ia-btn--sm" type="submit" name="go" value="save">Save</button>
          {{-- MARKER-SALES-START-OVER: a stopped read can start over too (it used to offer only Resume, from its old place) --}}
          <button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" name="go" value="start" data-confirm="{{ $hStopped ? 'Start over with the choices above, instead of resuming from ' . ($hAt ?: 'where it stopped') . '? Sales already in are updated, not doubled.' : 'Read your sales history again from the start? Sales already in are updated, not doubled, and anything outside your choices above is taken back out.' }}">{{ $hStopped ? 'Start over' : 'Read again' }}</button>
        @else
          <button class="ia-btn ia-btn--primary" type="submit" name="go" value="start">Start</button>
        @endif
      </div>
    @else
      <p class="ec-note">Stop the read to change these.</p>
    @endunless
  </form>

  @if($hs)
    <div class="cpx-run">
      <div style="font-size:13px;font-weight:500">
        @if($hRunning) <span class="cp-dot" style="display:inline-block;margin-right:6px"></span>Reading sales · <span data-live="sales.reached">{{ $hAt ?: 'starting' }}</span>
        @elseif($hStopped) Stopped{{ $hAt ? ' at ' . $hAt : '' }}
        @elseif(! empty($hs['error'])) The last read stopped with an error
        @elseif($hDone) Sales history is in{{ $pc->sales_in && $twoWay ? ' · new sales arrive about every minute' : '' }}
        @else Not started @endif
      </div>
      @if($hRunning || $hStopped)
        <div class="cpx-prog"><i data-live-pct="sales.percent" style="width:{{ $hPct }}%"></i></div>
      @endif
      <div class="av-sum" style="margin-top:6px">
        <div>Sales<b data-live="sales.kept">{{ number_format($hs['kept'] ?? 0) }}</b></div>
        <div>Lines<b data-live="sales.lines">{{ number_format($hs['lines'] ?? 0) }}</b></div>
        <div>On customers<b data-live="sales.on_customers">{{ number_format($hs['on_customers'] ?? 0) }}</b></div>
        <div>Linked items<b data-live="sales.linked_lines">{{ number_format($hs['linked_lines'] ?? 0) }}</b></div>
        <div class="w">Left out <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>{{ number_format($hs['seen'] ?? 0) }} looked at{{ ! $hFull && ! empty($hs['finished_at']) ? ', last checked ' . \Illuminate\Support\Carbon::parse($hs['finished_at'])->diffForHumans() : '' }}. Left out: {{ number_format($hs['ours'] ?? 0) }} Intake sent there · {{ number_format($hs['older'] ?? 0) }} older than your range · {{ number_format($hs['no_customer'] ?? 0) }} with no customer · {{ number_format($hs['voided'] ?? 0) }} voided · {{ number_format($hs['open'] ?? 0) }} not finished.</span><b data-live="sales.left_out">{{ number_format($hLeft) }}</b></div>
      </div>
      @if(! empty($hs['error']))<div class="d" style="color:#F26D6D;font-size:12.5px;margin-top:6px">{{ $hs['error'] }}</div>@endif
      <div class="cp-actions">
        @if($hRunning)
          <form method="POST" action="{{ route('tenant.cpos.sales_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form>
        @elseif($hStopped || ! empty($hs['error']))
          <form method="POST" action="{{ route('tenant.cpos.sales_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume{{ $hAt ? ' from ' . $hAt : '' }}</button></form>
        @endif
      </div>
    </div>
  @endif
</div>

@if($hsum['sales'] > 0)
  <div class="ec-sec">
    <h3>In Intake now</h3>
    <div class="av-sum">
      <div>Sales<b>{{ number_format($hsum['sales']) }}</b></div>
      <div>On Intake customers<b>{{ number_format($hsum['on_customers']) }}</b></div>
      <div>From<b>{{ $hDay($hsum['first']) }}</b></div>
      <div>To<b>{{ $hDay($hsum['last']) }}</b></div>
    </div>
    <div class="cp-actions">
      @php
        // opens the builder straight on Connected POS sales by month (the builder reads ?cfg=, base64 JSON)
        $hCfg = rtrim(strtr(base64_encode(json_encode(['ds' => 'pos', 'measures' => ['rev', 'tickets', 'avg'], 'dim' => 'month', 'range' => 'Last 12 months'])), '+/', '-_'), '=');
      @endphp
      <a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.reports.custom', ['cfg' => $hCfg]) }}">Open the report</a>
      <a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.buying') }}">Open Buying</a>
      @unless($hRunning)
        <form method="POST" action="{{ route('tenant.cpos.sales_clear') }}">@csrf<button class="ia-btn ia-btn--ghost ia-btn--sm" type="submit" data-confirm="Remove all {{ number_format($hsum['sales']) }} Connected POS sales from Intake? They disappear from customer pages, Buying, reports and Recovery. Your Connected POS, your stock and your own Intake sales are not touched, and you can read the history in again.">Remove sales history</button></form>
      @endunless
    </div>
  </div>
@endif
@include('tenant.connected-pos._live')
