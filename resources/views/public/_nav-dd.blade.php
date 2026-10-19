{{--
  MARKER-SHOP-MENU-GROUPS: one menu group (App\Support\ShopNav::bar).
  $mode 'bar' = the desktop dropdown; 'acc' = a row in a phone menu that
  opens in place. $g = ['key','title','feature'|null,'items'=>[TenantNavItem]].
--}}
@php
  $ddLink = fn ($i) => $i->url ?? '/';
  $ddTab  = fn ($i) => ! empty($i->open_in_new_tab) ? ' target="_blank" rel="noopener"' : '';
  $ddF    = $g['feature'] ?? null;
@endphp
@if(($mode ?? 'bar') === 'bar')
  <div class="p-dd" data-dd>
    <button type="button" class="p-nav-link p-dd-btn" aria-expanded="false" aria-haspopup="true">{{ $g['title'] }}<svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="m3 4.5 3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button>
    <div class="p-dd-panel" role="menu">
      <div class="p-dd-items{{ count($g['items']) > 3 ? ' is-two' : '' }}">
        @foreach($g['items'] as $i)
          <a href="{{ $ddLink($i) }}" class="p-dd-item" role="menuitem"{!! $ddTab($i) !!}>
            @if(($i->icon ?? '') !== '' && ($ddSvg = \App\Support\ShopNav::iconSvg($i->icon)) !== '')<span class="p-dd-ic">{!! $ddSvg !!}</span>@endif
            <span><b>{{ $i->label }}</b>@if(($i->description ?? '') !== '')<small>{{ $i->description }}</small>@endif</span>
          </a>
        @endforeach
      </div>
      @if($ddF)
        <a href="{{ $ddF['url'] }}" class="p-dd-feat" role="menuitem">
          <span class="p-dd-ic">{!! \App\Support\ShopNav::iconSvg($ddF['icon']) !!}</span>
          <b>{{ $ddF['label'] }}</b>
          @if(($ddF['desc'] ?? '') !== '')<small>{{ $ddF['desc'] }}</small>@endif
          <i aria-hidden="true">→</i>
        </a>
      @endif
    </div>
  </div>
@else
  <details class="p-mg">
    <summary>{{ $g['title'] }}<i aria-hidden="true">+</i></summary>
    <div class="p-mg-in">
      @foreach($g['items'] as $i)
        <a href="{{ $ddLink($i) }}" class="p-mg-item"{!! $ddTab($i) !!} @if(!empty($closeFull)) onclick="closeMobileNav()" @endif>
          @if(($i->icon ?? '') !== '' && ($ddSvg = \App\Support\ShopNav::iconSvg($i->icon)) !== '')<span class="p-dd-ic">{!! $ddSvg !!}</span>@endif
          <span><b>{{ $i->label }}</b>@if(($i->description ?? '') !== '')<small>{{ $i->description }}</small>@endif</span>
        </a>
      @endforeach
      @if($ddF)
        <a href="{{ $ddF['url'] }}" class="p-mg-item p-mg-feat" @if(!empty($closeFull)) onclick="closeMobileNav()" @endif><span><b>{{ $ddF['label'] }} →</b>@if(($ddF['desc'] ?? '') !== '')<small>{{ $ddF['desc'] }}</small>@endif</span></a>
      @endif
    </div>
  </details>
@endif
