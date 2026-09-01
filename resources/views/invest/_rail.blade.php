{{-- MARKER-INVEST-RAIL — expects $rail: [ ['#anchor', 'Label', 'section-id'|null] ].
     A third value means the item opens a collapsible section and carries a dot. --}}
<div class="rail"><div class="wrap">
  @foreach($rail as [$href, $label, $sec])
    <a href="{{ $href }}"@if($sec) data-sec="{{ $sec }}"@endif @if($href === '#talk' || str_contains($href, '/book/')) style="color:var(--lime)"@endif>@if($sec)<i></i>@endif{{ $label }}</a> {{-- MARKER-SCHED-TALK-ENTRY --}}
  @endforeach
</div></div>

<script>
(function () {
  document.querySelectorAll('.rail a[data-sec]').forEach(function (a) {
    var sec = document.getElementById(a.dataset.sec);
    if (!sec) { return; }

    function sync() { a.classList.toggle('open', sec.open); }

    sec.addEventListener('toggle', sync);
    // Opening the section as well as jumping to it — a link that scrolls to a
    // closed box reads as broken.
    a.addEventListener('click', function () { sec.open = true; sync(); });
    sync();
  });
})();
</script>
