{{-- MARKER-CONNECTED-POS-SECTION: Overview --}}
@php
  $pc = $pos['conn'];
  $it = $pos['items'];
  $cs = (array) ($pc->customer_sync ?? []);
  $ss = (array) ($pc->sync_state ?? []);
  $im = (array) ($pc->import_state ?? []);
  $cnt = $pos['counts'] ?? collect();
  $moving = $pos['use'] === 'move';
  $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t) : null;

  // what happened lately, newest first, from what is already recorded
  $acts = collect();
  foreach ($pos['latest']->take(4) as $sd) {
      $acts->push([$when($sd->updated_at), \Illuminate\Support\Str::limit($sd->label, 70) . ' · ' . (['done' => 'sent', 'failed' => 'failed to send', 'skipped' => 'not sent', 'pending' => 'waiting to send'][$sd->status] ?? $sd->status)]);
  }
  if (! empty($ss['finished_at']) && ! ($ss['running'] ?? false)) {
      $acts->push([$when($ss['finished_at']), 'Catalog read: ' . number_format($ss['items_seen'] ?? 0) . ' products · ' . number_format($ss['matched'] ?? 0) . ' linked · ' . number_format($ss['price_updates'] ?? 0) . ' prices · ' . number_format($ss['stock_changes'] ?? 0) . ' stock changes']);
  }
  if (! empty($cs['finished_at']) && ! ($cs['running'] ?? false)) {
      $acts->push([$when($cs['finished_at']), 'Customers checked: ' . number_format($cs['created'] ?? 0) . ' new, ' . number_format($cs['filled'] ?? 0) . ' filled in']);
  }
  if (! empty($im['finished_at']) && ! ($im['running'] ?? false)) {
      $acts->push([$when($im['finished_at']), 'Import' . (($im['stopped'] ?? false) ? ' stopped' : '') . ': ' . number_format($im['created'] ?? 0) . ' new items, ' . number_format($im['linked'] ?? 0) . ' linked']);
  }
  $acts = $acts->filter(fn ($a) => $a[0])->sortByDesc(fn ($a) => $a[0]->timestamp)->take(7);
@endphp

<div style="display:flex;gap:12px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
  <span style="font-size:12.5px;color:var(--ia-text-muted)">How you use it</span>
  <form method="POST" action="{{ route('tenant.cpos.use') }}" class="cpx-seg">
    @csrf
    <button type="submit" name="use" value="stay" class="{{ $moving ? '' : 'on' }}" @disabled(! $moving)>Staying on Connected POS</button>
    <button type="submit" name="use" value="move" class="{{ $moving ? 'on' : '' }}" @disabled($moving)>Moving to Intake</button>
  </form>
</div>
<p class="ec-note" style="margin-bottom:10px">
  @if(! $pc->isTwoWay()) You have switched over. Intake is your POS now; your Connected POS is Reading Only.
  @elseif($moving) You are moving to Intake. Bring things in step by step; your Connected POS keeps working until you switch over.
  @else Your Connected POS stays the till and the source of stock. Intake runs the rest and sends online orders back to it. @endif
</p>

<div class="cpx-strip">
  <div><div class="k">Items</div><div class="v" data-live="items.linked">{{ number_format($it['linked']) }}</div>
    <div class="d">linked {{ $pc->ownsPriceAndStock() ? 'and in sync' : '' }}<br><span data-live="items.waiting">{{ number_format($it['waiting']) }}</span> waiting in Import @if($it['new']) · {{ number_format($it['new']) }} new @endif</div>
    <a href="{{ route('tenant.cpos.home', ['tab' => 'items']) }}">Open →</a></div>
  <div><div class="k">Customers</div><div class="v">{{ $pc->customers_in || ! empty($cs['last_done']) ? number_format(\Illuminate\Support\Facades\DB::table('tenant_pos_customers')->where('tenant_id', $pc->tenant_id)->count()) : '—' }}</div>
    <div class="d">@if($cs['running'] ?? false) coming in now @elseif($pc->customers_in) brought in · new ones about every minute @elseif(! empty($cs['last_done'])) brought in · not coming in any more @else not brought in yet @endif</div>
    <a href="{{ route('tenant.cpos.home', ['tab' => 'customers']) }}">{{ $pc->customers_in || ! empty($cs['last_done']) ? 'Open' : 'Start' }} →</a></div>
  <div><div class="k">Sending</div><div class="v">{{ $pc->isTwoWay() ? number_format((int) ($cnt['pending'] ?? 0)) : '—' }}</div>
    <div class="d">@if($pc->isTwoWay()) waiting · {{ number_format((int) ($cnt['failed'] ?? 0)) }} failed<br>{{ $pos['last_sale'] ? 'last order sent ' . $when($pos['last_sale'])->diffForHumans() : 'no orders sent yet' }} @else Reading Only: nothing is sent @endif</div>
    <a href="{{ route('tenant.cpos.home', ['tab' => 'sending']) }}">Open →</a></div>
</div>

@if($moving)
  <div class="ec-sec">
    <h3>Moving to Intake <span class="cpx-pip">{{ $move['done'] }} of {{ $move['total'] }}</span></h3>
    @include('tenant.connected-pos._move-steps')
  </div>
@endif

<div class="ec-sec">
  <h3>Recent activity</h3>
  @if($acts->count())
    <div class="cpx-act">
      @foreach($acts as [$at, $what])
        <div><span class="w">{{ $at->isToday() ? tlocal($at, 'g:i A') : tlocal($at, 'M j') }}</span>{{ $what }}</div>
      @endforeach
    </div>
  @else
    <p class="ec-note">Nothing yet. Start under <a class="cpx-lnk" href="{{ route('tenant.cpos.home', ['tab' => 'items']) }}">Items</a>: read your catalog, then choose what to add.</p>
  @endif
</div>
@include('tenant.connected-pos._live')
