(function () {
    'use strict';

    if (typeof bgBulkMove === 'undefined') {
        return;
    }

    var modal = null;
    var pendingIds = [];

    function getModal() {
        if (!modal) {
            modal = document.getElementById('bg-move-modal');
        }
        return modal;
    }

    function openModal(ids) {
        var el = getModal();
        if (!el) {
            return;
        }

        pendingIds = ids;
        el.hidden = false;
        el.querySelector('.bg-move-status').textContent = '';

        el.querySelectorAll('.bg-move-node.expanded').forEach(function (node) {
            node.classList.remove('expanded');
            // The toggle/option sit inside a .bg-move-row wrapper (a leaf
            // node like "Uncategorized" has neither), so querySelector's
            // first-match-in-document-order behavior is used here rather
            // than a `:scope >` direct-child selector - this node's own
            // toggle always precedes any nested branch's toggles.
            var toggle = node.querySelector('.bg-move-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        var search = el.querySelector('#bg-move-search');
        search.value = '';
        filterTree('');
        search.focus();
    }

    function closeModal() {
        var el = getModal();
        if (!el) {
            return;
        }
        el.hidden = true;
        pendingIds = [];
    }

    function filterTree(query) {
        var el = getModal();
        if (!el) {
            return;
        }

        var normalized = query.trim().toLowerCase();
        var nodes = el.querySelectorAll('.bg-move-node');

        nodes.forEach(function (node) {
            node.classList.remove('filtered-hidden', 'match-highlight');
        });

        if (normalized === '') {
            return;
        }

        nodes.forEach(function (node) {
            // See the comment in openModal() above about why this isn't a
            // `:scope >` selector - this node's own option button always
            // precedes any nested branch's options in document order.
            var option = node.querySelector('.bg-move-option');
            var name = option ? option.getAttribute('data-folder-name').toLowerCase() : '';
            if (name.indexOf(normalized) === -1) {
                node.classList.add('filtered-hidden');
            } else {
                node.classList.add('match-highlight');
                node.classList.add('expanded');
                var ancestor = node.parentElement ? node.parentElement.closest('.bg-move-node') : null;
                while (ancestor) {
                    ancestor.classList.remove('filtered-hidden');
                    ancestor.classList.add('expanded');
                    ancestor = ancestor.parentElement ? ancestor.parentElement.closest('.bg-move-node') : null;
                }
            }
        });
    }

    function moveTo(folderId) {
        var el = getModal();
        var status = el.querySelector('.bg-move-status');
        status.textContent = 'Moving…';

        var body = new URLSearchParams();
        body.append('action', bgBulkMove.action);
        body.append('nonce', bgBulkMove.nonce);
        body.append('folder_id', folderId);
        pendingIds.forEach(function (id) {
            body.append('attachment_ids[]', id);
        });

        fetch(bgBulkMove.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.success) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('bg_moved', data.data.moved);
                    window.location.href = url.toString();
                } else {
                    status.textContent = (data && data.data && data.data.message) || 'Something went wrong.';
                }
            })
            .catch(function () {
                status.textContent = 'Network error - please try again.';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var el = getModal();
        if (!el) {
            return;
        }

        el.addEventListener('click', function (event) {
            if (event.target.closest('[data-bg-move-dismiss]')) {
                closeModal();
                return;
            }

            var toggle = event.target.closest('.bg-move-toggle');
            if (toggle) {
                var node = toggle.closest('.bg-move-node');
                var expanded = node.classList.toggle('expanded');
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                return;
            }

            var option = event.target.closest('.bg-move-option');
            if (option) {
                moveTo(option.getAttribute('data-folder-id'));
            }
        });

        var search = el.querySelector('#bg-move-search');
        search.addEventListener('input', function () {
            filterTree(search.value);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !el.hidden) {
                closeModal();
            }
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('#doaction, #doaction2');
        if (!button) {
            return;
        }

        var container = button.closest('.bulkactions');
        var select = container ? container.querySelector('select') : null;
        if (!select || select.value !== 'bg_move_to_folder') {
            return;
        }

        var checked = document.querySelectorAll('input[name="media[]"]:checked');
        if (checked.length === 0) {
            return;
        }

        event.preventDefault();

        var ids = Array.prototype.map.call(checked, function (checkbox) {
            return checkbox.value;
        });

        openModal(ids);
    });
})();
