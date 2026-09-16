<?php
/**
 * Instant heart toggle for the save-a-listing forms.
 *
 * Without this the heart is a plain form post that redirects, so every tap
 * reloads the page and throws away the boarder's scroll position. Here the
 * click is sent with fetch() and the button is repainted in place instead.
 *
 * The forms still work untouched with JavaScript off: this only takes over
 * once it has loaded, and it falls back to a real submit if the request
 * fails. Included from the public footer, and does nothing on pages that
 * have no .save-form on them.
 */
?>
<script>
  (function () {
    var TOAST_MS = 2200;
    var toastEl = null;
    var toastTimer = null;

    function toast(message, type) {
      if (!toastEl) {
        toastEl = document.createElement('div');
        toastEl.className = 'save-toast';
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');
        document.body.appendChild(toastEl);
      }
      toastEl.className = 'save-toast alert alert-' + (type === 'error' ? 'error' : 'success');
      toastEl.textContent = message;
      // Let the class land before flipping the transition on.
      requestAnimationFrame(function () { toastEl.classList.add('is-on'); });
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, TOAST_MS);
    }

    /** Repaint one heart for its new state, so no reload is needed. */
    function paint(form, btn, saved) {
      var actionInput = form.querySelector('input[name="action"]');
      if (actionInput) {
        actionInput.value = saved ? 'unsave' : 'save';
      }
      btn.classList.toggle('is-saved', saved);

      var svg = btn.querySelector('svg');
      if (svg) {
        svg.setAttribute('fill', saved ? 'currentColor' : 'none');
      }

      var label = saved ? 'Remove from saved' : 'Save this listing';
      btn.title = label;
      btn.setAttribute('aria-label', label);
      btn.setAttribute('aria-pressed', saved ? 'true' : 'false');

      // The detail page carries a worded label beside the heart.
      var text = btn.querySelector('.save-btn-text');
      if (text) {
        text.textContent = saved ? 'Saved' : 'Save this listing';
      }

      // Restart the pop animation on every toggle.
      btn.classList.remove('save-btn-pop');
      void btn.offsetWidth;
      btn.classList.add('save-btn-pop');
    }

    /**
     * On the saved page an unsaved card no longer belongs in the list, so it
     * fades out and the counter follows it down. Emptying the list reloads
     * once, which is the cheapest way to get the proper empty state back.
     */
    function dropCard(form) {
      var card = form.closest('.room-card');
      if (!card) {
        return;
      }
      card.classList.add('is-removing');
      setTimeout(function () {
        card.remove();
        var left = document.querySelectorAll('.card-grid .room-card').length;
        var counter = document.getElementById('saved-count');
        if (counter) {
          counter.textContent = left + ' saved';
        }
        if (left === 0) {
          window.location.reload();
        }
      }, 220);
    }

    document.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('.save-form') : null;
      if (!form) {
        return;
      }

      var btn = form.querySelector('.save-btn');
      if (!btn || btn.disabled) {
        return;
      }

      e.preventDefault();
      btn.disabled = true;

      // getAttribute, not form.action: the form has a field named "action",
      // and form.action returns that field instead of the URL, which sent
      // every tap to a missing page and fell back to a full reload.
      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.redirect) {
            window.location.href = data.redirect;
            return;
          }
          if (data.reload) {
            toast(data.message, 'error');
            setTimeout(function () { window.location.reload(); }, TOAST_MS);
            return;
          }
          btn.disabled = false;
          if (!data.ok) {
            toast(data.message || 'Sorry, that did not work. Please try again.', 'error');
            return;
          }
          if (!data.saved && form.dataset.dropOnUnsave) {
            dropCard(form);
          } else {
            paint(form, btn, data.saved);
          }
          toast(data.message, 'success');
        })
        .catch(function () {
          // Offline, or the server answered with something that is not JSON.
          // form.submit() skips this handler, so the click still counts.
          form.submit();
        });
    });
  })();
</script>
