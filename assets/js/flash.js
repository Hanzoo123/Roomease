/**
 * Success messages ("You have been logged out.") fade out and fold away after
 * a few seconds. Errors are not marked [data-autohide], so they stay until the
 * page changes: they explain why something did not work.
 *
 * Resting the pointer on a message holds it; the countdown restarts when the
 * pointer leaves. A page opened in a background tab starts counting only once
 * it is actually shown. The fold itself is CSS (.is-leaving in style.css).
 */
(function () {
  var DELAY = 3000;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  Array.prototype.forEach.call(document.querySelectorAll('[data-autohide]'), function (el) {
    var timer = null;
    var leaving = false;

    function remove() {
      if (el.parentNode) el.parentNode.removeChild(el);
    }

    function dismiss() {
      if (leaving) return;
      leaving = true;
      if (reduceMotion) {
        remove();
        return;
      }
      // Fix the current height first, so the fold animates from it to 0.
      el.style.height = el.offsetHeight + 'px';
      void el.offsetHeight;
      el.classList.add('is-leaving');
      el.addEventListener('transitionend', function (e) {
        if (e.target === el && e.propertyName === 'height') remove();
      });
      window.setTimeout(remove, 1000); // in case transitionend never arrives
    }

    function start() {
      if (leaving) return;
      window.clearTimeout(timer);
      timer = window.setTimeout(dismiss, DELAY);
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
