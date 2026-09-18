document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-bg-copy="gallery-sitemap"]');
    if (!button) {
        return;
    }

    var input = button.previousElementSibling;
    if (!input) {
        return;
    }

    input.focus();
    input.select();

    try {
        document.execCommand('copy');
        button.textContent = 'Copied';
        setTimeout(function () {
            button.textContent = 'Copy URL';
        }, 1200);
    } catch (e) {
        // Ignore copy errors and leave the URL selected for manual copy.
    }
});
