(function () {
    'use strict';

    var overlay = null;

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeLightbox();
        }
    }

    function closeLightbox() {
        if (!overlay) {
            return;
        }
        overlay.remove();
        overlay = null;
        document.removeEventListener('keydown', onKeydown);
        document.body.classList.remove('bg-lightbox-open');
    }

    function openLightbox(href, alt) {
        closeLightbox();

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

        var img = document.createElement('img');
        img.className = 'bg-lightbox-img';
        img.src = href;
        img.alt = alt || '';

        overlay.appendChild(img);
        overlay.appendChild(closeBtn);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeLightbox();
            }
        });

        document.body.appendChild(overlay);
        document.body.classList.add('bg-lightbox-open');
        document.addEventListener('keydown', onKeydown);
        closeBtn.focus();
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('.bg-lightbox');
        if (!link) {
            return;
        }

        event.preventDefault();

        var img = link.querySelector('img');
        var alt = img ? img.getAttribute('alt') : '';
        openLightbox(link.getAttribute('href'), alt);
    });
})();
