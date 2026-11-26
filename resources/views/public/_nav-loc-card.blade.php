{{--
  MARKER-SHOP-LOCATIONS: one location in the Locations menu (App\Support\ShopHours::cards).
  $L = the card, $lcParts = which parts show, $lcCompact = phone menu (hours on one line).
--}}
@php
  $lcHas  = fn ($p) => in_array($p, $lcParts ?? \App\Support\ShopHours::PARTS, true);
  $lcDir  = $lcHas('dir') && $L['dir_url'] !== '';
  $lcCall = $lcHas('call') && $L['tel'] !== '';
@endphp
<div class="p-loc">
  <div class="p-loc-top">
    <b>{{ $L['name'] }}</b>
    @if($lcHas('status') && $L['status'] !== '')
      <span class="p-loc-st{{ $L['open'] ? '' : ' closed' }}">{{ $L['status'] }}</span>
    @endif
  </div>
  @if($lcHas('hours') && $L['has_hours'])
    @if(! empty($lcCompact))
      <div class="p-hrs-sum">{{ $L['summary'] }}</div>
    @else
      <div class="p-hrs">
        @foreach($L['week'] as $lcD)
          <span class="{{ $lcD['today'] ? 'td' : '' }}">{{ $lcD['day'] }}</span><span class="{{ $lcD['today'] ? 'td' : '' }}">{{ $lcD['text'] }}</span>
        @endforeach
      </div>
    @endif
  @endif
  @if($lcHas('map') && $L['map_url'] !== '')
    <a class="p-map" href="{{ $L['dir_url'] }}" target="_blank" rel="noopener" aria-label="Map — {{ $L['name'] }}">
      <iframe data-src="{{ $L['map_url'] }}" title="Map — {{ $L['name'] }}" loading="lazy" tabindex="-1" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </a>
  @endif
  @if(($lcHas('addr') && $L['lines']) || ($lcHas('call') && $L['phone'] !== ''))
    <div class="p-addr">
      @if($lcHas('addr'))
        @foreach($L['lines'] as $lcLine)
          {{ $lcLine }}<br>
        @endforeach
      @endif
      @if($lcHas('call') && $L['phone'] !== '')
        {{ $L['phone'] }}
      @endif
    </div>
  @endif
  @if($lcDir || $lcCall)
    <div class="p-loc-acts">
      @if($lcDir)
        <a class="pri" href="{{ $L['dir_url'] }}" target="_blank" rel="noopener">Directions</a>
      @endif
      @if($lcCall)
        <a href="{{ $L['tel'] }}">Call</a>
      @endif
    </div>
  @endif
</div>
