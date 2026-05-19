/* PATCH-103-LOCATION-SWITCHER */
(function () {
  'use strict';

  function closeAllDetails(except) {
    document.querySelectorAll('[data-loc-switcher="root"] details[open]').forEach(function (d) {
      if (d !== except) d.removeAttribute('open');
    });
  }

  // Outside-click closes any open switcher menus.
  document.addEventListener('click', function (e) {
    var root = e.target.closest('[data-loc-switcher="root"]');
    if (!root) {
      closeAllDetails(null);
    } else {
      var openHere = root.querySelector('details[open]');
      closeAllDetails(openHere);
    }
  }, true);

  // Escape closes open menus.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
      closeAllDetails(null);
    }
  });

  // Intercept current-location clicks — no-op rather than POST to swap to self.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ia-loc-switcher-item.is-current');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();
      var d = btn.closest('details');
      if (d) d.removeAttribute('open');
    }
  }, true);
})();
