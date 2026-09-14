/**
 * Listing detail page: photo gallery, full-screen viewer, room filter, map.
 *
 * Everything here is an enhancement. Without JavaScript the main photo and the
 * thumbnails are plain links to the image files, every room stays listed, and
 * the map section shows its "Open in OpenStreetMap" link.
 */
(function () {
  'use strict';

  function readSet(el, attribute) {
    try {
      var set = JSON.parse(el.getAttribute(attribute) || '[]');
      return Array.isArray(set) ? set : [];
    } catch (e) {
      return [];
    }
  }

  /* ---------------------------------------------------------------------
   * Full-screen viewer, shared by the house gallery and every room.
   * open(photos, index, opener, onChange) shows a set; onChange(index) lets
   * the house gallery keep its own stage in step with the viewer.
   * ------------------------------------------------------------------- */
  var viewer = (function () {
    var box = document.querySelector('.lightbox');
    if (!box || typeof box.showModal !== 'function') {
      return null;
    }

    var img = box.querySelector('.lightbox-img');
    var count = box.querySelector('.lightbox-count');
    var prev = box.querySelector('.lightbox-prev');
    var next = box.querySelector('.lightbox-next');
    var photos = [];
    var current = 0;
    var opener = null;
    var onChange = null;

    function show(i) {
      if (!photos.length) return;
      current = (i + photos.length) % photos.length;
      img.src = photos[current].src;
      img.alt = photos[current].alt;
      count.textContent = photos.length > 1 ? (current + 1) + ' / ' + photos.length : '';
      if (onChange) onChange(current);
    }

    prev.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { show(current + 1); });
    box.querySelector('.lightbox-close').addEventListener('click', function () { box.close(); });

    box.addEventListener('keydown', function (e) {
      // <dialog> closes on Escape by itself in most browsers; handled here
      // too so it never depends on that.
      if (e.key === 'Escape') {
        e.preventDefault();
        box.close();
      } else if (e.key === 'ArrowLeft' && photos.length > 1) {
        e.preventDefault();
        show(current - 1);
      } else if (e.key === 'ArrowRight' && photos.length > 1) {
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
      if (touchX === null || photos.length < 2) return;
      var dx = e.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
    });

    // Focus goes back to whatever opened the viewer.
    box.addEventListener('close', function () {
      if (opener) opener.focus();
    });

    return {
      open: function (set, index, from, changed) {
        if (!set.length) return;
        photos = set;
        opener = from || null;
        onChange = changed || null;
        prev.hidden = next.hidden = set.length < 2;
        box.showModal();
        show(index || 0);
      }
    };
  })();

  /* ---------------------------------------------------------------------
   * House gallery
   * ------------------------------------------------------------------- */
  var gallery = document.querySelector('[data-gallery]');

  if (gallery) {
    var photos = readSet(gallery, 'data-photos');
    var stage = gallery.querySelector('.gallery-stage');
    var stageImg = gallery.querySelector('.gallery-main');
    var countText = gallery.querySelector('.gallery-count-text');
    var thumbs = gallery.querySelectorAll('.gallery-thumb');
    var current = 0;

    var showOnStage = function (i) {
      if (!photos.length) return;
      current = (i + photos.length) % photos.length;
      var photo = photos[current];
      var label = (current + 1) + ' of ' + photos.length;

      stageImg.src = photo.src;
      stageImg.alt = photo.alt;
      stage.href = photo.src;
      stage.setAttribute('aria-label', 'View photo ' + label + ' full screen');
      if (countText) countText.textContent = (current + 1) + ' / ' + photos.length;

      Array.prototype.forEach.call(thumbs, function (thumb, index) {
        var active = index === current;
        thumb.classList.toggle('is-active', active);
        if (active) {
          thumb.setAttribute('aria-current', 'true');
        } else {
          thumb.removeAttribute('aria-current');
        }
      });
    };

    Array.prototype.forEach.call(thumbs, function (thumb, index) {
      thumb.addEventListener('click', function (e) {
        e.preventDefault();
        showOnStage(index);
      });
    });

    // Where the browser has <dialog>, the main photo opens the viewer;
    // elsewhere it stays a link to the full image.
    if (viewer && photos.length) {
      stage.addEventListener('click', function (e) {
        e.preventDefault();
        viewer.open(photos, current, stage, showOnStage);
      });
    }
  }

  /* ---------------------------------------------------------------------
   * Room photos: each room's photo opens that room's own set.
   * ------------------------------------------------------------------- */
  if (viewer) {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest ? e.target.closest('[data-photo-set]') : null;
      if (!trigger) return;
      e.preventDefault();
      viewer.open(readSet(trigger, 'data-photo-set'), 0, trigger);
    });
  } else {
    // No <dialog>: a room photo button has nowhere to open, so it opens the
    // first image directly instead.
    Array.prototype.forEach.call(document.querySelectorAll('[data-photo-set]'), function (trigger) {
      trigger.addEventListener('click', function () {
        var set = readSet(trigger, 'data-photo-set');
        if (set.length) window.location.href = set[0].src;
      });
    });
  }

  /* ---------------------------------------------------------------------
   * Room type filter chips
   * ------------------------------------------------------------------- */
  var filter = document.querySelector('[data-room-filter]');

  if (filter) {
    filter.hidden = false;
    var tiles = document.querySelectorAll('[data-room-type-name]');
    var chips = filter.querySelectorAll('[data-room-type]');

    filter.addEventListener('click', function (e) {
      var chip = e.target.closest('[data-room-type]');
      if (!chip) return;
      var wanted = chip.getAttribute('data-room-type');

      Array.prototype.forEach.call(chips, function (c) {
        var on = c === chip;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      Array.prototype.forEach.call(tiles, function (tile) {
        tile.hidden = wanted !== '' && tile.getAttribute('data-room-type-name') !== wanted;
      });
    });
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
      var mapTiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
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

      mapTiles.once('tileerror', function () {
        var note = document.querySelector('[data-map-offline]');
        if (note) note.hidden = false;
      });
    }
  }
})();
