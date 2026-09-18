<?php
/**
 * Gutenberg blocks for the gallery/folders/breadcrumbs shortcodes. Each
 * block's render_callback delegates straight to the matching BG_Shortcodes
 * method - the block and the shortcode are two entry points into the same
 * rendering code, so they can never drift apart.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Blocks
{
    const EDITOR_SCRIPT_HANDLE = 'bilde-blocks-editor';

    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'register_editor_script'));
        add_action('init', array(__CLASS__, 'register_blocks'));
        add_filter('block_categories_all', array(__CLASS__, 'register_block_category'), 10, 2);
    }

    /**
     * Register (and localize) the shared editor script used by all three blocks
     */
    public static function register_editor_script(): void
    {
        wp_register_script(
            self::EDITOR_SCRIPT_HANDLE,
            BILDE_PLUGIN_URL . 'assets/js/blocks.js',
            array(
                'wp-blocks',
                'wp-element',
                'wp-block-editor',
                'wp-components',
                'wp-server-side-render',
                'wp-i18n',
            ),
            BILDE_VERSION,
            true
        );

        wp_localize_script(self::EDITOR_SCRIPT_HANDLE, 'bgBlocksData', array(
            'tree' => BG_Folders::get_tree(),
            'allFilesId' => BG_Folders::ALL_FILES_ID,
            'allFilesLabel' => BG_Folders::get_folder_label(BG_Folders::ALL_FILES_ID),
            'uncategorizedId' => BG_Folders::UNCATEGORIZED_ID,
            'uncategorizedLabel' => BG_Folders::get_folder_label(BG_Folders::UNCATEGORIZED_ID),
        ));
    }

    /**
     * Register the three blocks, delegating rendering entirely to the
     * existing shortcode methods
     */
    public static function register_blocks(): void
    {
        register_block_type('bildegallery/gallery', array(
            'api_version' => 2,
            'editor_script' => self::EDITOR_SCRIPT_HANDLE,
            'render_callback' => array('BG_Shortcodes', 'shortcode_gallery'),
            'attributes' => array(
                'folder_id' => array('type' => 'string', 'default' => ''),
                'columns' => array('type' => 'number', 'default' => 3),
                'size' => array('type' => 'string', 'default' => 'large'),
                'title' => array('type' => 'string', 'default' => ''),
            ),
        ));

        register_block_type('bildegallery/folders', array(
            'api_version' => 2,
            'editor_script' => self::EDITOR_SCRIPT_HANDLE,
            'render_callback' => array('BG_Shortcodes', 'shortcode_child_folders'),
            'attributes' => array(
                'parent_id' => array('type' => 'string', 'default' => ''),
            ),
        ));

        register_block_type('bildegallery/breadcrumbs', array(
            'api_version' => 2,
            'editor_script' => self::EDITOR_SCRIPT_HANDLE,
            'render_callback' => array('BG_Shortcodes', 'shortcode_breadcrumbs'),
            'attributes' => array(
                'root_id' => array('type' => 'string', 'default' => ''),
            ),
        ));
    }

    /**
     * Group the three blocks under their own category in the inserter
     */
    public static function register_block_category(array $categories): array
    {
        return array_merge(array(
            array(
                'slug' => 'bildegallery',
                'title' => 'BildeGallery',
            ),
        ), $categories);
    }
}
