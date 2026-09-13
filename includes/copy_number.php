<?php
/**
 * Copy-to-clipboard for the landlord's number on the listing detail page.
 *
 * The button is rendered as type="button" and does nothing without this file,
 * so the page is still usable with JavaScript off — the number itself is a
 * plain tel: link either way.
 *
 * navigator.clipboard only exists in a secure context, which a boarding-house
 * demo served over plain HTTP on a LAN is not, so there is a textarea and
 * execCommand fallback behind it. Included from the public footer; does
 * nothing on pages with no .js-copy-number button.
 */
?>
<script>
  (function () {
    var buttons = document.querySelectorAll('.js-copy-number');
    if (!buttons.length) return;

    function legacyCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }

    function confirmOn(btn) {
      var original = btn.getAttribute('data-label') || btn.textContent.trim();
      btn.setAttribute('data-label', original);
      btn.textContent = 'Copied';
      btn.classList.add('is-copied');
      window.setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('is-copied');
      }, 1800);
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        var number = btn.getAttribute('data-number') || '';
        if (!number) return;

        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(number).then(function () {
            confirmOn(btn);
          }, function () {
            if (legacyCopy(number)) confirmOn(btn);
          });
        } else if (legacyCopy(number)) {
          confirmOn(btn);
        }
      });
    });
  })();
</script>
