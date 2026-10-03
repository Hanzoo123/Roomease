<?php
/**
 * Save/unsave hearts without reloading the page (fetch). If the request
 * fails, or JavaScript is off, the form submits normally.
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

      btn.setAttribute('aria-pressed', saved ? 'true' : 'false');

      // A heart with a text label needs no aria-label; a bare heart gets one.
      var text = btn.querySelector('.save-btn-text');
      if (text) {
        text.textContent = saved ? 'Saved' : 'Save this listing';
      } else {
        var name = btn.getAttribute('data-name');
        btn.title = saved ? 'Remove from saved' : 'Save this listing';
        btn.setAttribute('aria-label', name
          ? (saved ? 'Remove ' + name + ' from saved' : 'Save ' + name)
          : btn.title);
      }

      // Restart the pop animation on every toggle.
      btn.classList.remove('save-btn-pop');
      void btn.offsetWidth;
      btn.classList.add('save-btn-pop');
    }

    /** Update every heart for this listing (the listing page has two). */
    function paintAll(form, saved) {
      var idField = form.querySelector('input[name="boarding_house_id"]');
      var id = idField ? idField.value : null;

      Array.prototype.forEach.call(document.querySelectorAll('.save-form'), function (other) {
        var otherId = other.querySelector('input[name="boarding_house_id"]');
        if (!id || !otherId || otherId.value !== id) {
          return;
        }
        var otherBtn = other.querySelector('.save-btn');
        if (otherBtn) {
          paint(other, otherBtn, saved);
        }
      });
    }

    /** Saved page: fade out an unsaved card; reload when the list is empty. */
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

      // Not form.action: the form has a field named "action", which hides it.
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
            paintAll(form, data.saved);
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
