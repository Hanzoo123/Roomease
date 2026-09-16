<?php
/**
 * "Show more rooms" on browse, without a page reload.
 *
 * The link is a real URL (?page=N+1#chunk-N+1) that renders every board up to
 * N+1, so with JavaScript off it reloads and jumps to the new board. Here the
 * same page is fetched in the background, only the new board and the updated
 * "Show more" block are lifted out of it, and the address bar is updated so a
 * refresh or a shared link shows the same rooms.
 *
 * The heart forms inside the new board need no wiring: favorite_toggle.php
 * listens for submits on the whole document.
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
