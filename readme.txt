=== BildeGallery ===
Contributors: godevtechnologies
Requires at least: 5.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Native WordPress Media Library folders with friendly URL navigation for folders and images. No FileBird (or any other third-party folder plugin) required.

== Description ==

Organizes Media Library attachments into a hierarchical folder structure (a
native `bilde_folder` taxonomy - no separate database tables, no dependency
on a third-party folder plugin), and renders folder/image galleries via
shortcodes with friendly folder URLs (e.g. `/gallery/residential/modern-homes/`),
breadcrumbs, and an auto-maintained XML sitemap of generated gallery URLs
for search engine submission.

Includes a one-time migration tool (Settings screen) that recreates an
existing FileBird folder tree and image assignments inside BildeGallery's
own taxonomy. It only reads FileBird's tables - it never writes to them -
so FileBird can stay active during and after migration until you're ready
to deactivate it.

== Shortcodes ==

* `[bilde_gallery folder_id="1" columns="3" size="large" title="My Gallery"]`
* `[bilde_folders parent_id="1"]`
* `[bilde_breadcrumbs root_id="1"]`
* `[bilde_debug_folders]`

`folder_id` / `parent_id` / `root_id` accept `-1` for the virtual "All Files"
pseudo-folder (every attachment) or `0` for "Uncategorized" (attachments
with no folder assigned), in addition to a real folder ID.

== File Structure ==

* `bildegallery.php` - plugin bootstrap: headers, constants, includes, init.
* `includes/class-bg-folders.php` - taxonomy registration, folder tree, attachment queries.
* `includes/class-bg-folder-helper.php` - generic slug/path/tree utilities shared by router, sitemap, and shortcodes.
* `includes/class-bg-settings.php` - admin menu, settings page, options.
* `includes/class-bg-router.php` - rewrite rules and query vars for friendly folder URLs.
* `includes/class-bg-sitemap.php` - sitemap index tracking and XML sitemap output.
* `includes/class-bg-shortcodes.php` - the four frontend shortcodes.
* `includes/class-bg-media-ui.php` - Media Library folder filter, column, and bulk-move action.
* `includes/class-bg-migration.php` - one-time FileBird folder/assignment importer.
* `includes/class-bg-assets.php` - frontend/admin CSS/JS enqueueing.
* `assets/css/frontend.css` - gallery, folder nav, and breadcrumb styles.
* `assets/css/admin.css` - settings page styles.
* `assets/js/admin.js` - sitemap URL copy-to-clipboard button.
* `assets/js/frontend.js` - lightbox behavior for gallery images.

== Changelog ==

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

= 0.1.0 =
* Initial build.
