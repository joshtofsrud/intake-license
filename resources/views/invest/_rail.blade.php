{{-- MARKER-INVEST-RAIL / MARKER-INVEST-RAILMENU
     $rail:     [ ['#anchor', 'Label', 'section-id'|null] ] — a third value means
                the item opens a collapsible section and carries a dot.
                An item ['menu', 'Label', null] renders the dropdown below.
     $railMenu: optional [ ['Group label', [ ['#anchor','Label','section-id'], … ] ] ] --}}
@php $railMenu = $railMenu ?? []; @endphp
<div class="rail"><div class="wrap">
  @foreach($rail as [$href, $label, $sec])
    @if($href === 'menu')
      @php $railMenuCount = collect($railMenu)->sum(fn ($g) => count($g[1])); @endphp
      <div class="rail-menu">
        <button type="button" data-rail-menu>
          {{ $label }} <span class="rail-menu-count">{{ $railMenuCount }}</span> <span class="rail-menu-caret"></span>
        </button>
        <div class="rail-pop">
          @foreach($railMenu as [$groupLabel, $items])
            @if($groupLabel)<div class="rail-pop-grp">{{ $groupLabel }}</div>@endif
            @foreach($items as [$mHref, $mLabel, $mSec])
              <a href="{{ $mHref }}"@if($mSec) data-sec="{{ $mSec }}"@endif>
                @if($mSec)<i></i>@endif{{ $mLabel }}
              </a>
            @endforeach
          @endforeach
        </div>
      </div>
    @else
      <a href="{{ $href }}"@if($sec) data-sec="{{ $sec }}"@endif @if($href === '#talk' || str_contains($href, '/book/') || str_contains($href, '/demo')) {{-- MARKER-INVEST-DEMO --}} style="color:var(--lime)"@endif>@if($sec)<i></i>@endif{{ $label }}</a>
    @endif
  @endforeach
</div></div>

<style>
/* MARKER-INVEST-RAILMENU — the menu borrows the rail's own type and colours. */
.rail-menu{position:relative;flex:0 0 auto}
.rail-menu > button{all:unset;cursor:pointer;font-size:13px;font-weight:550;color:var(--body);
  white-space:nowrap;display:flex;align-items:center;gap:7px;transition:color .12s}
.rail-menu > button:hover,.rail-menu.on > button{color:var(--text)}
.rail-menu-caret{width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;
  border-top:5px solid currentColor;opacity:.7}
.rail-menu-count{font-size:11px;color:var(--dim)}
.rail-pop{position:absolute;top:calc(100% + 12px);left:0;min-width:300px;background:var(--panel);
  border:1px solid var(--line2);border-radius:12px;padding:6px;display:none;
  box-shadow:0 24px 60px rgba(0,0,0,.55);z-index:40;max-height:70vh;overflow-y:auto}
.rail-menu.on .rail-pop{display:block}
.rail-pop a{display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:8px;
  font-size:13.5px;color:var(--text);text-decoration:none;white-space:normal}
.rail-pop a:hover{background:var(--panel2)}
.rail-pop a i{width:6px;height:6px;border-radius:50%;background:var(--line2);flex:0 0 6px;display:block;
  transition:background .12s}
.rail-pop a.open i{background:var(--lime)}
.rail-pop-grp{font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:var(--dim);
  padding:9px 11px 4px}
</style>

<script>
(function () {
  // MARKER-INVEST-RAIL — a link that scrolls to a closed box reads as broken,
  // so opening the section is part of following the link. Applies to rail
  // links and menu links alike.
  document.querySelectorAll('.rail a[data-sec], .rail-pop a[data-sec]').forEach(function (a) {
    var sec = document.getElementById(a.dataset.sec);
    if (!sec) { return; }

    function sync() { a.classList.toggle('open', sec.open); }

    sec.addEventListener('toggle', sync);
    a.addEventListener('click', function () { sec.open = true; sync(); });
    sync();
  });

  // MARKER-INVEST-RAILMENU — the dropdown itself.
  var menus = document.querySelectorAll('.rail-menu');
  menus.forEach(function (menu) {
    var btn = menu.querySelector('[data-rail-menu]');
    if (!btn) { return; }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var wasOpen = menu.classList.contains('on');
      menus.forEach(function (m) { m.classList.remove('on'); });
      menu.classList.toggle('on', !wasOpen);
    });
    menu.querySelectorAll('.rail-pop a').forEach(function (a) {
      a.addEventListener('click', function () { menu.classList.remove('on'); });
    });
  });
  document.addEventListener('click', function () {
    menus.forEach(function (m) { m.classList.remove('on'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { menus.forEach(function (m) { m.classList.remove('on'); }); }
  });
})();
</script>
