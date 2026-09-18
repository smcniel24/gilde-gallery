(function () {
    'use strict';

    if (typeof wp === 'undefined' || typeof bgBlocksData === 'undefined') {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TreeSelect = wp.components.TreeSelect;
    var RangeControl = wp.components.RangeControl;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var __ = wp.i18n.__;

    // Some @wordpress packages with a single default export expose it
    // directly as the wp.<name> global, others nest it under .default -
    // handle either shape rather than assuming one.
    var ServerSideRender = wp.serverSideRender && wp.serverSideRender.default
        ? wp.serverSideRender.default
        : wp.serverSideRender;

    var data = bgBlocksData;

    // wp_localize_script() converts every value to a string - see the
    // identical fix (and the debugging session it took to find) in
    // assets/js/media-grid-folders.js. Both IDs are numeric on the PHP
    // side and need to be numbers here for consistent comparisons.
    data.allFilesId = parseInt(data.allFilesId, 10);
    data.uncategorizedId = parseInt(data.uncategorizedId, 10);

    // TreeSelect expects { id, name, children } nodes with string ids;
    // BG_Folders::get_tree() gives { id, text, title, parent, children }.
    function mapTree(folders) {
        return (folders || []).map(function (folder) {
            return {
                id: String(folder.id),
                name: folder.text || folder.title || ('Folder ' + folder.id),
                children: mapTree(folder.children),
            };
        });
    }

    function buildFolderTree() {
        return [
            { id: String(data.allFilesId), name: data.allFilesLabel, children: [] },
            { id: String(data.uncategorizedId), name: data.uncategorizedLabel, children: [] },
        ].concat(mapTree(data.tree));
    }

    var folderTree = buildFolderTree();

    function folderPicker(label, value, onChange) {
        return el(TreeSelect, {
            label: label,
            tree: folderTree,
            selectedId: value,
            onChange: onChange,
        });
    }

    function preview(blockName, attributes) {
        if (!ServerSideRender) {
            return null;
        }
        return el(ServerSideRender, {
            block: blockName,
            attributes: attributes,
        });
    }

    registerBlockType('bildegallery/gallery', {
        title: __('BildeGallery: Gallery', 'bildegallery'),
        description: __('Show the images in a BildeGallery folder.', 'bildegallery'),
        icon: 'format-gallery',
        category: 'bildegallery',
        attributes: {
            folder_id: { type: 'string', default: '' },
            columns: { type: 'number', default: 3 },
            size: { type: 'string', default: 'large' },
            title: { type: 'string', default: '' },
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return el(Fragment, {}, [
                el(InspectorControls, { key: 'inspector' },
                    el(PanelBody, { title: __('Gallery Settings', 'bildegallery') }, [
                        folderPicker(__('Folder', 'bildegallery'), attributes.folder_id, function (id) {
                            setAttributes({ folder_id: id });
                        }),
                        el(TextControl, {
                            key: 'title',
                            label: __('Title', 'bildegallery'),
                            value: attributes.title,
                            onChange: function (value) { setAttributes({ title: value }); },
                        }),
                        el(RangeControl, {
                            key: 'columns',
                            label: __('Columns', 'bildegallery'),
                            min: 1,
                            max: 6,
                            value: attributes.columns,
                            onChange: function (value) { setAttributes({ columns: value }); },
                        }),
                        el(SelectControl, {
                            key: 'size',
                            label: __('Image Size', 'bildegallery'),
                            value: attributes.size,
                            options: [
                                { label: __('Thumbnail', 'bildegallery'), value: 'thumbnail' },
                                { label: __('Medium', 'bildegallery'), value: 'medium' },
                                { label: __('Large', 'bildegallery'), value: 'large' },
                                { label: __('Full', 'bildegallery'), value: 'full' },
                            ],
                            onChange: function (value) { setAttributes({ size: value }); },
                        }),
                    ])
                ),
                preview('bildegallery/gallery', attributes),
            ]);
        },
        save: function () {
            return null;
        },
    });

    registerBlockType('bildegallery/folders', {
        title: __('BildeGallery: Child Folders', 'bildegallery'),
        description: __('Show clickable links to a folder’s subfolders.', 'bildegallery'),
        icon: 'category',
        category: 'bildegallery',
        attributes: {
            parent_id: { type: 'string', default: '' },
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return el(Fragment, {}, [
                el(InspectorControls, { key: 'inspector' },
                    el(PanelBody, { title: __('Child Folders Settings', 'bildegallery') },
                        folderPicker(__('Parent Folder', 'bildegallery'), attributes.parent_id, function (id) {
                            setAttributes({ parent_id: id });
                        })
                    )
                ),
                preview('bildegallery/folders', attributes),
            ]);
        },
        save: function () {
            return null;
        },
    });

    registerBlockType('bildegallery/breadcrumbs', {
        title: __('BildeGallery: Breadcrumbs', 'bildegallery'),
        description: __('Show a folder navigation breadcrumb trail.', 'bildegallery'),
        icon: 'menu',
        category: 'bildegallery',
        attributes: {
            root_id: { type: 'string', default: '' },
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return el(Fragment, {}, [
                el(InspectorControls, { key: 'inspector' },
                    el(PanelBody, { title: __('Breadcrumbs Settings', 'bildegallery') },
                        folderPicker(__('Root Folder', 'bildegallery'), attributes.root_id, function (id) {
                            setAttributes({ root_id: id });
                        })
                    )
                ),
                preview('bildegallery/breadcrumbs', attributes),
            ]);
        },
        save: function () {
            return null;
        },
    });
})();
