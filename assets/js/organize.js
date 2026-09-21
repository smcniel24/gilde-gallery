(function ($) {
    'use strict';

    if (typeof bgOrganize === 'undefined') {
        return;
    }

    var $tree = $('#bg-organize-tree');
    var $grid = $('#bg-organize-grid');
    var $currentLabel = $('#bg-organize-current-folder');
    var $status = $('#bg-organize-status');
    var currentFolderId = null;
    var selectedIds = [];

    function setStatus(text) {
        $status.text(text || '');
    }

    function organizeAction(action, data) {
        return $.post(bgOrganize.ajaxUrl, $.extend({ action: action, nonce: bgOrganize.nonce }, data));
    }

    function errorMessage(response, fallback) {
        return (response && response.data && response.data.message) || fallback;
    }

    // ---- Thumbnail grid ----

    function loadFolder(folderId, label) {
        currentFolderId = folderId;
        selectedIds = [];

        $tree.find('.bg-organize-option').removeClass('active');
        $tree.find('.bg-organize-option[data-folder-id="' + folderId + '"]').addClass('active');
        $currentLabel.text(label || '');
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

                // Dragging a thumbnail that isn't part of the current
                // selection drags just that one image instead.
                if (selectedIds.indexOf(id) === -1) {
                    $grid.find('.bg-organize-thumb').removeClass('selected');
                    selectedIds = [id];
                    $this.addClass('selected');
                }
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
        setStatus('Moving…');

        $.post(bgOrganize.ajaxUrl, {
            action: bgOrganize.bulkMoveAction,
            nonce: bgOrganize.bulkMoveNonce,
            folder_id: folderId,
            attachment_ids: ids,
        }).done(function (response) {
            if (response && response.success) {
                setStatus('Moved ' + response.data.moved + ' item(s).');
                loadFolder(currentFolderId, $currentLabel.text());
            } else {
                setStatus(errorMessage(response, 'Something went wrong.'));
            }
        });
    }

    // ---- Folder tree ----

    function createFolder(parentId) {
        var name = window.prompt(parentId ? 'New subfolder name:' : 'New folder name:');
        if (!name) {
            return;
        }

        organizeAction('bg_organize_create_folder', { name: name, parent_id: parentId }).done(function (response) {
            if (response && response.success) {
                // A new node has to appear at a specific nested position in
                // the tree - simplest and most reliable is to reload rather
                // than hand-build the right <li> and re-wire its widgets.
                window.location.reload();
            } else {
                setStatus(errorMessage(response, 'Could not create that folder.'));
            }
        });
    }

    function renameFolder(folderId, currentName) {
        var name = window.prompt('Rename folder:', currentName);
        if (!name || name === currentName) {
            return;
        }

        organizeAction('bg_organize_rename_folder', { folder_id: folderId, name: name }).done(function (response) {
            if (response && response.success) {
                $tree.find('.bg-organize-option[data-folder-id="' + folderId + '"]')
                    .text(name)
                    .attr('data-folder-name', name);
                $tree.find('.bg-organize-rename[data-folder-id="' + folderId + '"]').attr('data-folder-name', name);
                $tree.find('.bg-organize-delete[data-folder-id="' + folderId + '"]').attr('data-folder-name', name);

                if (currentFolderId === folderId) {
                    $currentLabel.text(name);
                }
            } else {
                setStatus(errorMessage(response, 'Could not rename that folder.'));
            }
        });
    }

    function deleteFolder(folderId, name) {
        if (!window.confirm('Delete "' + name + '"? Images inside will become Uncategorized.')) {
            return;
        }

        organizeAction('bg_organize_delete_folder', { folder_id: folderId }).done(function (response) {
            if (response && response.success) {
                // Deleting a folder can reparent its own children up a
                // level (WordPress's own wp_delete_term() behavior for
                // hierarchical taxonomies) - reload rather than trying to
                // replicate that restructuring client-side.
                window.location.reload();
            } else {
                setStatus(errorMessage(response, 'Could not delete that folder.'));
            }
        });
    }

    function moveFolder(folderId, newParentId) {
        setStatus('Moving folder…');

        organizeAction('bg_organize_move_folder', { folder_id: folderId, new_parent_id: newParentId }).done(function (response) {
            if (response && response.success) {
                window.location.reload();
            } else {
                setStatus(errorMessage(response, "Can't move a folder into itself or one of its own subfolders."));
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
            },
            stop: function (event, ui) {
                ui.item.removeClass('bg-dragging-folder');

                var parentId = parseInt($list.data('parent-id'), 10) || 0;
                var orderedIds = $list.children('li.bg-organize-node').map(function () {
                    return parseInt($(this).data('folder-id'), 10);
                }).get();

                organizeAction('bg_organize_reorder_siblings', {
                    parent_id: parentId,
                    ordered_ids: orderedIds,
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
            loadFolder(parseInt($btn.data('folder-id'), 10), $btn.data('folder-name') || $btn.text().trim());
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
