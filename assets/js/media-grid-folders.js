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

    function renderBranch(folders) {
        var $ul = $('<ul class="bg-grid-folder-branch"></ul>');
        folders.forEach(function (folder) {
            var id = folder.id || 0;
            var name = folder.text || folder.title || ('Folder ' + id);
            var hasChildren = !!(folder.children && folder.children.length);
            var $li = buildNodeMarkup(id, name, hasChildren);

            if (hasChildren) {
                $li.append(renderBranch(folder.children));
            }

            $ul.append($li);
        });
        return $ul;
    }

    function buildTree() {
        var $root = $('<ul class="bg-grid-folder-tree"></ul>');
        $root.append(buildNodeMarkup(data.allFilesId, data.allFilesLabel, false));
        $root.append(buildNodeMarkup(data.uncategorizedId, data.uncategorizedLabel, false));
        $root.append(renderBranch(data.tree || []));
        return $root;
    }

    function setActiveFolder($sidebar, folderId) {
        $sidebar.find('.bg-grid-folder-option').removeClass('active');
        $sidebar.find('.bg-grid-folder-option[data-folder-id="' + folderId + '"]').addClass('active');
    }

    function updateGridUrl(folderId) {
        if (!isGridPage || !window.history || !window.history.replaceState) {
            return;
        }
        var url = new URL(window.location.href);
        url.searchParams.set(data.urlParam, folderId);
        window.history.replaceState({}, '', url.toString());
    }

    function selectFolder(view, $sidebar, folderId) {
        var library = view.controller.state().get('library');
        if (library && library.props) {
            library.props.set(data.queryArg, folderId);
        }
        setActiveFolder($sidebar, folderId);
        updateGridUrl(folderId);
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
                selectFolder(view, $sidebar, $(this).attr('data-folder-id'));
            });

            var initialFolder = data.allFilesId;
            if (isGridPage) {
                var urlValue = new URL(window.location.href).searchParams.get(data.urlParam);
                if (urlValue !== null && urlValue !== '') {
                    initialFolder = parseInt(urlValue, 10);
                }
            }
            setActiveFolder($sidebar, initialFolder);
            if (initialFolder !== data.allFilesId) {
                selectFolder(view, $sidebar, initialFolder);
            }
        },
    });
})(jQuery);
