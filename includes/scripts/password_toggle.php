<?php
/**
 * Show/hide toggle for every password field on the page.
 *
 * Included from both footers, so it applies to the public theme and the
 * management panel alike and does nothing on pages without a password field.
 * The icons are inline SVG rather than Font Awesome, because the public theme
 * does not load an icon font.
 */
?>
<style>
  .pw-wrap {
    position: relative;
    display: block;
  }

  .pw-toggle {
    position: absolute;
    top: 50%;
    right: 6px;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: #6c757d;
    cursor: pointer;
    line-height: 1;
  }

  .pw-toggle:hover {
    color: #212529;
    background: rgba(0, 0, 0, .06);
  }

  .pw-toggle:focus {
    outline: 2px solid #80bdff;
    outline-offset: 1px;
  }

  /* Inside a Bootstrap input-group the button sits beside the existing icon. */
  .pw-toggle-ig {
    cursor: pointer;
    color: #495057;
  }

  .pw-toggle-ig:hover {
    color: #212529;
  }

  .pw-toggle svg,
  .pw-toggle-ig svg {
    width: 18px;
    height: 18px;
    display: block;
  }
</style>
<script>
  (function () {
    var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function makeButton(extraClass) {
      var btn = document.createElement('button');
      // Must be type=button, or it would submit the form it sits in.
      btn.type = 'button';
      btn.className = extraClass;
      btn.innerHTML = EYE;
      btn.setAttribute('aria-label', 'Show password');
      btn.setAttribute('aria-pressed', 'false');
      btn.title = 'Show password';
      return btn;
    }

    function wire(btn, input) {
      btn.addEventListener('click', function () {
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        btn.innerHTML = reveal ? EYE_OFF : EYE;
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        btn.title = reveal ? 'Hide password' : 'Show password';
        // Keep the caret where the user left it.
        if (typeof input.selectionStart === 'number') {
          var pos = input.value.length;
          input.focus();
          try { input.setSelectionRange(pos, pos); } catch (e) { /* type change can reset it */ }
        }
      });
    }

    function attach(input) {
      if (input.dataset.pwToggle) {
        return;
      }
      input.dataset.pwToggle = '1';

      var group = input.closest ? input.closest('.input-group') : null;

      if (group) {
        // Bootstrap input-group (the login form): sit next to the lock icon.
        var append = group.querySelector('.input-group-append');
        if (!append) {
          append = document.createElement('div');
          append.className = 'input-group-append';
          group.appendChild(append);
        }
        var igBtn = makeButton('input-group-text pw-toggle-ig');
        append.appendChild(igBtn);
        wire(igBtn, input);
        return;
      }

      // Everything else: overlay the button on the right edge of the field.
      // The input's bottom margin moves to the wrapper so the wrapper is
      // exactly as tall as the input and the button centres correctly.
      var wrap = document.createElement('span');
      wrap.className = 'pw-wrap';
      var style = window.getComputedStyle(input);
      wrap.style.marginBottom = style.marginBottom;
      input.style.marginBottom = '0';

      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      // Stop the revealed text from running underneath the icon.
      var padRight = parseFloat(style.paddingRight) || 0;
      if (padRight < 38) {
        input.style.paddingRight = '38px';
      }

      var btn = makeButton('pw-toggle');
      wrap.appendChild(btn);
      wire(btn, input);
    }

    function init() {
      var inputs = document.querySelectorAll('input[type="password"]');
      for (var i = 0; i < inputs.length; i++) {
        attach(inputs[i]);
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
</script>
