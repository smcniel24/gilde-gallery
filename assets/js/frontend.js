(function () {
    'use strict';

    var overlay = null;
    var items = [];
    var currentIndex = -1;
    var touchStartX = null;

    var SWIPE_THRESHOLD = 40;

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeLightbox();
        } else if (event.key === 'ArrowLeft') {
            showIndex(currentIndex - 1);
        } else if (event.key === 'ArrowRight') {
            showIndex(currentIndex + 1);
        }
    }

    function onTouchStart(event) {
        touchStartX = event.changedTouches[0].screenX;
    }

    function onTouchEnd(event) {
        if (touchStartX === null) {
            return;
        }

        var delta = event.changedTouches[0].screenX - touchStartX;
        touchStartX = null;

        if (Math.abs(delta) < SWIPE_THRESHOLD) {
            return;
        }

        showIndex(currentIndex + (delta < 0 ? 1 : -1));
    }

    function closeLightbox() {
        if (!overlay) {
            return;
        }
        overlay.remove();
        overlay = null;
        items = [];
        currentIndex = -1;
        document.removeEventListener('keydown', onKeydown);
        document.body.classList.remove('bg-lightbox-open');
    }

    function showIndex(index) {
        if (!overlay || items.length === 0) {
            return;
        }

        currentIndex = (index + items.length) % items.length;

        var link = items[currentIndex];
        var img = link.querySelector('img');
        var alt = img ? img.getAttribute('alt') : '';

        var lightboxImg = overlay.querySelector('.bg-lightbox-img');
        lightboxImg.src = link.getAttribute('href');
        lightboxImg.alt = alt || '';

        var multiple = items.length > 1;
        var prevBtn = overlay.querySelector('.bg-lightbox-prev');
        var nextBtn = overlay.querySelector('.bg-lightbox-next');
        prevBtn.hidden = !multiple;
        nextBtn.hidden = !multiple;

        var counter = overlay.querySelector('.bg-lightbox-counter');
        counter.hidden = !multiple;
        if (multiple) {
            counter.textContent = (currentIndex + 1) + ' / ' + items.length;
        }

        // Preload neighbors so prev/next feels instant.
        [items[(currentIndex + 1) % items.length], items[(currentIndex - 1 + items.length) % items.length]]
            .forEach(function (neighbor) {
                if (neighbor) {
                    new Image().src = neighbor.getAttribute('href');
                }
            });
    }

    function openLightbox(groupItems, startIndex) {
        closeLightbox();

        items = groupItems;

        overlay = document.createElement('div');
        overlay.className = 'bg-lightbox-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'bg-lightbox-close';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', closeLightbox);

        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'bg-lightbox-nav bg-lightbox-prev';
        prevBtn.setAttribute('aria-label', 'Previous image');
        prevBtn.innerHTML = '&#10094;';
        prevBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            showIndex(currentIndex - 1);
        });

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'bg-lightbox-nav bg-lightbox-next';
        nextBtn.setAttribute('aria-label', 'Next image');
        nextBtn.innerHTML = '&#10095;';
        nextBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            showIndex(currentIndex + 1);
        });

        var counter = document.createElement('div');
        counter.className = 'bg-lightbox-counter';

        var img = document.createElement('img');
        img.className = 'bg-lightbox-img';

        overlay.appendChild(img);
        overlay.appendChild(closeBtn);
        overlay.appendChild(prevBtn);
        overlay.appendChild(nextBtn);
        overlay.appendChild(counter);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeLightbox();
            }
        });

        overlay.addEventListener('touchstart', onTouchStart, { passive: true });
        overlay.addEventListener('touchend', onTouchEnd, { passive: true });

        document.body.appendChild(overlay);
        document.body.classList.add('bg-lightbox-open');
        document.addEventListener('keydown', onKeydown);

        showIndex(startIndex);
        closeBtn.focus();
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.bg-lightbox');
        if (!link) {
            return;
        }

        event.preventDefault();

        var group = link.getAttribute('data-bg-lightbox');
        var groupItems = group
            ? Array.prototype.slice.call(document.querySelectorAll('.bg-lightbox[data-bg-lightbox="' + CSS.escape(group) + '"]'))
            : [link];

        openLightbox(groupItems, groupItems.indexOf(link));
    });
})();
