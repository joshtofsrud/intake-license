{{-- MARKER-CONNECTED-POS-SECTION: the Move to Intake checklist. Used on the Overview tab and on the
     Move to Intake page, so the two can't drift. Expects $pos and $move. --}}
@php
  $pc = $pos['conn'];
  $ss = (array) ($pc?->sync_state ?? []);
  $cs = (array) ($pc?->customer_sync ?? []);
  $im = (array) ($pc?->import_state ?? []);
  $it = $pos['items'];
  $by = collect($move['steps'])->keyBy('key');
  $cls = fn ($k) => $by[$k]['done'] ? 'done' : ($by[$k]['now'] ? 'now' : '');
  $num = fn ($k) => $by[$k]['done'] ? '✓' : $by[$k]['n'];
  $reading = (bool) ($ss['running'] ?? false);
  $switching = $reading && ! empty($ss['switch_after']);
  $waitingSends = (int) (($pos['counts'] ?? collect())['pending'] ?? 0);
@endphp
<div class="cpx-steps">
  {{-- 1 --}}
  <div class="cpx-st {{ $cls('connect') }}"><span class="n">{{ $num('connect') }}</span>
    <div class="b"><div class="t">Connect your current POS</div>
      <div class="d">@if($by['connect']['done']) {{ $pos['label'] }} connected {{ $pc->connected_at?->diffForHumans() }}. @elseif($pc) The connection needs attention: {{ $pc->last_error ?: 'reconnect it.' }} @else Sign in there and approve Intake. Nothing is copied yet. @endif</div></div>
    @unless($by['connect']['done'])<div class="ctl"><a class="ia-btn ia-btn--primary ia-btn--sm" href="{{ route('tenant.cpos.home') }}">{{ $pc ? 'Open Connected POS' : 'Connect' }}</a></div>@endunless
  </div>

  {{-- 2 --}}
  <div class="cpx-st {{ $cls('read') }}"><span class="n">{{ $num('read') }}</span>
    <div class="b"><div class="t">Read your catalog</div>
      <div class="d">
        @if($reading && ! $switching) Reading… <b data-live="state.items_seen">{{ number_format($ss['items_seen'] ?? 0) }}</b> products so far.
        @elseif($by['read']['done']) {{ number_format($it['total']) }} products read. {{ number_format($it['linked']) }} are items you already carry, and are linked.
        @else Reads every product and links the ones you already carry. Nothing is created. @endif
      </div></div>
    @if($by['connect']['done'] && ! $by['read']['done'] && ! $reading)
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.read') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Read now</button></form></div>
    @elseif($reading && ! $switching)
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.read_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit">Stop</button></form></div>
    @elseif(($ss['stopped'] ?? false) && ! $by['read']['done'])
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.read_resume') }}">@csrf<button class="ia-btn ia-btn--primary ia-btn--sm" type="submit">Resume</button></form></div>
    @endif
  </div>

  {{-- 3 --}}
  <div class="cpx-st {{ $cls('items') }}"><span class="n">{{ $num('items') }}</span>
    <div class="b"><div class="t">Choose the items to bring in</div>
      <div class="d">
        @if(! $by['read']['done']) After your catalog is read.
        @elseif($it['waiting']) <span data-live="items.waiting">{{ number_format($it['waiting']) }}</span> {{ $it['waiting'] === 1 ? 'product is' : 'products are' }} only in your Connected POS. Filter to what you still sell, preview, and import.
        @else Every product in your Connected POS is linked to an Intake item. @endif
      </div>
      @if($im['running'] ?? false)
        <div class="cpx-prog"><i data-live-bar="import" style="width:{{ ($im['total'] ?? 0) ? round(100 * ($im['done'] ?? 0) / $im['total']) : 0 }}%"></i></div>
        <div class="ec-note">Importing <span data-live="import.done">{{ number_format($im['done'] ?? 0) }}</span> of {{ number_format($im['total'] ?? 0) }}</div>
      @elseif($im['stopped'] ?? false)
        <div class="ec-note">An import is stopped at {{ number_format($im['done'] ?? 0) }} of {{ number_format($im['total'] ?? 0) }}. Resume it from Import.</div>
      @endif
    </div>
    @if($by['read']['done'])<div class="ctl"><a class="ia-btn {{ $by['items']['now'] ? 'ia-btn--primary' : 'ia-btn--secondary' }} ia-btn--sm" href="{{ route('tenant.inventory.cpos') }}">Open Import</a></div>@endif
  </div>

  {{-- 4 --}}
  <div class="cpx-st {{ $cls('customers') }}"><span class="n">{{ $num('customers') }}</span>
    <div class="b"><div class="t">Bring in customers</div>
      <div class="d">
        @if($cs['running'] ?? false) Bringing in customers… <b data-live="customers.seen">{{ number_format($cs['seen'] ?? 0) }}</b> read so far.
        @elseif($by['customers']['done']) {{ number_format(($cs['created'] ?? 0) + ($cs['matched'] ?? 0)) }} customers in from the last full read. New and changed ones keep coming in while Bring in customers is on.
        @else Matched to customers you already have by email, then phone. Only blank fields are filled; nobody is opted in to marketing. @endif
      </div></div>
    @if($by['connect']['done'] && ! $by['customers']['done'] && ! ($cs['running'] ?? false))
      <div class="ctl">
        @if(($cs['stopped'] ?? false))
          <a class="ia-btn ia-btn--secondary ia-btn--sm" href="{{ route('tenant.cpos.home', ['tab' => 'customers']) }}">Open Customers</a>
        @else
          <form method="POST" action="{{ route('tenant.cpos.customers') }}">@csrf<input type="hidden" name="customers_in" value="1"><button class="ia-btn {{ $by['customers']['now'] ? 'ia-btn--primary' : 'ia-btn--secondary' }} ia-btn--sm" type="submit">Bring in</button></form>
        @endif
      </div>
    @endif
  </div>

  {{-- 5 --}}
  <div class="cpx-st {{ $cls('switch') }}"><span class="n">{{ $num('switch') }}</span>
    <div class="b"><div class="t">Switch over</div>
      <div class="d">
        @if($by['switch']['done']) Switched over {{ $pc->switched_at?->diffForHumans() }}. Your Connected POS is Reading Only: nothing is sent to it, and Intake owns price and stock. Disconnect when you no longer need to look anything up.
        @elseif($switching) Reading your Connected POS one last time: <b data-live="state.items_seen">{{ number_format($ss['items_seen'] ?? 0) }}</b> products. It becomes Reading Only as soon as this finishes.
        @else Intake reads your Connected POS one last time, so linked items start with its final prices and counts. Then it becomes Reading Only: nothing more is sent to it, and Intake owns price and stock. @endif
      </div></div>
    @if(! $by['switch']['done'] && ! $switching)
      <div class="ctl">
        @if($by['read']['done'])
          <form method="POST" action="{{ route('tenant.cpos.switch_over') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit"
            data-confirm="Switch over to Intake? Your Connected POS is read one last time, then becomes Reading Only: online orders, refunds and items stop being sent to it, and its prices and counts stop replacing Intake's.{{ $it['waiting'] ? ' ' . number_format($it['waiting']) . ' products are still only in your Connected POS; you can still import them afterwards.' : '' }}{{ $waitingSends ? ' ' . number_format($waitingSends) . ' sends are still waiting and must finish first.' : '' }} You can go back to 2 Way Data under Settings.">Switch over</button></form>
        @else
          <button class="ia-btn ia-btn--secondary ia-btn--sm" type="button" disabled title="Read your catalog first">Switch over</button>
        @endif
      </div>
    @elseif($switching)
      <div class="ctl"><form method="POST" action="{{ route('tenant.cpos.read_stop') }}">@csrf<button class="ia-btn ia-btn--secondary ia-btn--sm" type="submit" data-confirm="Stop the last read? You stay on 2 Way Data, and can switch over again when you're ready.">Stop</button></form></div>
    @endif
  </div>
</div>
