<?php
/**
 * "Copy" button for the landlord's number. navigator.clipboard needs HTTPS,
 * so there is a fallback for plain HTTP.
 */
?>
<script>
  (function () {
    // .js-copy-number carries a phone number; .js-copy carries anything else,
    // such as the listing page's own link.
    var buttons = document.querySelectorAll('.js-copy-number, .js-copy');
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
        var number = btn.getAttribute('data-copy') || btn.getAttribute('data-number') || '';
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
