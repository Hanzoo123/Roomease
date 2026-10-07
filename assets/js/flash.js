/**
 * Success messages fade out after a few seconds (errors stay). Hovering pauses
 * the countdown, and the close button dismisses a message at once. A floating
 * notice (.flash-toast) just fades; one inside a page, such as on the sign-in
 * card, also folds the space it held (.is-leaving in style.css).
 */
(function () {
  var DELAY = 3000;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function remove(el) {
    if (el.parentNode) el.parentNode.removeChild(el);
  }

  function dismiss(el) {
    if (el.getAttribute('data-leaving')) return;
    el.setAttribute('data-leaving', '1');
    if (reduceMotion) {
      remove(el);
      return;
    }
    if (el.classList.contains('flash-toast')) {
      el.classList.add('is-hiding');
      window.setTimeout(function () { remove(el); }, 250);
      return;
    }
    // Fix the current height first, so the fold animates from it to 0.
    el.style.height = el.offsetHeight + 'px';
    void el.offsetHeight;
    el.classList.add('is-leaving');
    el.addEventListener('transitionend', function (e) {
      if (e.target === el && e.propertyName === 'height') remove(el);
    });
    window.setTimeout(function () { remove(el); }, 1000); // in case transitionend never arrives
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-flash-close]'), function (btn) {
    btn.addEventListener('click', function () {
      var el = btn.closest('.flash-toast');
      if (el) dismiss(el);
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-autohide]'), function (el) {
    var timer = null;

    function start() {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { dismiss(el); }, DELAY);
    }

    el.addEventListener('mouseenter', function () {
      window.clearTimeout(timer);
    });
    el.addEventListener('mouseleave', start);

    if (document.hidden) {
      document.addEventListener('visibilitychange', function onShow() {
        if (document.hidden) return;
        document.removeEventListener('visibilitychange', onShow);
        start();
      });
    } else {
      start();
    }
  });
})();
