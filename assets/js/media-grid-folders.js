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

    function filterLocalLibrary(view, library, folderId) {
        // Cache plain attribute data (not live Backbone model objects) from
        // a pristine, pre-filtering snapshot. Re-using the same model
        // *instances* across multiple reset() calls proved unreliable -
        // once a model is reset out of the collection, WP's media models
        // appear to do internal cleanup that leaves them unusable if
        // reset back in later (re-selecting "All Files" after visiting a
        // folder showed nothing instead of everything, even though the
        // exact same cached objects were being passed back in). Passing
        // plain attribute hashes to reset() instead makes Backbone build
        // brand-new model instances each time, sidestepping that entirely.
        //
        // Only accept a new snapshot when it's at least as large as what's
        // already cached, so a capture that fires before the library has
        // fully loaded can't permanently lock in an incomplete result.
        if (!view.bgAllAttrs || library.models.length > view.bgAllAttrs.length) {
            view.bgAllAttrs = library.models.map(function (model) {
                return model.toJSON();
            });
        }

        var filtered = folderId === data.allFilesId
            ? view.bgAllAttrs.slice()
            : view.bgAllAttrs.filter(function (attrs) {
                var value = typeof attrs[data.queryArg] === 'number' ? attrs[data.queryArg] : data.allFilesId;
                return folderId === data.uncategorizedId ? (value <= 0) : (value === folderId);
            });

        // Confirmed via live debugging: calling reset() on a *freshly
        // fetched* library reference from the console works every time,
        // but reset() on the `library` object captured earlier in this
        // call - even deferred with setTimeout - produced 0 models with
        // no error. That points to the active state's library sometimes
        // being swapped for a different collection object in between, so
        // re-fetch it fresh right before actually resetting it instead of
        // trusting the reference captured above.
        setTimeout(function () {
            var freshLibrary = view.controller.state().get('library');
            freshLibrary.reset(filtered);
        }, 0);
    }

    function selectFolder(view, $sidebar, folderId) {
        var library = view.controller.state().get('library');

        if (library) {
            if (isLiveQuery(library)) {
                library.props.set(data.queryArg, folderId);
                library.props.set('ignore', (+new Date()));
            } else {
                filterLocalLibrary(view, library, folderId);
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
