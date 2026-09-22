(function ($) {
    'use strict';

    if (typeof wp === 'undefined' || !wp.media || !wp.media.view || !wp.media.view.AttachmentsBrowser) {
        return;
    }

    if (typeof bgMediaGridFolders === 'undefined') {
        return;
    }

    var data = bgMediaGridFolders;
    var isGridPage = document.body.classList.contains('upload-php');
    var STORAGE_KEY = 'bgGridFolder';

    // wp_localize_script() converts every value to a string, including
    // these two - so comparisons like `folderId === data.allFilesId`
    // (folderId always parsed to a real number from the DOM) were silently
    // false the whole time. Real folder IDs never hit this because both
    // sides of those comparisons happened to already be numbers.
    data.allFilesId = parseInt(data.allFilesId, 10);
    data.uncategorizedId = parseInt(data.uncategorizedId, 10);

    function buildNodeMarkup(id, name, hasChildren) {
        var $li = $('<li class="bg-grid-folder-node"></li>');
        if (hasChildren) {
            $li.addClass('has-children');
            $li.append(
                $('<button type="button" class="bg-grid-folder-toggle" aria-expanded="false"></button>')
                    .append('<i class="bi bi-chevron-right" aria-hidden="true"></i>')
            );
        }
        $li.append(
            $('<button type="button" class="bg-grid-folder-option"></button>')
                .attr('data-folder-id', id)
                .attr('data-folder-name', name)
                .text(name)
        );
        return $li;
    }

    // Appends <li> nodes directly into $parentUl - used both for the
    // top-level list (real <ul> the sidebar always shows) and, recursively,
    // for a folder's children (a nested <ul class="bg-grid-folder-branch">
    // that's collapsed by default and only shown via the "expanded" class -
    // that wrapper must never be used at the top level, or the CSS rule
    // that collapses it by default hides every real folder with no
    // "expanded" parent <li> ever able to reveal them).
    function renderNodes($parentUl, folders) {
        folders.forEach(function (folder) {
            var id = folder.id || 0;
            var name = folder.text || folder.title || ('Folder ' + id);
            var hasChildren = !!(folder.children && folder.children.length);
            var $li = buildNodeMarkup(id, name, hasChildren);

            if (hasChildren) {
                var $branch = $('<ul class="bg-grid-folder-branch"></ul>');
                renderNodes($branch, folder.children);
                $li.append($branch);
            }

            $parentUl.append($li);
        });
    }

    function buildTree() {
        var $root = $('<ul class="bg-grid-folder-tree"></ul>');
        $root.append(buildNodeMarkup(data.allFilesId, data.allFilesLabel, false));
        $root.append(buildNodeMarkup(data.uncategorizedId, data.uncategorizedLabel, false));
        renderNodes($root, data.tree || []);
        return $root;
    }

    function setActiveFolder($sidebar, folderId) {
        $sidebar.find('.bg-grid-folder-option').removeClass('active');

        var $option = $sidebar.find('.bg-grid-folder-option[data-folder-id="' + folderId + '"]');
        $option.addClass('active');

        // Expand every ancestor node so a nested selection restored on
        // load (e.g. from sessionStorage) is actually visible, not hidden
        // inside a collapsed branch the user never manually opened.
        $option.parents('.bg-grid-folder-node').each(function () {
            var $node = $(this);
            $node.addClass('expanded');
            $node.children('.bg-grid-folder-toggle').attr('aria-expanded', 'true');
        });
    }

    function rememberGridFolder(folderId) {
        if (!isGridPage) {
            return;
        }

        // The Grid page's own internal router (wp.media.view.MediaFrame.Manage)
        // manages the address bar itself and resets it shortly after load,
        // so a URL query param alone doesn't survive a hard refresh - fighting
        // WordPress for ownership of the URL isn't worth it. sessionStorage
        // is the actual persistence mechanism; the URL update below is just
        // a nice-to-have that's visible while browsing, not relied on.
        try {
            window.sessionStorage.setItem(STORAGE_KEY, folderId);
        } catch (e) {
            // Private browsing / storage disabled - persistence just won't work.
        }

        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set(data.urlParam, folderId);
            window.history.replaceState({}, '', url.toString());
        }
    }

    function getStoredGridFolder() {
        try {
            var stored = window.sessionStorage.getItem(STORAGE_KEY);
            return stored === null ? null : parseInt(stored, 10);
        } catch (e) {
            return null;
        }
    }

    // The "Add Media" modal's library is typically a live wp.media.model.Query
    // (server-paginated, refetches per filter change), but the Grid page's
    // default library turns out to be a plain local Attachments collection -
    // every attachment already loaded into the browser once, with no server
    // round trip per filter. Each needs a different filtering mechanism, so
    // detect which one we actually have rather than assuming either.
    function isLiveQuery(library) {
        return !!(wp.media.model.Query && library instanceof wp.media.model.Query);
    }

    function filterLocalLibrary(view, folderId) {
        // The Grid page's local library only ever holds whatever's
        // currently loaded into the browser (it grows as you scroll), so
        // filtering a client-side snapshot of it silently misses any of a
        // folder's images that just hadn't been paginated into view yet on
        // a large library - this worked fine in testing purely because a
        // small library is always loaded in full. Fetching the real,
        // authoritative list from the server on every folder click
        // (the same way BG_Organize's page already does successfully)
        // sidesteps that entirely instead of trusting whatever the browser
        // happens to already have.
        $.post(data.ajaxUrl, {
            action: 'bg_media_grid_folder_attachments',
            nonce: data.nonce,
            folder_id: folderId,
        }).done(function (response) {
            if (!response || !response.success) {
                return;
            }
            var freshLibrary = view.controller.state().get('library');
            freshLibrary.reset(response.data.items);
        });
    }

    function selectFolder(view, $sidebar, folderId) {
        var library = view.controller.state().get('library');

        if (library) {
            if (isLiveQuery(library)) {
                library.props.set(data.queryArg, folderId);
                library.props.set('ignore', (+new Date()));
            } else {
                filterLocalLibrary(view, folderId);
            }
        }

        setActiveFolder($sidebar, folderId);
        rememberGridFolder(folderId);
    }

    function filterSidebar($sidebar, query) {
        var normalized = query.trim().toLowerCase();
        var $nodes = $sidebar.find('.bg-grid-folder-node');

        $nodes.removeClass('filtered-hidden match-highlight');

        if (normalized === '') {
            return;
        }

        $nodes.each(function () {
            var $node = $(this);
            var name = ($node.children('.bg-grid-folder-option').attr('data-folder-name') || '').toLowerCase();
            if (name.indexOf(normalized) === -1) {
                $node.addClass('filtered-hidden');
            } else {
                $node.addClass('match-highlight').addClass('expanded');
                $node.parents('.bg-grid-folder-node').removeClass('filtered-hidden').addClass('expanded');
            }
        });
    }

    var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

    wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend({
        render: function () {
            AttachmentsBrowser.prototype.render.apply(this, arguments);
            this.bgRenderFolderSidebar();
            return this;
        },

        bgRenderFolderSidebar: function () {
            if (this.$el.hasClass('bg-has-folder-sidebar')) {
                return;
            }

            var view = this;
            var $existing = this.$el.children();
            var $main = $('<div class="bg-grid-main"></div>');
            $existing.each(function () {
                $main.append(this);
            });

            var $sidebar = $('<div class="bg-grid-folder-sidebar"></div>');
            $sidebar.append(
                $('<input type="search" class="bg-grid-folder-search" autocomplete="off">')
                    .attr('placeholder', 'Search folders…')
            );
            $sidebar.append(buildTree());

            this.$el.addClass('bg-has-folder-sidebar').append($sidebar).append($main);

            $sidebar.on('input', '.bg-grid-folder-search', function () {
                filterSidebar($sidebar, $(this).val());
            });

            $sidebar.on('click', '.bg-grid-folder-toggle', function () {
                var $node = $(this).closest('.bg-grid-folder-node');
                var expanded = $node.toggleClass('expanded').hasClass('expanded');
                $(this).attr('aria-expanded', expanded ? 'true' : 'false');
            });

            $sidebar.on('click', '.bg-grid-folder-option', function () {
                selectFolder(view, $sidebar, parseInt($(this).attr('data-folder-id'), 10));
            });

            var initialFolder = data.allFilesId;
            if (isGridPage) {
                var urlValue = new URL(window.location.href).searchParams.get(data.urlParam);
                if (urlValue !== null && urlValue !== '') {
                    initialFolder = parseInt(urlValue, 10);
                } else {
                    var stored = getStoredGridFolder();
                    if (stored !== null) {
                        initialFolder = stored;
                    }
                }
            }
            setActiveFolder($sidebar, initialFolder);
            if (initialFolder !== data.allFilesId) {
                selectFolder(view, $sidebar, initialFolder);
            }
        },
    });
})(jQuery);
