/**
 * Listing detail page: photo gallery, full-screen viewer, and the map.
 *
 * Everything here is an enhancement. Without JavaScript the main photo and the
 * thumbnails are plain links to the image files, and the map section shows its
 * "Open in OpenStreetMap" link.
 */
(function () {
  'use strict';

  /* ---------------------------------------------------------------------
   * Gallery
   * ------------------------------------------------------------------- */
  var gallery = document.querySelector('[data-gallery]');

  if (gallery) {
    var photos;
    try {
      photos = JSON.parse(gallery.getAttribute('data-photos') || '[]');
    } catch (e) {
      photos = [];
    }

    var stage = gallery.querySelector('.gallery-stage');
    var stageImg = gallery.querySelector('.gallery-main');
    var countText = gallery.querySelector('.gallery-count-text');
    var thumbs = gallery.querySelectorAll('.gallery-thumb');
    var box = document.querySelector('.lightbox');
    var current = 0;

    var boxImg = box ? box.querySelector('.lightbox-img') : null;
    var boxCount = box ? box.querySelector('.lightbox-count') : null;

    function label(i) {
      return (i + 1) + ' / ' + photos.length;
    }

    function show(i) {
      if (!photos.length) return;
      current = (i + photos.length) % photos.length;
      var photo = photos[current];

      stageImg.src = photo.src;
      stageImg.alt = photo.alt;
      stage.href = photo.src;
      stage.setAttribute('aria-label', 'View photo ' + label(current).replace(' / ', ' of ') + ' full screen');
      if (countText) countText.textContent = label(current);

      Array.prototype.forEach.call(thumbs, function (thumb, index) {
        var active = index === current;
        thumb.classList.toggle('is-active', active);
        if (active) {
          thumb.setAttribute('aria-current', 'true');
        } else {
          thumb.removeAttribute('aria-current');
        }
      });

      if (box && box.open) {
        boxImg.src = photo.src;
        boxImg.alt = photo.alt;
        boxCount.textContent = label(current);
      }
    }

    Array.prototype.forEach.call(thumbs, function (thumb, index) {
      thumb.addEventListener('click', function (e) {
        e.preventDefault();
        show(index);
      });
    });

    // <dialog> is the viewer where the browser has it; elsewhere the main
    // photo stays a link to the full image.
    if (box && typeof box.showModal === 'function' && photos.length) {
      stage.addEventListener('click', function (e) {
        e.preventDefault();
        box.showModal();
        show(current);
      });

      box.querySelector('.lightbox-prev').addEventListener('click', function () { show(current - 1); });
      box.querySelector('.lightbox-next').addEventListener('click', function () { show(current + 1); });
      box.querySelector('.lightbox-close').addEventListener('click', function () { box.close(); });

      box.addEventListener('keydown', function (e) {
        // <dialog> closes on Escape by itself in most browsers; handled here
        // too so it never depends on that.
        if (e.key === 'Escape') {
          e.preventDefault();
          box.close();
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          show(current - 1);
        } else if (e.key === 'ArrowRight') {
          e.preventDefault();
          show(current + 1);
        }
      });

      // A click on the dark backdrop, outside the photo and the buttons, closes it.
      box.addEventListener('click', function (e) {
        if (e.target === box || e.target.classList.contains('lightbox-inner')) {
          box.close();
        }
      });

      // Swipe left or right on a phone.
      var touchX = null;
      box.addEventListener('touchstart', function (e) {
        touchX = e.changedTouches[0].clientX;
      }, { passive: true });
      box.addEventListener('touchend', function (e) {
        if (touchX === null) return;
        var dx = e.changedTouches[0].clientX - touchX;
        touchX = null;
        if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
      });

      // Focus goes back to the photo that opened the viewer.
      box.addEventListener('close', function () {
        stage.focus();
      });
    }
  }

  /* ---------------------------------------------------------------------
   * Map
   * ------------------------------------------------------------------- */
  var mapEl = document.getElementById('listing-map');

  if (mapEl && window.L) {
    var lat = parseFloat(mapEl.getAttribute('data-lat'));
    var lng = parseFloat(mapEl.getAttribute('data-lng'));

    if (isFinite(lat) && isFinite(lng)) {
      mapEl.textContent = '';

      var map = L.map(mapEl, { scrollWheelZoom: false }).setView([lat, lng], 16);
      var tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
      }).addTo(map);

      // The popup is built as a text node: the name is typed by a landlord, and
      // a string passed to bindPopup would be inserted as HTML.
      var popup = document.createElement('strong');
      popup.textContent = mapEl.getAttribute('data-label') || '';
      L.marker([lat, lng]).addTo(map).bindPopup(popup);

      // Scroll-wheel zoom stays off until the map is clicked, so scrolling the
      // page past the map does not zoom it by accident.
      map.once('focus click', function () { map.scrollWheelZoom.enable(); });

      tiles.once('tileerror', function () {
        var note = document.querySelector('[data-map-offline]');
        if (note) note.hidden = false;
      });
    }
  }
})();
