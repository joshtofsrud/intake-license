/* Class subnav scroll-hint (patch #34).
 *
 * On a phone viewport (≤1023px), if any .cl-subnav scrolls horizontally
 * (i.e. tab content overflows), perform a one-time scroll-and-bounce on
 * page load to teach the user the bar scrolls. Matches the affordance
 * pattern established in resfilter-scroll-hint.sh (#30).
 *
 * - Only animates if .cl-subnav.scrollWidth > .clientWidth + 16px slack
 * - Respects prefers-reduced-motion (skips animation entirely)
 * - Cancels on first user touch (don't fight a user already scrolling)
 * - Only animates once per page load
 */
(function () {
  'use strict';

  if (window.matchMedia('(min-width: 1024px)').matches) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var bar = document.querySelector('.cl-subnav');
  if (!bar) return;

  var overflow = bar.scrollWidth - bar.clientWidth;
  if (overflow < 16) return;

  var cancelled = false;
  function onTouch() {
    cancelled = true;
    bar.removeEventListener('touchstart', onTouch);
    bar.removeEventListener('wheel', onTouch);
  }
  bar.addEventListener('touchstart', onTouch, { passive: true });
  bar.addEventListener('wheel', onTouch, { passive: true });

  // Wait a beat for layout to settle, then nudge right and back.
  setTimeout(function () {
    if (cancelled) return;
    bar.scrollTo({ left: 36, behavior: 'smooth' });
    setTimeout(function () {
      if (cancelled) return;
      bar.scrollTo({ left: 0, behavior: 'smooth' });
    }, 380);
  }, 220);
})();
