/**
 * The public header stays at the top of the screen. It slides out of the way
 * while the page scrolls down, and back in as soon as it scrolls up, so the
 * navigation is one flick away without covering what is being read.
 *
 * The movement itself is CSS (.site-header.is-hidden in style.css); this only
 * decides when. It also publishes --header-offset, the height the header is
 * covering right now, for things that stick below it (Quick Info on a listing).
 * Without JavaScript the header simply stays put.
 */
(function () {
  var header = document.querySelector('[data-site-header]');
  if (!header) return;

  // Scroll movement smaller than this is ignored, so a resting thumb or a
  // trackpad's last few pixels of momentum do not make the header flicker.
  var TOLERANCE = 8;

  var root = document.documentElement;
  var lastY = currentY();
  var ticking = false;

  function currentY() {
    // Clamped, because overscroll bounce at either end reports positions
    // outside the page, which would read as a change of direction.
    var max = root.scrollHeight - window.innerHeight;
    return Math.min(Math.max(window.scrollY, 0), Math.max(max, 0));
  }

  function publishOffset() {
    var hidden = header.classList.contains('is-hidden');
    root.style.setProperty('--header-offset', hidden ? '0px' : header.offsetHeight + 'px');
  }

  function setHidden(hidden) {
    if (header.classList.contains('is-hidden') === hidden) return;
    header.classList.toggle('is-hidden', hidden);
    publishOffset();
  }

  function update() {
    ticking = false;
    var y = currentY();
    var height = header.offsetHeight;

    // Until the page has scrolled past the header's own spot, the band is
    // still behind it, so it keeps its band colour and never hides.
    header.classList.toggle('is-floating', y > height);

    if (y <= height || header.contains(document.activeElement)) {
      setHidden(false);
      lastY = y;
      return;
    }

    if (Math.abs(y - lastY) < TOLERANCE) return;
    setHidden(y > lastY);
    lastY = y;
  }

  function requestUpdate() {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(update);
  }

  window.addEventListener('scroll', requestUpdate, { passive: true });
  window.addEventListener('resize', function () {
    publishOffset();
    requestUpdate();
  });

  // Tabbing into the navigation brings it back even mid-page.
  header.addEventListener('focusin', function () {
    setHidden(false);
  });

  publishOffset();
  update();
})();
