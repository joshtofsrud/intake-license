{{--
  MARKER-SHOP-PORT4: Features accordion on shop sites. Port of
  marketing/sections/features_accordion: one row of slim, coloured panels, one
  open at a time; auto-advances until a visitor clicks (hover pauses). Phones:
  panels stack and open downward. Each panel: title, heading, body, link, its
  own colour + accent, and a picture (or nothing). intake.works' animated
  product screens are about Intake itself, so shop sites offer Picture/Nothing;
  a panel's text colour is picked to read on that panel's own colour.
--}}
@php
  $c   = $c ?? [];
  $sh  = \App\Support\ShopSectionShell::make($c, $section, 'sfa');
  $faId = $sh['id'];
  $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) ? strtolower(trim($v)) : null;
  $panels = $c['panels'] ?? [];
  if (is_string($panels)) { $d = json_decode($panels, true); $panels = is_array($d) ? $d : []; }
  $panels = array_values(array_filter(is_array($panels) ? $panels : [], fn ($p) => is_array($p) && trim((string) ($p['title'] ?? '')) !== ''));
  $safe   = fn ($u) => preg_match('#^(https?://|/|\#|mailto:|tel:)#i', trim((string) $u)) ? trim((string) $u) : '';
  $n      = count($panels);
  $start  = $n ? max(0, min($n - 1, (int) ($c['start_panel'] ?? 1) - 1)) : 0;
  $auto   = ! in_array((string) ($c['autoplay'] ?? '1'), ['0', 'false', ''], true);
  $secs   = max(3, min(30, (int) ($c['autoplay_seconds'] ?? 7)));
  $height = ['m' => 500, 'l' => 560, 'xl' => 640][$c['panel_height'] ?? 'l'] ?? 560;
  $pad    = fn ($i) => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
