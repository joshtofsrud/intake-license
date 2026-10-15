@php $bgId = 'mkbg-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10); @endphp
@include('marketing.sections._section_bg', ['bgId' => $bgId])
{{--
  MARKER-FEATURES-ACCORDION: one row of slim, coloured panels; one is open at a time.
  Panels auto-advance until a visitor clicks one (hovering pauses). Desktop: click or
  hover a slim panel to open it. Phones: panels stack and open downward.
  Each panel: title, heading, body, link_label/link_url, colour (base) + accent, and a
  visual: one of the built-in live mini-screens, a picture, or nothing.
--}}
@php
    $faId   = 'fa-' . substr(md5((string) ($section->id ?? uniqid())), 0, 10);
    $hex    = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($v)) ? trim($v) : null;
    $accent = $hex($c['accent_color'] ?? null) ?: 'var(--mk-accent)';
    $panels = $c['panels'] ?? [];
    if (is_string($panels)) { $d = json_decode($panels, true); $panels = is_array($d) ? $d : []; }
    $panels = array_values(array_filter(is_array($panels) ? $panels : [], fn ($p) => is_array($p) && trim((string) ($p['title'] ?? '')) !== ''));
    $safe   = fn ($u) => preg_match('#^(https?://|/|\#|mailto:|tel:)#i', trim((string) $u)) ? trim((string) $u) : '';
    $heading = e($c['heading'] ?? '');
    $aw = trim((string) ($c['accent_words'] ?? ''));
    if ($aw !== '' && stripos($heading, e($aw)) !== false) $heading = preg_replace('/' . preg_quote(e($aw), '/') . '/i', '<span style="color:' . $accent . '">$0</span>', $heading, 1);
    $n      = count($panels);
    $start  = max(0, min($n - 1, (int) ($c['start_panel'] ?? 3) - 1));
    $auto   = ! in_array((string) ($c['autoplay'] ?? '1'), ['0', 'false', ''], true);
    $secs   = max(3, min(30, (int) ($c['autoplay_seconds'] ?? 7)));
    $height = ['m' => 500, 'l' => 560, 'xl' => 640][$c['panel_height'] ?? 'l'] ?? 560;
    $cta    = trim((string) ($c['cta_label'] ?? ''));
    $ctaUrl = $safe($c['cta_url'] ?? '');
    $pad    = fn ($i) => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
