<?php
/**
 * "Show 6 more" on browse without reloading: fetches the next page and adds
 * the new cards. Without JavaScript the link just loads that page.
 */
?>
<script>
  (function () {
    document.addEventListener('click', function (e) {
      var link = e.target.closest ? e.target.closest('.js-show-more') : null;
      if (!link || link.getAttribute('aria-busy') === 'true') {
        return;
      }
      e.preventDefault();

      var label = link.textContent;
      link.setAttribute('aria-busy', 'true');
      link.textContent = 'Loading rooms…';

      fetch(link.href, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.text();
        })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var board = doc.getElementById(link.hash.slice(1));
          var nextMore = doc.querySelector('.show-more');
          var currentMore = link.closest('.show-more');
          if (!board || !currentMore) {
            throw new Error('Board missing from response');
          }

          currentMore.parentNode.insertBefore(document.adoptNode(board), currentMore);
          if (nextMore) {
            currentMore.replaceWith(document.adoptNode(nextMore));
          } else {
            currentMore.remove();
          }

          history.replaceState(null, '', link.pathname + link.search);

          // Keyboard and screen reader users land on the first room just added.
          var first = board.querySelector('.room-card-link');
          if (first) {
            first.focus({ preventScroll: true });
          }
        })
        .catch(function () {
          // Offline, or an unexpected reply: fall back to the plain link.
          link.textContent = label;
          link.removeAttribute('aria-busy');
          window.location.href = link.href;
        });
    });
  })();
</script>
