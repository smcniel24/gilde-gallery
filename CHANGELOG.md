# Changelog

All notable changes to the BildeGallery plugin are documented in this file.

## 1.0.0

- **GitHub-based updates**: the plugin now checks tagged releases on its public GitHub repo and shows updates in the normal Plugins screen, plus a "Check for Updates" button on the Settings page.
- **Modernized settings notices**: fixed a bug where an empty admin notice always appeared (and doubled up after saving); notices are now custom-styled with icons and a dismiss button instead of default WP admin styling.
- **Lightbox prev/next navigation**: each `[bilde_gallery]` gets its own lightbox group, with arrow-key, on-screen button, and touch-swipe navigation between that gallery's images, an image counter, and preloading of neighboring images.
- **Folder/attachment query caching**: `get_tree()` and `get_attachment_ids()` are now cached in version-keyed transients, cutting repeated database queries on pages with multiple gallery/folder/breadcrumb shortcodes. The cache invalidates automatically whenever a folder or an attachment's folder assignment changes.
- **Folder-tree picker for bulk moves**: the Media Library's "Bulk actions" dropdown no longer lists every folder as a separate flat option. A single "Move to folder…" action now opens a modal with a real expandable/collapsible folder tree and a search filter, scaling to large folder structures.

## 0.1.0

- Initial build: native `bilde_folder` taxonomy replacing the FileBird dependency, friendly folder URLs, XML gallery sitemap, `[bilde_gallery]` / `[bilde_folders]` / `[bilde_breadcrumbs]` shortcodes, minimal Media Library folder filter/column/bulk-move UI, and a one-time FileBird migration tool (Settings screen, read-only against FileBird's own tables).
