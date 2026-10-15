<?php
/**
 * The password rules as a checklist under a new-password field, ticked as the
 * person types. Only for inputs marked data-password-checklist. The rules match
 * password_problem() in includes/core/validation.php, which still checks them
 * on the server (and also refuses common passwords and the person's own name).
 */
?>
<style>
  .pw-rules {
    list-style: none;
    margin: 6px 0 14px;
    padding: 0;
    /* One rule per line in a narrow field, side by side where there is room. */
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
    gap: 0 14px;
    font-size: .8125rem;
    line-height: 1.5;
    color: var(--ink-faint, #6c757d);
  }

  .pw-rules li::before {
    content: "\25CB";
    display: inline-block;
    width: 1.1em;
  }

  .pw-rules li.is-met {
    color: var(--available-fg, #22613F);
  }

  .pw-rules li.is-met::before {
    content: "\2713";
  }

  html[data-theme="dark"] .pw-rules {
    color: #a7b0ad;
  }

  html[data-theme="dark"] .pw-rules li.is-met {
    color: #7fd3a0;
  }
</style>
<script>
  (function () {
    var RULES = [
      ['8 or more characters', function (v) { return v.length >= 8; }],
      ['An uppercase letter', function (v) { return /\p{Lu}/u.test(v); }],
      ['A lowercase letter', function (v) { return /\p{Ll}/u.test(v); }],
      ['A number', function (v) { return /\d/.test(v); }],
      ['A symbol (! @ # ?)', function (v) { return /[^\p{L}\p{N}]/u.test(v); }]
    ];

    function attach(input) {
      var list = document.createElement('ul');
      list.className = 'pw-rules';
      list.id = (input.id || 'password') + '-rules';
      var items = RULES.map(function (rule) {
        var li = document.createElement('li');
        li.textContent = rule[0];
        list.appendChild(li);
        return li;
      });

      // Below the field, and below any wrapper the show/hide button lives in.
      var anchor = input.closest('.password-field, .input-group, .pw-wrap') || input;
      anchor.insertAdjacentElement('afterend', list);

      // The checklist replaces a written hint, and screen readers read it instead.
      var hintId = input.getAttribute('aria-describedby');
      var hint = hintId ? document.getElementById(hintId) : null;
      if (hint) {
        hint.hidden = true;
      }
      input.setAttribute('aria-describedby', list.id);

      function update() {
        var value = input.value;
        RULES.forEach(function (rule, i) {
          var met = rule[1](value);
          items[i].classList.toggle('is-met', met);
          items[i].setAttribute('aria-label', rule[0] + (met ? ', done' : ', not yet'));
        });
      }
      input.addEventListener('input', update);
      update();
    }

    function init() {
      document.querySelectorAll('input[data-password-checklist]').forEach(attach);
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
</script>
