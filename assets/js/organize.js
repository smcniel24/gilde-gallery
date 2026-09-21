(function ($) {
    'use strict';

    if (typeof bgOrganize === 'undefined') {
        return;
    }

    var $tree = $('#bg-organize-tree');
    var $grid = $('#bg-organize-grid');
    var $currentLabel = $('#bg-organize-current-folder');
    var $toast = $('#bg-organize-toast');
    var $toastMessage = $('#bg-organize-toast-message');
    var currentFolderId = null;
    var selectedIds = [];
    var toastHideTimer = null;

    // Shown while an action is in flight (no auto-hide), then either
    // auto-hides shortly after success or stays a bit longer as an error.
    function showToast(message, options) {
        options = options || {};
        clearTimeout(toastHideTimer);
        $toastMessage.text(message);
        $toast.toggleClass('bg-organize-toast-error', !!options.error).prop('hidden', false);
        if (options.autoHideMs) {
            toastHideTimer = setTimeout(hideToast, options.autoHideMs);
        }
    }

    function hideToast() {
        $toast.prop('hidden', true);
    }

    function organizeAction(action, data) {
        return $.post(bgOrganize.ajaxUrl, $.extend({ action: action, nonce: bgOrganize.nonce }, data));
    }

    function errorMessage(response, fallback) {
        return (response && response.data && response.data.message) || fallback;
    }

    // Walks up from a folder's option button through its ancestor nodes to
    // build a "Parent › Child" breadcrumb for the grid header.
    function buildBreadcrumb($option) {
        var names = [$option.data('folder-name') || $option.text().trim()];

        $option.closest('li.bg-organize-node').parents('li.bg-organize-node').each(function () {
            var name = $(this).children('.bg-organize-row').find('.bg-organize-option').first().data('folder-name');
            if (name) {
                names.unshift(name);
            }
        });

        return names;
    }

    function renderBreadcrumb(names) {
        $currentLabel.empty();
        names.forEach(function (name, index) {
            if (index > 0) {
                $currentLabel.append('<span class="bg-organize-crumb-sep">›</span>');
            }
            $currentLabel.append($('<span></span>').text(name));
        });
    }

    // ---- Thumbnail grid ----

    function loadFolder(folderId, breadcrumbNames) {
        currentFolderId = folderId;
        selectedIds = [];

        $tree.find('.bg-organize-option').removeClass('active');
        $tree.find('.bg-organize-option[data-folder-id="' + folderId + '"]').addClass('active');
        renderBreadcrumb(breadcrumbNames || []);
        $grid.html('<div class="bg-organize-grid-empty">Loading…</div>');

        organizeAction('bg_organize_get_folder_attachments', { folder_id: folderId }).done(function (response) {
            if (!response || !response.success) {
                $grid.html('<div class="bg-organize-grid-empty">Could not load images.</div>');
                return;
            }
            renderGrid(response.data.items);
        });
    }

    function renderGrid(items) {
        $grid.empty();

        if (!items.length) {
            $grid.html('<div class="bg-organize-grid-empty">No images in this folder.</div>');
            return;
        }

        items.forEach(function (item) {
            var $thumb = $('<div class="bg-organize-thumb"></div>')
                .attr('data-attachment-id', item.id)
                .attr('title', item.title);
            $thumb.append($('<img loading="lazy">').attr('src', item.thumb).attr('alt', item.title));
            $grid.append($thumb);
        });

        initThumbInteractions();
    }

    function initThumbInteractions() {
        var $thumbs = $grid.find('.bg-organize-thumb');

        $thumbs.on('click', function () {
            var $t = $(this);
            var id = parseInt($t.data('attachment-id'), 10);
            var index = selectedIds.indexOf(id);

            if (index === -1) {
                selectedIds.push(id);
                $t.addClass('selected');
            } else {
                selectedIds.splice(index, 1);
                $t.removeClass('selected');
            }
        });

        $thumbs.draggable({
            distance: 5,
            revert: 'invalid',
            zIndex: 100000,
            cursor: 'grabbing',
            appendTo: 'body',
            start: function () {
                var $this = $(this);
                var id = parseInt($this.data('attachment-id'), 10);

                $('body').addClass('bg-organize-dragging');

                // Dragging a thumbnail that isn't part of the current
                // selection drags just that one image instead.
                if (selectedIds.indexOf(id) === -1) {
                    $grid.find('.bg-organize-thumb').removeClass('selected');
                    selectedIds = [id];
                    $this.addClass('selected');
                }
            },
            stop: function () {
                $('body').removeClass('bg-organize-dragging');
            },
            helper: function () {
                var id = parseInt($(this).data('attachment-id'), 10);
                var ids = selectedIds.indexOf(id) === -1 ? [id] : selectedIds.slice();

                var $helper = $('<div class="bg-organize-drag-helper"></div>');
                ids.slice(0, 3).forEach(function (dragId) {
                    var src = $grid.find('.bg-organize-thumb[data-attachment-id="' + dragId + '"] img').attr('src');
                    $helper.append($('<img>').attr('src', src));
                });
                if (ids.length > 1) {
                    $helper.append('<span class="bg-organize-drag-count">' + ids.length + '</span>');
                }
                return $helper;
            },
        });
    }

    function moveAttachments(ids, folderId) {
        showToast(ids.length > 1 ? 'Moving ' + ids.length + ' images…' : 'Moving image…');

        $.post(bgOrganize.ajaxUrl, {
            action: bgOrganize.bulkMoveAction,
            nonce: bgOrganize.bulkMoveNonce,
            folder_id: folderId,
            attachment_ids: ids,
        }).done(function (response) {
            if (response && response.success) {
                showToast('Moved ' + response.data.moved + ' item(s).', { autoHideMs: 1500 });
                loadFolder(currentFolderId, buildBreadcrumb($tree.find('.bg-organize-option.active')));
            } else {
                showToast(errorMessage(response, 'Something went wrong.'), { error: true, autoHideMs: 4000 });
            }
        });
    }

    // ---- Folder tree ----

    function createFolder(parentId) {
        var name = window.prompt(parentId ? 'New subfolder name:' : 'New folder name:');
        if (!name) {
            return;
        }

        showToast('Creating folder…');

        organizeAction('bg_organize_create_folder', { name: name, parent_id: parentId }).done(function (response) {
            if (response && response.success) {
                // A new node has to appear at a specific nested position in
                // the tree - simplest and most reliable is to reload rather
                // than hand-build the right <li> and re-wire its widgets.
                window.location.reload();
            } else {
                showToast(errorMessage(response, 'Could not create that folder.'), { error: true, autoHideMs: 4000 });
            }
        });
    }

    function renameFolder(folderId, currentName) {
        var name = window.prompt('Rename folder:', currentName);
        if (!name || name === currentName) {
            return;
        }

        showToast('Renaming folder…');

        organizeAction('bg_organize_rename_folder', { folder_id: folderId, name: name }).done(function (response) {
            if (response && response.success) {
                var $option = $tree.find('.bg-organize-option[data-folder-id="' + folderId + '"]')
                    .text(name)
                    .attr('data-folder-name', name);
                $tree.find('.bg-organize-rename[data-folder-id="' + folderId + '"]').attr('data-folder-name', name);
                $tree.find('.bg-organize-delete[data-folder-id="' + folderId + '"]').attr('data-folder-name', name);

                if (currentFolderId === folderId) {
                    renderBreadcrumb(buildBreadcrumb($option));
                }

                hideToast();
            } else {
                showToast(errorMessage(response, 'Could not rename that folder.'), { error: true, autoHideMs: 4000 });
            }
        });
    }

    function deleteFolder(folderId, name) {
        if (!window.confirm('Delete "' + name + '"? Images inside will become Uncategorized.')) {
            return;
        }

        showToast('Deleting folder…');

        organizeAction('bg_organize_delete_folder', { folder_id: folderId }).done(function (response) {
            if (response && response.success) {
                // Deleting a folder can reparent its own children up a
                // level (WordPress's own wp_delete_term() behavior for
                // hierarchical taxonomies) - reload rather than trying to
                // replicate that restructuring client-side.
                window.location.reload();
            } else {
                showToast(errorMessage(response, 'Could not delete that folder.'), { error: true, autoHideMs: 4000 });
            }
        });
    }

    function moveFolder(folderId, newParentId) {
        showToast('Moving folder…');

        organizeAction('bg_organize_move_folder', { folder_id: folderId, new_parent_id: newParentId }).done(function (response) {
            if (response && response.success) {
                window.location.reload();
            } else {
                showToast(errorMessage(response, "Can't move a folder into itself or one of its own subfolders."), { error: true, autoHideMs: 4000 });
            }
        });
    }

    function initSortableList($list) {
        $list.sortable({
            items: '> li.bg-organize-node:not([data-folder-id="' + bgOrganize.uncategorizedId + '"])',
            handle: '.bg-organize-grip',
            placeholder: 'ui-sortable-placeholder',
            tolerance: 'pointer',
            start: function (event, ui) {
                ui.item.addClass('bg-dragging-folder');
                $('body').addClass('bg-organize-dragging');
            },
            stop: function (event, ui) {
                ui.item.removeClass('bg-dragging-folder');
                $('body').removeClass('bg-organize-dragging');

                var parentId = parseInt($list.data('parent-id'), 10) || 0;
                var orderedIds = $list.children('li.bg-organize-node').map(function () {
                    return parseInt($(this).data('folder-id'), 10);
                }).get();

                showToast('Saving order…');
                organizeAction('bg_organize_reorder_siblings', {
                    parent_id: parentId,
                    ordered_ids: orderedIds,
                }).done(function (response) {
                    if (response && response.success) {
                        showToast('Order saved.', { autoHideMs: 1200 });
                    } else {
                        showToast(errorMessage(response, 'Could not save that order.'), { error: true, autoHideMs: 4000 });
                    }
                });
            },
        });
    }

    function initFolderDragDrop($option) {
        var folderId = parseInt($option.data('folder-id'), 10);

        // The "Uncategorized" pseudo-folder can't itself be dragged
        // elsewhere or accept another folder being filed under it.
        if (folderId !== bgOrganize.uncategorizedId) {
            $option.draggable({
                distance: 5,
                revert: 'invalid',
                helper: 'clone',
                appendTo: 'body',
                zIndex: 100000,
                cursor: 'move',
                start: function () {
                    $('body').addClass('bg-organize-dragging');
                },
                stop: function () {
                    $('body').removeClass('bg-organize-dragging');
                },
            });
        }

        $option.droppable({
            accept: function ($dragged) {
                if ($dragged.hasClass('bg-organize-thumb')) {
                    return true;
                }
                if ($dragged.hasClass('bg-organize-option')) {
                    var draggedId = parseInt($dragged.data('folder-id'), 10);
                    return draggedId !== folderId && folderId !== bgOrganize.uncategorizedId;
                }
                return false;
            },
            hoverClass: 'bg-drop-hover',
            drop: function (event, ui) {
                var $dragged = ui.draggable;

                if ($dragged.hasClass('bg-organize-thumb')) {
                    var attachmentId = parseInt($dragged.data('attachment-id'), 10);
                    var ids = selectedIds.indexOf(attachmentId) === -1 ? [attachmentId] : selectedIds.slice();
                    moveAttachments(ids, folderId);
                    return;
                }

                if ($dragged.hasClass('bg-organize-option')) {
                    moveFolder(parseInt($dragged.data('folder-id'), 10), folderId);
                }
            },
        });
    }

    function initTreeInteractions() {
        $tree.on('click', '.bg-organize-toggle', function () {
            var $node = $(this).closest('.bg-organize-node');
            var expanded = $node.toggleClass('expanded').hasClass('expanded');
            $(this).attr('aria-expanded', expanded ? 'true' : 'false');
        });

        $tree.on('click', '.bg-organize-option', function () {
            var $btn = $(this);
            loadFolder(parseInt($btn.data('folder-id'), 10), buildBreadcrumb($btn));
        });

        $tree.on('click', '.bg-organize-add-child', function (event) {
            event.stopPropagation();
            createFolder(parseInt($(this).data('folder-id'), 10));
        });

        $tree.on('click', '.bg-organize-rename', function (event) {
            event.stopPropagation();
            renameFolder(parseInt($(this).data('folder-id'), 10), $(this).data('folder-name'));
        });

        $tree.on('click', '.bg-organize-delete', function (event) {
            event.stopPropagation();
            deleteFolder(parseInt($(this).data('folder-id'), 10), $(this).data('folder-name'));
        });

        $('#bg-organize-new-folder').on('click', function () {
            createFolder(0);
        });

        initSortableList($tree);
        $tree.find('.bg-organize-branch').each(function () {
            initSortableList($(this));
        });

        $tree.find('.bg-organize-option').each(function () {
            initFolderDragDrop($(this));
        });
    }

    $(document).ready(function () {
        initTreeInteractions();
    });
})(jQuery);
