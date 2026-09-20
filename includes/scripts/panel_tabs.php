<?php
/**
 * Drives the record tabs printed by panel_page_header()'s 'tabs' option, and
 * the shared "are you sure?" confirmation on destructive forms.
 *
 * Included from panel_footer.php, so every panel page gets both and no page
 * has to carry its own copy. It does nothing on a page with neither.
 *
 * The tabs are progressive: the markup renders every panel, and only this
 * script hides the ones that are not current. A browser with JavaScript off
 * shows the whole record, which is the right fallback for a page whose job is
 * to show everything about one thing.
 */
?>
<script>
  (function () {
    /* ---- Record tabs ---- */
    document.querySelectorAll('[data-panel-tabs]').forEach(function (tablist) {
      var tabs = Array.prototype.slice.call(tablist.querySelectorAll('.re-tab'));
      var panels = tabs.map(function (tab) {
        return document.getElementById(tab.getAttribute('aria-controls'));
      });
      if (tabs.length < 2 || panels.indexOf(null) !== -1) return;

      // Only now does hiding become reversible, so only now is it switched on.
      document.body.classList.add('re-tabbed');

      function show(index, focus) {
        tabs.forEach(function (tab, i) {
          var current = i === index;
          tab.classList.toggle('is-active', current);
          tab.setAttribute('aria-selected', current ? 'true' : 'false');
          tab.setAttribute('tabindex', current ? '0' : '-1');
          panels[i].hidden = !current;
        });
        if (focus) tabs[index].focus();
      }

      tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function (event) {
          event.preventDefault();
          show(i);
          // The section is in the address bar, so a reload or a shared link
          // opens the same one.
          history.replaceState(null, '', tab.getAttribute('href'));
        });

        // Left and right move between tabs, as a tablist is expected to.
        tab.addEventListener('keydown', function (event) {
          var step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
          if (!step) return;
          event.preventDefault();
          show((i + step + tabs.length) % tabs.length, true);
        });
      });

      function openFromHash(fallbackToFirst) {
        var wanted = tabs.findIndex(function (tab) {
          return tab.getAttribute('href') === window.location.hash;
        });
        if (wanted !== -1) {
          show(wanted);
        } else if (fallbackToFirst) {
          show(0);
        }
      }

      // Open whichever section the address bar names, falling back to the first.
      openFromHash(true);

      // A link elsewhere on the page pointing at a section, or the back button,
      // changes the hash without reloading; the tabs follow it either way.
      window.addEventListener('hashchange', function () {
        openFromHash(false);
      });
    });

    /* ---- Destructive forms ask first ---- */
    // The question is text in an attribute rather than script, so a name
    // carrying a quote, a backslash or a line break cannot break the handler
    // and quietly let the form through unconfirmed.
    document.querySelectorAll('form.js-confirm').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
      });
    });
  })();
</script>
