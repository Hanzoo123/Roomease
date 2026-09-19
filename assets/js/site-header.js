/**
 * The public header stays at the top of the screen. It slides out of the way
 * while the page scrolls down, and back in as soon as it scrolls up, so the
 * navigation is one flick away without covering what is being read.
 *
 * The movement itself is CSS (.site-header.is-hidden in style.css); this only
 * decides when. It also publishes --header-offset, the height the header is
 * covering right now, for things that stick below it (Quick Info on a listing).
 * Without JavaScript the header simply stays put.
 *
 * On a narrow screen the links leave the tab and become a sheet hanging under
 * it, opened by the button beside the wordmark. That is the second half of
 * this file. The stylesheet only draws the collapsed navigation under
 * html.js, so with JavaScript off the links stay where they always were.
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

    // An open sheet is anchored to the tab, so the tab has to stay put: the
    // header sliding away under a reaching thumb would take the menu with it.
    if (root.classList.contains('nav-open')) {
      setHidden(false);
      lastY = y;
      return;
    }

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

  /* ---------------------------------------------------------------
     The sheet of links on a narrow screen
     --------------------------------------------------------------- */
  var toggle = header.querySelector('[data-nav-toggle]');
  var nav = document.getElementById('site-nav');
  var scrim = header.querySelector('[data-nav-scrim]');
  if (!toggle || !nav) return;

  // The stylesheet decides at which width the sheet exists; asking whether the
  // button is on screen keeps that one decision in one place.
  function collapsed() {
    return toggle.offsetParent !== null;
  }

  function focusables() {
    return Array.prototype.filter.call(
      nav.querySelectorAll('a[href], button:not([disabled])'),
      function (el) { return el.offsetParent !== null; }
    );
  }

  function openNav() {
    root.classList.add('nav-open');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Close menu');
    if (scrim) scrim.hidden = false;
    setHidden(false);

    var first = focusables()[0];
    if (first) first.focus();
  }

  function closeNav(returnFocus) {
    if (!root.classList.contains('nav-open')) return;
    root.classList.remove('nav-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Menu');
    if (scrim) scrim.hidden = true;
    if (returnFocus) toggle.focus();
  }

  toggle.addEventListener('click', function () {
    if (root.classList.contains('nav-open')) {
      closeNav(false);
    } else {
      openNav();
    }
  });

  if (scrim) {
    scrim.addEventListener('click', function () {
      closeNav(false);
    });
  }

  // A link to somewhere on this same page (#rooms, say) navigates without a
  // reload, which would otherwise leave the sheet sitting over the answer.
  nav.addEventListener('click', function (event) {
    if (event.target.closest('a')) closeNav(false);
  });

  document.addEventListener('keydown', function (event) {
    if (!root.classList.contains('nav-open')) return;

    if (event.key === 'Escape') {
      closeNav(true);
      return;
    }

    if (event.key !== 'Tab') return;

    // Tab cycles within the sheet and its button rather than wandering off
    // into the page the sheet is covering.
    var stops = [toggle].concat(focusables());
    var first = stops[0];
    var last = stops[stops.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // Turning the phone, or widening the window past the breakpoint, puts the
  // links back in the tab; an open sheet would be left behind on its own.
  window.addEventListener('resize', function () {
    if (!collapsed()) closeNav(false);
  });
})();
