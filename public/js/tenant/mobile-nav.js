/**
 * Mobile nav drawer controller.
 * No framework dependencies — plain vanilla JS.
 */
window.IntakeMobileNav = {
  openDrawer() {
    const overlay = document.getElementById('ia-more-drawer');
    if (!overlay) return;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ia-drawer-open');
  },

  closeDrawer() {
    const overlay = document.getElementById('ia-more-drawer');
    if (!overlay) return;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ia-drawer-open');
  },

  closeDrawerFromOverlay(event) {
    // Only close if the overlay itself was clicked, not the drawer content.
    if (event.target.id === 'ia-more-drawer') {
      this.closeDrawer();
    }
  }
};

// ESC key closes drawer
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    window.IntakeMobileNav.closeDrawer();
  }
});
