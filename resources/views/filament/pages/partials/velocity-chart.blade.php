{{-- MARKER-VELOCITY-CHART: a sparkline with a hover read-out. Params: pts [{f,v,l}], w, h, initial (server-drawn points). --}}
<div x-data="vlChart(@js($pts), {{ $w }}, {{ $h }})" class="vl-chart" style="width:{{ $w }}px" @mousemove="move($event)" @mouseleave="leave()">
  <svg width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" style="display:block">
    <polyline fill="none" stroke="#8b5cf6" stroke-width="1.6" points="{{ $initial }}" :points="path"/>
    <line x-show="tip" x-cloak :x1="tip ? tip.x * {{ $w }} / 100 : 0" :x2="tip ? tip.x * {{ $w }} / 100 : 0" y1="0" y2="{{ $h }}" stroke="currentColor" stroke-opacity=".35"/>
  </svg>
  <div class="vl-tip" x-show="tip" x-cloak :style="tip ? `left:${tip.px}px;top:${tip.py}px` : ''"><b x-text="tip ? tip.v.toLocaleString() + ' in stock' : ''"></b><span x-text="tip ? tip.l : ''"></span></div>
</div>
