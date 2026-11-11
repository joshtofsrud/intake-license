{{-- MARKER-RENTALS-STUDIO: the small bit of motion every Studio rental page shares —
     scroll-reveal for .sd-in and the frosted header. @include inside @push('scripts'). --}}
<script>
(function () {
  'use strict';
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() { var l = innerHeight * 0.92; $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < l) el.classList.add('is-in'); }); }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = document.getElementById('sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();
})();
</script>
