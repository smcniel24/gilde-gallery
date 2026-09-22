<?php
/**
 * Folder-tree sidebar injected into WordPress's native wp.media grid view
 * (Media -> Library) and every "Add Media" picker modal (block editor, page
 * builders like Divi, ACF, etc.) - anywhere that shares the same underlying
 * Backbone AttachmentsBrowser. Server side, this only needs to enqueue the
 * client script/styles and extend the one filter WP core provides for this
 * exact purpose; the actual sidebar and query wiring live in
 * assets/js/media-grid-folders.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Media_Grid
{
    const QUERY_ARG = 'bg_folder';
    const NONCE_ACTION = 'bg_media_grid_nonce';

    public static function init(): void
    {
        add_action('wp_enqueue_media', array(__CLASS__, 'enqueue_assets'));
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'apply_folder_query_arg'));
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'add_folder_to_js_data'), 10, 2);
        add_action('wp_ajax_bg_media_grid_folder_attachments', array(__CLASS__, 'ajax_get_folder_attachments'));
    }

    /**
     * Enqueue the sidebar script/styles wherever wp.media is set up -
     * both the Grid page itself and any screen that later opens an
     * "Add Media" popup (wp_enqueue_media() fires in both cases)
     */
    public static function enqueue_assets(): void
    {
        BG_Assets::enqueue_bootstrap_icons();

        wp_enqueue_style(
            'bilde-media-grid-folders',
            BILDE_PLUGIN_URL . 'assets/css/media-grid-folders.css',
            array(),
            BILDE_VERSION
        );

        wp_enqueue_script(
            'bilde-media-grid-folders',
            BILDE_PLUGIN_URL . 'assets/js/media-grid-folders.js',
            array('media-views'),
            BILDE_VERSION,
            true
        );

        wp_localize_script('bilde-media-grid-folders', 'bgMediaGridFolders', array(
            'tree' => BG_Folders::get_tree(),
            'allFilesId' => BG_Folders::ALL_FILES_ID,
            'allFilesLabel' => BG_Folders::get_folder_label(BG_Folders::ALL_FILES_ID),
            'uncategorizedId' => BG_Folders::UNCATEGORIZED_ID,
            'uncategorizedLabel' => BG_Folders::get_folder_label(BG_Folders::UNCATEGORIZED_ID),
            'queryArg' => self::QUERY_ARG,
            'urlParam' => 'bg_folder_filter',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        ));
    }

    /**
     * Inject a tax_query into the classic media-library AJAX query
     * (query-attachments, used by both the Grid view and every Add Media
     * modal) when the client set our custom bg_folder prop on the
     * library query's props model.
     */
    public static function apply_folder_query_arg(array $args): array
    {
        if (!isset($args[self::QUERY_ARG]) || $args[self::QUERY_ARG] === '') {
            return $args;
        }

        $folder_id = (int)$args[self::QUERY_ARG];
        unset($args[self::QUERY_ARG]);

        $tax_query = BG_Folders::get_tax_query_for_folder($folder_id);
        if ($tax_query !== null) {
            $args['tax_query'] = $tax_query;
        }

        return $args;
    }

    /**
     * Expose each attachment's folder on its JS/Backbone model. The Grid
     * page's default library turns out to be a plain local collection
     * (every attachment loaded into the browser once, not re-queried from
     * the server per filter change like the Add Media modal's does), so
     * without this, the client has no way to know which folder an
     * already-loaded attachment belongs to for local filtering.
     */
    public static function add_folder_to_js_data(array $response, $attachment): array
    {
        $terms = get_the_terms($attachment->ID, BG_Folders::TAXONOMY);
        $response[self::QUERY_ARG] = (!empty($terms) && !is_wp_error($terms))
            ? (int)$terms[0]->term_id
            : BG_Folders::UNCATEGORIZED_ID;

        return $response;
    }

    /**
     * Return the full, authoritative attachment list for a folder, for the
     * Grid page's local (non-Query) library to reset() itself to.
     *
     * That library only ever holds whatever's currently loaded into the
     * browser (it grows as you scroll), so filtering it client-side against
     * a snapshot silently misses any of a folder's images that just hadn't
     * been paginated into view yet on a large library - it happened to work
     * on small test libraries purely because everything was always already
     * loaded. Fetching the real list from the database here, the same way
     * BG_Organize's page already does successfully, sidesteps that
     * entirely instead of relying on whatever the browser happens to have.
     */
    public static function ajax_get_folder_attachments(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        if ($folder_id === null) {
            wp_send_json_error(array('message' => 'No folder specified.'), 400);
        }

        $ids = BG_Folders::get_attachment_ids($folder_id);
        $items = array();

        foreach ($ids as $id) {
            $data = wp_prepare_attachment_for_js($id);
            if ($data) {
                $items[] = $data;
            }
        }

        wp_send_json_success(array('items' => $items));
    }
}