@endphp
<style>
  {!! $sh['css'] !!}
  .{{ $faId }} .fa-wrap{max-width:calc(var(--p-page-max, 1192px) + 128px);margin:0 auto;padding:0 clamp(20px,5vw,64px)}
  .{{ $faId }} .fa-head{max-width:760px;margin-bottom:34px}
  .{{ $faId }} .fa-eyebrow{font-family:ui-monospace,monospace;font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:{{ $sh['accent'] }};margin-bottom:14px}
  .{{ $faId }} .fa-head h2{font-size:clamp(26px,3.6vw,44px);font-weight:var(--p-heading-weight,600);text-transform:var(--p-heading-transform,none);line-height:1.1;letter-spacing:-.02em;margin:0 0 12px}
  .{{ $faId }} .fa-head p{opacity:.7;font-size:18px;line-height:1.6;margin:0}
  .{{ $faId }} .acc{display:flex;gap:10px;height:{{ $height }}px}
  .{{ $faId }} .acc-p{position:relative;flex:0 0 66px;border-radius:22px;overflow:hidden;cursor:pointer;outline:none;color:var(--pt);
    background:radial-gradient(140% 90% at 50% 110%,color-mix(in srgb,var(--pa) 18%,transparent),transparent 60%),var(--pb);
    border:1px solid color-mix(in srgb,var(--pt) 8%,transparent);transition:flex-basis .7s cubic-bezier(.65,0,.2,1),flex-grow .7s cubic-bezier(.65,0,.2,1),filter .3s}
  .{{ $faId }} .acc-p:not(.is-on):hover{filter:brightness(1.12)}
  .{{ $faId }} .acc-p:focus-visible{box-shadow:0 0 0 2px {{ $sh['accent'] }}}
  .{{ $faId }} .acc-p.is-on{flex:1 1 0;cursor:default;background:radial-gradient(90% 120% at 100% 0%,color-mix(in srgb,var(--pa) 22%,transparent),transparent 55%),linear-gradient(160deg,var(--pb),color-mix(in srgb,var(--pb) 70%,#000) 85%)}
  .{{ $faId }} .acc-label{position:absolute;inset:0;display:flex;flex-direction:column-reverse;align-items:center;justify-content:center;gap:16px;padding:24px 0;transition:opacity .3s}
  .{{ $faId }} .acc-t{writing-mode:vertical-rl;transform:rotate(180deg);font-size:24px;font-weight:600;letter-spacing:-.02em;white-space:nowrap}
  .{{ $faId }} .acc-n{writing-mode:vertical-rl;transform:rotate(180deg);font-size:11px;opacity:.55;font-variant-numeric:tabular-nums}
  .{{ $faId }} .acc-p.is-on .acc-label{opacity:0;pointer-events:none}
  .{{ $faId }} .acc-body{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:space-between;gap:20px;padding:64px 36px 56px;opacity:0;visibility:hidden;transition:opacity .25s}
  .{{ $faId }} .acc-p.is-on .acc-body{opacity:1;visibility:visible;transition:opacity .45s .35s}
  .{{ $faId }} .acc-k{position:absolute;top:28px;left:36px;font-size:11px;letter-spacing:.12em;font-weight:600;opacity:.65;text-transform:uppercase}
  .{{ $faId }} .acc-copy h3{font-size:clamp(28px,3vw,40px);line-height:1.05;font-weight:700;letter-spacing:-.035em;margin:0 0 14px}
  .{{ $faId }} .acc-copy p{opacity:.78;font-size:16px;line-height:1.6;margin:0 0 18px;max-width:420px}
  .{{ $faId }} .acc-link{font-size:14px;font-weight:600;color:var(--pa);text-decoration:none}
  .{{ $faId }} .acc-link span{display:inline-block;transition:transform .2s}
  .{{ $faId }} .acc-link:hover span{transform:translate(2px,-2px)}
  .{{ $faId }} .acc-copy>*,.{{ $faId }} .acc-vis{transform:translateY(14px);opacity:0;transition:transform .5s cubic-bezier(.2,.8,.2,1),opacity .5s}
  .{{ $faId }} .acc-p.is-on .acc-copy>*,.{{ $faId }} .acc-p.is-on .acc-vis{transform:none;opacity:1}
  .{{ $faId }} .acc-p.is-on .acc-copy h3{transition-delay:.4s}.{{ $faId }} .acc-p.is-on .acc-copy p{transition-delay:.48s}.{{ $faId }} .acc-p.is-on .acc-link{transition-delay:.56s}.{{ $faId }} .acc-p.is-on .acc-vis{transition-delay:.5s}
  .{{ $faId }} .acc-p.is-on .acc-copy p{opacity:.78}
  .{{ $faId }} .acc-vis{width:min(100%,620px);align-self:flex-end}
  .{{ $faId }} .acc-vis img{display:block;width:100%;max-height:{{ (int) round($height * .52) }}px;object-fit:cover;border-radius:12px;box-shadow:0 30px 60px -20px rgba(0,0,0,.6)}
  .{{ $faId }} .acc-prog{position:absolute;left:36px;right:36px;bottom:22px;display:flex;align-items:center;gap:14px;font-size:11px;opacity:.6;font-variant-numeric:tabular-nums}
  .{{ $faId }} .acc-prog i{flex:1;height:2px;background:color-mix(in srgb,var(--pt) 15%,transparent);border-radius:2px;overflow:hidden}
  .{{ $faId }} .acc-prog b{display:block;height:100%;width:0;background:var(--pa)}
  .{{ $faId }} .acc-p.is-on.is-auto .acc-prog b{animation:{{ $faId }}prog {{ $secs }}s linear forwards}
  @keyframes {{ $faId }}prog{to{width:100%}}
  .{{ $faId }} .acc:hover .acc-p.is-on.is-auto .acc-prog b{animation-play-state:paused}
  @media (max-width:980px){.{{ $faId }} .acc-vis{align-self:stretch}}
  @media (max-width:760px){
    .{{ $faId }} .acc{flex-direction:column;height:auto;gap:10px}
    .{{ $faId }} .acc-p{flex:none;height:64px;transition:height .6s cubic-bezier(.65,0,.2,1)}
    .{{ $faId }} .acc-p.is-on{flex:none;height:480px}
    .{{ $faId }} .acc-label{flex-direction:row;justify-content:flex-start;padding:0 22px}
    .{{ $faId }} .acc-t,.{{ $faId }} .acc-n{writing-mode:horizontal-tb;transform:none}
    .{{ $faId }} .acc-t{font-size:18px}
    .{{ $faId }} .acc-body{padding:56px 20px 50px}
    .{{ $faId }} .acc-k{left:20px;top:22px}.{{ $faId }} .acc-prog{left:20px;right:20px}
  }
  @media (prefers-reduced-motion:reduce){.{{ $faId }} .acc *{transition:none!important;animation:none!important}}
</style>
<section {!! $sh['attrs'] !!}>
<div class="fa-wrap">
  @if(trim((string) ($c['eyebrow'] ?? '')) !== '' || $sh['heading'] !== '' || trim((string) ($c['subheading'] ?? '')) !== '')
    <div class="fa-head">
      @if(trim((string) ($c['eyebrow'] ?? '')) !== '')<div class="fa-eyebrow">{{ $c['eyebrow'] }}</div>@endif
      @if($sh['heading'] !== '')<h2>{!! $sh['heading'] !!}</h2>@endif
      @if(trim((string) ($c['subheading'] ?? '')) !== '')<p>{{ $c['subheading'] }}</p>@endif
    </div>
  @endif

  @if($n > 0)
  <div class="acc" data-fa data-auto="{{ $auto ? 1 : 0 }}" data-dur="{{ $secs * 1000 }}">
    @foreach($panels as $i => $p)
      @php
        $pb  = $hex($p['color'] ?? null) ?: '#1f3b2b';
        $pa  = $hex($p['accent'] ?? null) ?: '#BEF264';
        $pt  = \App\Support\ColorHelper::accentTextColor($pb);
        $img = trim((string) ($p['image'] ?? ''));
        $vis = ($p['visual'] ?? '') === 'image' && preg_match('#^(https?://|/)#i', $img) ? 'image' : 'none';
        $lnk = $safe($p['link_url'] ?? '');
        $lbl = trim((string) ($p['link_label'] ?? ''));
      @endphp
      <div class="acc-p{{ $i === $start ? ' is-on' : '' }}" style="--pb:{{ $pb }};--pa:{{ $pa }};--pt:{{ $pt }}" role="button" tabindex="0" aria-expanded="{{ $i === $start ? 'true' : 'false' }}" aria-label="{{ $p['title'] }}">
        <div class="acc-label"><span class="acc-n">{{ $pad($i) }}</span><span class="acc-t">{{ $p['title'] }}</span></div>
        <div class="acc-body">
          @if($vis === 'image')
            <div class="acc-vis"><img src="{{ $img }}" alt="{{ $p['image_alt'] ?? $p['title'] }}" loading="lazy"></div>
          @else
            <div></div>
          @endif
          <div class="acc-copy">
            <div class="acc-k">{{ $pad($i) }} / {{ $p['title'] }}</div>
            @if(trim((string) ($p['heading'] ?? '')) !== '')<h3>{{ $p['heading'] }}</h3>@endif
            @if(trim((string) ($p['body'] ?? '')) !== '')<p>{{ $p['body'] }}</p>@endif
            @if($lnk !== '' && $lbl !== '')<a class="acc-link" href="{{ $lnk }}">{{ $lbl }} <span>↗</span></a>@endif
          </div>
          <div class="acc-prog"><span>{{ $pad($i) }} / {{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }}</span><i><b></b></i></div>
        </div>
      </div>
    @endforeach
  </div>
  @endif
</div>
<script>
(function(){
  var sec=document.querySelector('.{{ $faId }}'); if(!sec||sec.dataset.faReady)return; sec.dataset.faReady='1';
  var acc=sec.querySelector('[data-fa]'); if(!acc)return;
  var ps=[].slice.call(acc.querySelectorAll('.acc-p')),auto=acc.dataset.auto==='1',timer=null,DUR=parseInt(acc.dataset.dur,10)||7000,hov=null;
  var fine=matchMedia('(hover:hover) and (min-width:761px)').matches, reduce=matchMedia('(prefers-reduced-motion: reduce)').matches, seen=false;
  function open(p){
    if(p.classList.contains('is-on'))return;
    ps.forEach(function(x){x.classList.remove('is-on','is-auto');x.setAttribute('aria-expanded','false')});
    p.classList.add('is-on');p.setAttribute('aria-expanded','true');
    if(auto){void p.offsetWidth;p.classList.add('is-auto')}
  }
  function tick(){clearTimeout(timer);if(!auto)return;timer=setTimeout(function(){if(acc.matches(':hover')){tick();return}var i=ps.findIndex(function(x){return x.classList.contains('is-on')});open(ps[(i+1)%ps.length]);tick()},DUR)}
  function stop(){auto=false;clearTimeout(timer);ps.forEach(function(x){x.classList.remove('is-auto')})}
  ps.forEach(function(p){
    p.addEventListener('click',function(e){if(e.target.closest('a'))return;stop();open(p)});
    p.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){if(e.target.closest('a'))return;e.preventDefault();stop();open(p)}
      if(e.key==='ArrowRight'||e.key==='ArrowDown'){e.preventDefault();var n=ps[(ps.indexOf(p)+1)%ps.length];n.focus();stop();open(n)}
      if(e.key==='ArrowLeft'||e.key==='ArrowUp'){e.preventDefault();var q=ps[(ps.indexOf(p)-1+ps.length)%ps.length];q.focus();stop();open(q)}});
    if(fine)p.addEventListener('mouseenter',function(){clearTimeout(hov);hov=setTimeout(function(){stop();open(p)},260)});
    if(fine)p.addEventListener('mouseleave',function(){clearTimeout(hov)});
  });
  function begin(){if(seen)return;seen=true;var p=acc.querySelector('.acc-p.is-on')||ps[0];if(p){if(!p.classList.contains('is-on'))open(p);if(auto&&!reduce){p.classList.add('is-auto');tick()}}}
  if('IntersectionObserver' in window){new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){o.disconnect();begin()}})},{threshold:.4}).observe(acc)}else{begin()}
})();
</script>
</section>