@endphp
{{-- anchor, custom classes and hide-on-phone/desktop are applied by the page's section wrapper --}}
<section class="{{ $padding }} {{ $bgId }} {{ $faId }}" @if(!empty($inlineStyle ?? '')) style="{{ $inlineStyle }}" @endif>
<style>
  .{{ $faId }} .fa-wrap{max-width:1320px;margin:0 auto;padding:0 var(--mk-gutter,clamp(20px,5vw,64px))}
  .{{ $faId }} .fa-head{max-width:760px;margin-bottom:34px}
  .{{ $faId }} .fa-head p{color:rgba(255,255,255,.62);font-size:18px;line-height:1.6}
  .{{ $faId }} .acc{display:flex;gap:10px;height:{{ $height }}px}
  .{{ $faId }} .acc-p{position:relative;flex:0 0 66px;border-radius:22px;overflow:hidden;cursor:pointer;outline:none;
    background:radial-gradient(140% 90% at 50% 110%,color-mix(in srgb,var(--pa) 18%,transparent),transparent 60%),var(--pb);
    border:1px solid rgba(255,255,255,.06);transition:flex-basis .7s cubic-bezier(.65,0,.2,1),flex-grow .7s cubic-bezier(.65,0,.2,1),filter .3s}
  .{{ $faId }} .acc-p:not(.is-on):hover{filter:brightness(1.18)}
  .{{ $faId }} .acc-p:focus-visible{box-shadow:0 0 0 2px {{ $accent }}}
  .{{ $faId }} .acc-p.is-on{flex:1 1 0;cursor:default;background:radial-gradient(90% 120% at 100% 0%,color-mix(in srgb,var(--pa) 22%,transparent),transparent 55%),linear-gradient(160deg,color-mix(in srgb,var(--pb) 85%,#000),#0e0e0e 75%)}
  .{{ $faId }} .acc-label{position:absolute;inset:0;display:flex;flex-direction:column-reverse;align-items:center;justify-content:center;gap:16px;padding:24px 0;transition:opacity .3s}
  .{{ $faId }} .acc-t{writing-mode:vertical-rl;transform:rotate(180deg);font-size:24px;font-weight:600;letter-spacing:-.02em;white-space:nowrap;color:#fff}
  .{{ $faId }} .acc-n{writing-mode:vertical-rl;transform:rotate(180deg);font-size:11px;color:rgba(255,255,255,.5);font-variant-numeric:tabular-nums}
  .{{ $faId }} .acc-p.is-on .acc-label{opacity:0;pointer-events:none}
  .{{ $faId }} .acc-body{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:space-between;gap:20px;padding:64px 36px 56px;opacity:0;visibility:hidden;transition:opacity .25s}
  .{{ $faId }} .acc-p.is-on .acc-body{opacity:1;visibility:visible;transition:opacity .45s .35s}
  .{{ $faId }} .acc-k{position:absolute;top:28px;left:36px;font-size:11px;letter-spacing:.12em;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase}
  .{{ $faId }} .acc-copy h3{font-size:clamp(28px,3vw,40px);line-height:1.05;font-weight:700;letter-spacing:-.035em;margin:0 0 14px}
  .{{ $faId }} .acc-copy p{color:rgba(255,255,255,.72);font-size:16px;line-height:1.6;margin:0 0 18px;max-width:420px}
  .{{ $faId }} .acc-link{font-size:14px;font-weight:600;color:var(--pa);text-decoration:none}
  .{{ $faId }} .acc-link span{display:inline-block;transition:transform .2s}
  .{{ $faId }} .acc-link:hover span{transform:translate(2px,-2px)}
  .{{ $faId }} .acc-copy>*,.{{ $faId }} .acc-vis{transform:translateY(14px);opacity:0;transition:transform .5s cubic-bezier(.2,.8,.2,1),opacity .5s}
  .{{ $faId }} .acc-p.is-on .acc-copy>*,.{{ $faId }} .acc-p.is-on .acc-vis{transform:none;opacity:1}
  .{{ $faId }} .acc-p.is-on .acc-copy h3{transition-delay:.4s}.{{ $faId }} .acc-p.is-on .acc-copy p{transition-delay:.48s}.{{ $faId }} .acc-p.is-on .acc-link{transition-delay:.56s}.{{ $faId }} .acc-p.is-on .acc-vis{transition-delay:.5s}
  .{{ $faId }} .acc-vis{width:min(100%,560px);align-self:flex-end}
  .{{ $faId }} .acc-vis.is-img{width:min(100%,620px)}
  .{{ $faId }} .acc-vis img{display:block;width:100%;max-height:{{ (int) round($height * .52) }}px;object-fit:cover;border-radius:12px;box-shadow:0 30px 60px -20px rgba(0,0,0,.6)}
  .{{ $faId }} .acc-prog{position:absolute;left:36px;right:36px;bottom:22px;display:flex;align-items:center;gap:14px;font-size:11px;color:rgba(255,255,255,.5);font-variant-numeric:tabular-nums}
  .{{ $faId }} .acc-prog i{flex:1;height:2px;background:rgba(255,255,255,.12);border-radius:2px;overflow:hidden}
  .{{ $faId }} .acc-prog b{display:block;height:100%;width:0;background:var(--pa)}
  .{{ $faId }} .acc-p.is-on.is-auto .acc-prog b{animation:{{ $faId }}prog {{ $secs }}s linear forwards}
  @keyframes {{ $faId }}prog{to{width:100%}}
  .{{ $faId }} .acc:hover .acc-p.is-on.is-auto .acc-prog b{animation-play-state:paused}
  /* live mini-screens */
  .{{ $faId }} .vis{position:relative;border-radius:12px;background:rgba(10,10,10,.82);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.07);box-shadow:0 30px 60px -20px rgba(0,0,0,.6);min-height:170px;padding:18px;font-size:13px;color:rgba(255,255,255,.8);line-height:1.5}
  .{{ $faId }} .row{display:flex;align-items:center;justify-content:space-between;gap:8px}
  .{{ $faId }} .mut{color:rgba(255,255,255,.42)}
  .{{ $faId }} .pill{font-size:10.5px;font-weight:600;padding:3px 8px;border-radius:999px;background:rgba(255,255,255,.07);color:rgba(255,255,255,.75);white-space:nowrap}
  .{{ $faId }} .pill.go{background:{{ $accent }};color:#0a0a0a}
  .{{ $faId }} .v-flow .track{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:12px}
  .{{ $faId }} .v-flow .st{font-size:11px;text-align:center;padding:6px 4px;border-radius:7px;background:rgba(255,255,255,.05);color:rgba(255,255,255,.5);transition:all .35s}
  .{{ $faId }} .v-flow .st.done{color:rgba(255,255,255,.8)}
  .{{ $faId }} .v-flow .st.now{background:{{ $accent }};color:#0a0a0a;font-weight:600}
  .{{ $faId }} .v-flow .lane{position:relative;height:52px}
  .{{ $faId }} .v-flow .job{position:absolute;top:0;left:0;width:calc(20% - 6px);background:#1b1b1b;border:1px solid rgba(255,255,255,.1);border-radius:9px;padding:7px 9px;transition:left .6s cubic-bezier(.6,0,.2,1)}
  .{{ $faId }} .v-flow .job b{display:block;font-size:11.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .{{ $faId }} .v-flow .job span{font-size:10.5px;color:rgba(255,255,255,.45)}
  .{{ $faId }} .v-flow .src{margin-top:8px;font-size:10.5px;color:rgba(255,255,255,.4)}
  .{{ $faId }} .v-wo .ln{display:flex;justify-content:space-between;padding:5px 0;border-top:1px dashed rgba(255,255,255,.08);opacity:0;transform:translateY(4px);transition:all .35s}
  .{{ $faId }} .v-wo .ln.in{opacity:1;transform:none}
  .{{ $faId }} .v-wo .note{margin-top:8px;font-size:11px;color:rgba(255,255,255,.55);background:rgba(255,255,255,.04);border-radius:7px;padding:6px 8px;opacity:0;transition:opacity .4s}
  .{{ $faId }} .v-wo .note.in{opacity:1}
  .{{ $faId }} .v-pos .ln{display:flex;justify-content:space-between;padding:4px 0}
  .{{ $faId }} .v-pos .tot{border-top:1px solid rgba(255,255,255,.1);margin-top:6px;padding-top:7px;font-weight:700;font-size:14px}
  .{{ $faId }} .v-pos .stamp{position:absolute;right:12px;bottom:12px;transform:rotate(8deg) scale(.6);opacity:0;border:2px solid {{ $accent }};color:{{ $accent }};font-weight:800;font-size:13px;letter-spacing:.08em;padding:2px 8px;border-radius:6px;transition:all .3s cubic-bezier(.3,1.6,.5,1)}
  .{{ $faId }} .v-pos .stamp.in{opacity:1;transform:rotate(8deg) scale(1)}
  .{{ $faId }} .v-pos .stock{margin-top:8px;font-size:10.5px;color:rgba(255,255,255,.45)}
  .{{ $faId }} .v-pos .stock b{color:{{ $accent }};font-weight:600}
  .{{ $faId }} .v-cust .av{width:30px;height:30px;border-radius:50%;background:color-mix(in srgb,{{ $accent }} 20%,#1b1b1b);color:{{ $accent }};display:grid;place-items:center;font-weight:700;font-size:12px}
  .{{ $faId }} .v-cust .tl{display:flex;gap:5px;align-items:flex-end;height:38px;margin:12px 0 6px}
  .{{ $faId }} .v-cust .tl i{flex:1;border-radius:3px 3px 0 0;background:rgba(255,255,255,.1);height:0;transition:height .5s cubic-bezier(.3,1.3,.5,1)}
  .{{ $faId }} .v-cust .tl i.hi{background:{{ $accent }}}
  .{{ $faId }} .v-wb .p{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-top:1px solid rgba(255,255,255,.05)}
  .{{ $faId }} .v-wb .p:first-of-type{border-top:0}
  .{{ $faId }} .v-wb .chk{font-size:10.5px;font-weight:600;color:rgba(255,255,255,.4);transition:color .3s}
  .{{ $faId }} .v-wb .chk.sent{color:{{ $accent }}}
  .{{ $faId }} .v-site .frame{border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,.08)}
  .{{ $faId }} .v-site .bar{height:16px;background:#1a1a1a;display:flex;align-items:center;gap:4px;padding:0 7px}
  .{{ $faId }} .v-site .bar i{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.2)}
  .{{ $faId }} .v-site .bar b{margin-left:8px;font-weight:400;font-size:9.5px;color:rgba(255,255,255,.45)}
  .{{ $faId }} .v-site .pg{padding:12px;background:var(--sb,#16213a);transition:background .5s}
  .{{ $faId }} .v-site .pg h5{font-size:15px;font-weight:800;letter-spacing:-.02em;color:var(--sf,#fff);transition:color .5s;margin:0}
  .{{ $faId }} .v-site .pg .btn{display:inline-block;margin-top:8px;font-size:10px;font-weight:700;padding:4px 10px;border-radius:5px;background:var(--sa,#A9CBE8);color:var(--sb,#16213a);transition:all .5s}
  .{{ $faId }} .v-site .sw{display:flex;gap:6px;margin-top:10px}
  .{{ $faId }} .v-site .sw i{width:16px;height:16px;border-radius:50%;border:2px solid transparent;transition:border-color .3s}
  .{{ $faId }} .v-site .sw i.on{border-color:#fff}
  .{{ $faId }} .v-grow{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .{{ $faId }} .v-grow .ad{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.04);border-radius:8px;padding:8px 10px}
  .{{ $faId }} .v-grow .sw2{width:26px;height:15px;border-radius:999px;background:rgba(255,255,255,.14);position:relative;transition:background .3s}
  .{{ $faId }} .v-grow .sw2::after{content:"";position:absolute;top:2px;left:2px;width:11px;height:11px;border-radius:50%;background:#fff;transition:left .3s}
  .{{ $faId }} .v-grow .ad.on .sw2{background:{{ $accent }}}
  .{{ $faId }} .v-grow .ad.on .sw2::after{left:13px;background:#0a0a0a}
  /* glow-border call to action */
  @property --fa-gb{syntax:'<angle>';initial-value:0deg;inherits:false}
  .{{ $faId }} .fa-cta{display:flex;justify-content:center;margin-top:30px}
  .{{ $faId }} .fa-glow{position:relative;isolation:isolate;display:inline-flex;align-items:center;gap:8px;background:#0b0b0b;color:#fff;border-radius:999px;padding:12px 22px;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap}
  .{{ $faId }} .fa-glow::before{content:"";position:absolute;inset:-1.5px;border-radius:inherit;z-index:-1;padding:1.5px;background:conic-gradient(from var(--fa-gb),transparent 0 62%,color-mix(in srgb,{{ $accent }} 40%,transparent) 72%,{{ $accent }} 86%,#fff 90%,transparent 94%);-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:{{ $faId }}spin 2.6s linear infinite}
  .{{ $faId }} .fa-glow::after{content:"";position:absolute;inset:-10px;border-radius:inherit;z-index:-2;filter:blur(14px);opacity:.55;background:conic-gradient(from var(--fa-gb),transparent 0 70%,{{ $accent }} 86%,transparent 96%);animation:{{ $faId }}spin 2.6s linear infinite;transition:opacity .25s}
  .{{ $faId }} .fa-glow:hover::after{opacity:.9}
  .{{ $faId }} .fa-glow svg{width:15px;height:15px;color:{{ $accent }}}
  @keyframes {{ $faId }}spin{to{--fa-gb:360deg}}
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
  @media (prefers-reduced-motion:reduce){.{{ $faId }} .acc *{transition:none!important;animation:none!important}.{{ $faId }} .fa-glow::before,.{{ $faId }} .fa-glow::after{animation:none}}
</style>
<div class="fa-wrap">
  @if(trim((string) ($c['eyebrow'] ?? '')) !== '' || $heading !== '' || trim((string) ($c['subheading'] ?? '')) !== '')
    <div class="fa-head">
      @if(trim((string) ($c['eyebrow'] ?? '')) !== '')<div class="mk-eyebrow" style="color:{{ $accent }}">{{ $c['eyebrow'] }}</div>@endif
      @if($heading !== '')<h2 class="mk-section-title">{!! $heading !!}</h2>@endif
      @if(trim((string) ($c['subheading'] ?? '')) !== '')<p>{{ $c['subheading'] }}</p>@endif
    </div>
  @endif

  @if($n > 0)
  <div class="acc" data-fa data-auto="{{ $auto ? 1 : 0 }}" data-dur="{{ $secs * 1000 }}">
    @foreach($panels as $i => $p)
      @php
        $pb = $hex($p['color'] ?? null) ?: '#1f3b2b';
        $pa = $hex($p['accent'] ?? null) ?: '#BEF264';
        $vis = in_array($p['visual'] ?? '', ['flow','wo','pos','cust','wb','site','grow','image','none'], true) ? $p['visual'] : 'none';
        $img = trim((string) ($p['image'] ?? ''));
        if ($vis === 'image' && ! preg_match('#^(https?://|/)#i', $img)) $vis = 'none';
        $lnk = $safe($p['link_url'] ?? '');
        $lbl = trim((string) ($p['link_label'] ?? ''));
      @endphp
      <div class="acc-p{{ $i === $start ? ' is-on' : '' }}" style="--pb:{{ $pb }};--pa:{{ $pa }}" role="button" tabindex="0" aria-expanded="{{ $i === $start ? 'true' : 'false' }}" aria-label="{{ $p['title'] }}">
        <div class="acc-label"><span class="acc-n">{{ $pad($i) }}</span><span class="acc-t">{{ $p['title'] }}</span></div>
        <div class="acc-body">
          @if($vis === 'image')
            <div class="acc-vis is-img"><img src="{{ $img }}" alt="{{ $p['image_alt'] ?? $p['title'] }}" loading="lazy"></div>
          @elseif($vis === 'flow')
            <div class="acc-vis"><div class="vis v-flow" data-vis="flow"><div class="track"><div class="st">Booked</div><div class="st">Checked in</div><div class="st">In progress</div><div class="st">Ready</div><div class="st">Picked up</div></div><div class="lane"><div class="job"><b>Sam R.</b><span>Service · 10:30</span></div></div><div class="src">Booked online · 2 min ago</div></div></div>
          @elseif($vis === 'wo')
            <div class="acc-vis"><div class="vis v-wo" data-vis="wo"><div class="row" style="margin-bottom:8px"><b style="font-size:12.5px">WO-1042</b><span class="pill go" data-st>In progress</span></div><div class="ln"><span>Service labor</span><span>$85.00</span></div><div class="ln"><span>Part · 1</span><span>$34.00</span></div><div class="ln"><span>Part · 2</span><span>$12.50</span></div><div class="note">Staff note: customer approved extra part by text</div></div></div>
          @elseif($vis === 'pos')
            <div class="acc-vis"><div class="vis v-pos" data-vis="pos"><div class="stamp">PAID</div><div class="ln"><span>Item A</span><span>$49.00</span></div><div class="ln"><span>Item B × 2</span><span>$18.00</span></div><div class="ln tot"><span>Total</span><span data-tot>$67.00</span></div><div class="stock">Item A stock <b data-stk>6</b></div></div></div>
          @elseif($vis === 'cust')
            <div class="acc-vis"><div class="vis v-cust" data-vis="cust"><div class="row"><div style="display:flex;gap:9px;align-items:center"><div class="av">SR</div><div><b style="font-size:12.5px">Sam R.</b><div class="mut" style="font-size:10.5px">Customer since 2023</div></div></div><span class="pill">12 visits</span></div><div class="tl">@for($k = 0; $k < 12; $k++)<i></i>@endfor</div><div class="row mut" style="font-size:10.5px"><span>Last 12 months</span><span>Spent <b style="color:#fff" data-spend>$1,284</b></span></div></div></div>
          @elseif($vis === 'wb')
            <div class="acc-vis"><div class="vis v-wb" data-vis="wb"><div class="row mut" style="font-size:10.5px;margin-bottom:6px"><span>Not seen in 6+ months</span><span>3 customers</span></div><div class="p"><span>Jordan P. <span class="mut">· 7 mo</span></span><span class="chk">Queued</span></div><div class="p"><span>Alex M. <span class="mut">· 9 mo</span></span><span class="chk">Queued</span></div><div class="p"><span>Casey L. <span class="mut">· 11 mo</span></span><span class="chk">Queued</span></div></div></div>
          @elseif($vis === 'site')
            <div class="acc-vis"><div class="vis v-site" data-vis="site"><div class="frame"><div class="bar"><i></i><i></i><i></i><b>yourshop.com</b></div><div class="pg"><h5>Your Shop</h5><div style="font-size:10px;color:var(--sf,#fff);opacity:.7">Book a service in two minutes</div><span class="btn">Book now</span></div></div><div class="sw"><i style="background:#A9CBE8"></i><i style="background:#F3E36B"></i><i style="background:#E8604C"></i><i style="background:#BEF264"></i></div></div></div>
          @elseif($vis === 'grow')
            <div class="acc-vis"><div class="vis v-grow" data-vis="grow"><div class="ad"><span>Rentals</span><span class="sw2"></span></div><div class="ad"><span>Online store</span><span class="sw2"></span></div><div class="ad"><span>Staff scheduling</span><span class="sw2"></span></div><div class="ad"><span>Gift cards</span><span class="sw2"></span></div></div></div>
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

  @if($cta !== '' && $ctaUrl !== '')
    <div class="fa-cta"><a class="fa-glow" href="{{ $ctaUrl }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.8 5.6L19.5 9.5l-5.7 1.9L12 17l-1.8-5.6L4.5 9.5l5.7-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>{{ $cta }}</a></div>
  @endif
</div>
<script>
(function(){
  var sec=document.querySelector('.{{ $faId }}'); if(!sec||sec.dataset.faReady)return; sec.dataset.faReady='1';
  var acc=sec.querySelector('[data-fa]'); if(!acc)return;
  var ps=[].slice.call(acc.querySelectorAll('.acc-p')),auto=acc.dataset.auto==='1',timer=null,DUR=parseInt(acc.dataset.dur,10)||7000,hov=null;
  var fine=matchMedia('(hover:hover) and (min-width:761px)').matches, reduce=matchMedia('(prefers-reduced-motion: reduce)').matches, seen=false;
  function start(p){var v=p.querySelector('[data-vis]');if(v&&!v.dataset.go&&run[v.dataset.vis]){v.dataset.go=1;run[v.dataset.vis](v)}}
  function open(p){
    if(p.classList.contains('is-on'))return;
    ps.forEach(function(x){x.classList.remove('is-on','is-auto');x.setAttribute('aria-expanded','false')});
    p.classList.add('is-on');p.setAttribute('aria-expanded','true');
    if(seen)start(p);
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
  function loop(fn,ms){fn();if(!reduce)setInterval(fn,ms)}
  var run={
    flow:function(el){var st=el.querySelectorAll('.st'),job=el.querySelector('.job'),src=el.querySelector('.src'),i=0,lab=['Booked online · 2 min ago','Checked in at 10:28','Tech started · 11:05','Ready · text sent','Picked up · paid'];
      loop(function(){st.forEach(function(s,k){s.className='st'+(k<i?' done':'')+(k===i?' now':'')});job.style.left='calc('+(i*20)+'% + '+(i?1:0)+'px)';src.textContent=lab[i];i=(i+1)%5},1600)},
    wo:function(el){var ln=el.querySelectorAll('.ln'),n=el.querySelector('.note'),pill=el.querySelector('[data-st]'),i=0;
      loop(function(){if(i===0){ln.forEach(function(l){l.classList.remove('in')});n.classList.remove('in');pill.textContent='In progress'}
        if(i<3)ln[i].classList.add('in');else if(i===3)n.classList.add('in');else if(i===4)pill.textContent='Ready';i=(i+1)%6},900)},
    pos:function(el){var tot=el.querySelector('[data-tot]'),stk=el.querySelector('[data-stk]'),stamp=el.querySelector('.stamp'),i=0;
      loop(function(){if(i===0){stamp.classList.remove('in');stk.textContent='6';var v=0,end=67;var t=setInterval(function(){v=Math.min(end,v+4.2);tot.textContent='$'+v.toFixed(2);if(v>=end)clearInterval(t)},40)}
        if(i===2){stamp.classList.add('in');stk.textContent='5'}i=(i+1)%4},1100)},
    cust:function(el){var bars=el.querySelectorAll('.tl i'),sp=el.querySelector('[data-spend]'),h=[30,0,55,20,0,70,40,0,90,35,60,100];
      loop(function(){bars.forEach(function(b){b.style.height='0'});sp.textContent='$0';setTimeout(function(){bars.forEach(function(b,k){setTimeout(function(){b.style.height=h[k]+'%';b.classList.toggle('hi',k===11)},k*70)});var v=0,t=setInterval(function(){v+=38;sp.textContent='$'+Math.min(v,1284).toLocaleString();if(v>=1284)clearInterval(t)},30)},200)},5200)},
    wb:function(el){var c=el.querySelectorAll('.chk'),i=0;
      loop(function(){if(i===0)c.forEach(function(x){x.textContent='Queued';x.classList.remove('sent')});if(i>=1&&i<=3){c[i-1].textContent='Sent ✓';c[i-1].classList.add('sent')}if(i===5){c[1].textContent='Came back · $95'}i=(i+1)%8},800)},
    site:function(el){var pg=el.querySelector('.pg'),btn=el.querySelector('.btn'),sw=el.querySelectorAll('.sw i'),th=[['#16213a','#fff','#A9CBE8'],['#1f6b40','#F3E36B','#F3E36B'],['#F4D35E','#7a2a1d','#E8604C'],['#0c0c0c','#fff','#BEF264']],i=0;
      loop(function(){var t=th[i];pg.style.setProperty('--sb',t[0]);pg.style.setProperty('--sf',t[1]);pg.style.setProperty('--sa',t[2]);btn.style.color=t[0];sw.forEach(function(s,k){s.classList.toggle('on',k===i)});i=(i+1)%4},1700)},
    grow:function(el){var a=el.querySelectorAll('.ad'),i=0;
      loop(function(){if(i===0)a.forEach(function(x){x.classList.remove('on')});if(i>=1&&i<=4)a[i-1].classList.add('on');i=(i+1)%7},800)}
  };
  function begin(){if(seen)return;seen=true;var p=acc.querySelector('.acc-p.is-on')||ps[0];if(p){if(!p.classList.contains('is-on'))open(p);start(p);if(auto&&!reduce){p.classList.add('is-auto');tick()}}}
  if('IntersectionObserver' in window){new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){o.disconnect();begin()}})},{threshold:.4}).observe(acc)}else{begin()}
})();
</script>
</section>
