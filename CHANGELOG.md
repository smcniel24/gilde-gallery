# Changelog

All notable changes to the BildeGallery plugin are documented in this file.

## 1.1.2

- **Fixed the Grid/Add Media folder sidebar showing incomplete results on larger media libraries**: on sites with more attachments than fit in one initial batch, selecting a folder there could show only a random subset of that folder's images instead of all of them, because the sidebar was filtering whatever happened to already be loaded into the browser rather than the real, complete list. It now fetches the authoritative attachment list for the selected folder directly from the database on every click, the same reliable approach the Organize Folders page already used. This only affected the Grid view/Add Media modal picker - the frontend shortcodes/blocks and the Organize Folders page were never affected.

## 1.1.1

- **Folder ID visible again in Organize Folders**: hiding the native taxonomy screen in 1.1.0 removed the easiest way to look up a folder ID for shortcodes/blocks. The breadcrumb header now shows it directly, e.g. "Selected Folder: Projects › White (folder id: 5)".
- **Copyable shortcode box**: selecting a folder in Organize Folders now shows a ready-to-paste `[bilde_gallery folder_id="…" columns="3" size="large" title="My Gallery"]` shortcode with its folder ID already filled in, plus a Copy button.
- **Creating a folder no longer collapses the tree**: adding a folder (especially a nested subfolder) now happens entirely over AJAX - the new folder is inserted directly into the tree in place, its parent (and all of that parent's own ancestors) expand automatically so it's actually visible, and it opens immediately in the grid pane. Previously this reloaded the whole page, collapsing everything back to the default view.

## 1.1.0

- **"Organize Folders" admin page**: a dedicated screen under Media for managing folders with drag-and-drop - separate from the Grid/Add Media picker sidebar, which stays focused on browsing and inserting images. Create nested folders, drag one or more selected images onto a folder to file them, drag a folder onto another folder's name to move it there, and drag a folder up/down (via a small grip handle) to reorder it among its siblings. Manual folder ordering is a small new addition to the data model (only written when a folder is actually reordered; anything untouched still sorts alphabetically as before). Rename and delete round out the page. This is purely additive - the List Mode bulk-move modal and the Grid/Add Media picker sidebar are unaffected.
- **Clearer drag-and-drop feedback**: the drop-target highlight no longer competes with the plain hover state while dragging, the grid header shows a full breadcrumb trail ("Selected Folder: Gallery › White") instead of just the current folder's name, and every action (moving images, creating/renaming/deleting/reordering folders) now shows a floating toast with a spinner while it's working instead of an easy-to-miss inline status line.
- **Decluttered the Media submenu**: the native, flat "Folders" taxonomy screen no longer appears in the menu now that Organize Folders is the real management UI, removing the confusion of two different folder-management screens.

## 1.0.0

- **GitHub-based updates**: the plugin now checks tagged releases on its public GitHub repo and shows updates in the normal Plugins screen, plus a "Check for Updates" button on the Settings page.
- **Modernized settings notices**: fixed a bug where an empty admin notice always appeared (and doubled up after saving); notices are now custom-styled with icons and a dismiss button instead of default WP admin styling.
- **Lightbox prev/next navigation**: each `[bilde_gallery]` gets its own lightbox group, with arrow-key, on-screen button, and touch-swipe navigation between that gallery's images, an image counter, and preloading of neighboring images.
- **Folder/attachment query caching**: `get_tree()` and `get_attachment_ids()` are now cached in version-keyed transients, cutting repeated database queries on pages with multiple gallery/folder/breadcrumb shortcodes. The cache invalidates automatically whenever a folder or an attachment's folder assignment changes.
- **Folder-tree picker for bulk moves**: the Media Library's "Bulk actions" dropdown no longer lists every folder as a separate flat option. A single "Move to folder…" action now opens a modal with a real expandable/collapsible folder tree and a search filter, scaling to large folder structures.
- **Native folder browsing in the Media Library**: the default Grid view (and every "Add Media" popup, including inside page builders like Divi) now shows a folder-tree sidebar next to the images, matching FileBird's browsing experience. Selecting a folder filters the visible attachments in place, with no page reload.
- **Gutenberg blocks**: `[bilde_gallery]`, `[bilde_folders]`, and `[bilde_breadcrumbs]` are now also available as blocks (grouped under a "BildeGallery" category), each with a folder-tree picker in place of typing in a numeric folder ID by hand. The blocks render through the exact same code as the shortcodes, so there's no behavioral difference between the two - just a friendlier way to configure them.

## 0.1.0

- Initial build: native `bilde_folder` taxonomy replacing the FileBird dependency, friendly folder URLs, XML gallery sitemap, `[bilde_gallery]` / `[bilde_folders]` / `[bilde_breadcrumbs]` shortcodes, minimal Media Library folder filter/column/bulk-move UI, and a one-time FileBird migration tool (Settings screen, read-only against FileBird's own tables).
