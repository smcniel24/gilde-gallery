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

    public static function init(): void
    {
        add_action('wp_enqueue_media', array(__CLASS__, 'enqueue_assets'));
        add_filter('ajax_query_attachments_args', array(__CLASS__, 'apply_folder_query_arg'));
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
}
