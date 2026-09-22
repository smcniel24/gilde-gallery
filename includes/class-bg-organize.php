<?php
/**
 * Dedicated "Organize Folders" admin page: create nested folders and drag
 * images (including multi-select) and folders around to file them. This is
 * additive to, not a replacement for, the List Mode bulk-move modal
 * (BG_Media_UI) and the Grid/Add Media picker sidebar (BG_Media_Grid) -
 * those are unaffected by this page's existence.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Organize
{
    const NONCE_ACTION = 'bg_organize_nonce';
    const UNCATEGORIZED_NODE_ID = 0;

    /** @var string */
    private static $hook = '';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'register_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        add_action('wp_ajax_bg_organize_get_folder_attachments', array(__CLASS__, 'ajax_get_folder_attachments'));
        add_action('wp_ajax_bg_organize_create_folder', array(__CLASS__, 'ajax_create_folder'));
        add_action('wp_ajax_bg_organize_rename_folder', array(__CLASS__, 'ajax_rename_folder'));
        add_action('wp_ajax_bg_organize_delete_folder', array(__CLASS__, 'ajax_delete_folder'));
        add_action('wp_ajax_bg_organize_move_folder', array(__CLASS__, 'ajax_move_folder'));
        add_action('wp_ajax_bg_organize_reorder_siblings', array(__CLASS__, 'ajax_reorder_siblings'));
    }

    /**
     * Register the submenu page under Media
     */
    public static function register_page(): void
    {
        self::$hook = add_submenu_page(
            'upload.php',
            'Organize Folders',
            'Organize Folders',
            'upload_files',
            'bildegallery-organize',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Enqueue jQuery UI (already bundled with WordPress core) plus this
     * page's own script/styles, only on this page
     */
    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== self::$hook) {
            return;
        }

        BG_Assets::enqueue_bootstrap_icons();

        wp_enqueue_style(
            'bilde-organize',
            BILDE_PLUGIN_URL . 'assets/css/organize.css',
            array(),
            BILDE_VERSION
        );

        wp_enqueue_script(
            'bilde-organize',
            BILDE_PLUGIN_URL . 'assets/js/organize.js',
            array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-sortable'),
            BILDE_VERSION,
            true
        );

        wp_localize_script('bilde-organize', 'bgOrganize', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'bulkMoveAction' => BG_Media_UI::BULK_MOVE_NONCE_ACTION,
            'bulkMoveNonce' => wp_create_nonce(BG_Media_UI::BULK_MOVE_NONCE_ACTION),
            'uncategorizedId' => self::UNCATEGORIZED_NODE_ID,
        ));
    }

    /**
     * Render the page shell: folder tree on the left, empty thumbnail
     * grid (populated by JS) on the right
     */
    public static function render_page(): void
    {
        $tree = BG_Folders::get_tree();
        ?>
        <div class="wrap bg-organize-wrap">
            <h1>Organize Folders</h1>
            <p class="description">Drag images onto a folder to file them, drag a folder onto another folder's name to move it there, or drag a folder up/down within its list to reorder it.</p>

            <div class="bg-organize-layout">
                <div class="bg-organize-sidebar">
                    <button type="button" class="button" id="bg-organize-new-folder">+ New Folder</button>

                    <ul class="bg-organize-tree" id="bg-organize-tree" data-parent-id="0">
                        <li class="bg-organize-node" data-folder-id="<?php echo esc_attr(self::UNCATEGORIZED_NODE_ID); ?>">
                            <div class="bg-organize-row">
                                <button type="button" class="bg-organize-option" data-folder-id="<?php echo esc_attr(self::UNCATEGORIZED_NODE_ID); ?>">Uncategorized</button>
                            </div>
                        </li>
                        <?php self::render_tree_nodes($tree); ?>
                    </ul>
                </div>

                <div class="bg-organize-main">
                    <div class="bg-organize-grid-header">
                        <span class="bg-organize-grid-header-label">Selected Folder:</span>
                        <span id="bg-organize-current-folder" class="bg-organize-breadcrumb">Select a folder to view its images</span>
                    </div>
                    <div class="bg-organize-shortcode-row" id="bg-organize-shortcode-row" hidden>
                        <input type="text" id="bg-organize-shortcode" class="bg-organize-shortcode-input" readonly onclick="this.select();" aria-label="Gallery shortcode for this folder">
                        <button type="button" class="button" id="bg-organize-copy-shortcode">Copy</button>
                    </div>
                    <div class="bg-organize-grid" id="bg-organize-grid"></div>
                </div>
            </div>

            <div class="bg-organize-toast" id="bg-organize-toast" hidden aria-live="polite">
                <span class="bg-organize-toast-spinner"></span>
                <span class="bg-organize-toast-message" id="bg-organize-toast-message"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Recursively render the folder tree as nested, draggable/droppable,
     * sortable-within-parent <ul>/<li> nodes
     */
    private static function render_tree_nodes(array $folders): void
    {
        foreach ($folders as $folder) {
            $id = isset($folder['id']) ? (int)$folder['id'] : 0;
            $name = $folder['text'] ?? $folder['title'] ?? ('Folder ' . $id);
            $has_children = !empty($folder['children']);
            ?>
            <li class="bg-organize-node<?php echo $has_children ? ' has-children' : ''; ?>" data-folder-id="<?php echo esc_attr($id); ?>">
                <div class="bg-organize-row">
                    <span class="bg-organize-grip" title="Drag to reorder"><i class="bi bi-grip-vertical" aria-hidden="true"></i></span>
                    <?php if ($has_children) : ?>
                        <button type="button" class="bg-organize-toggle" aria-expanded="false" aria-label="Expand">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="bg-organize-option" data-folder-id="<?php echo esc_attr($id); ?>" data-folder-name="<?php echo esc_attr($name); ?>">
                        <?php echo esc_html($name); ?>
                    </button>
                    <span class="bg-organize-actions">
                        <button type="button" class="bg-organize-add-child" data-folder-id="<?php echo esc_attr($id); ?>" aria-label="Add subfolder"><i class="bi bi-folder-plus" aria-hidden="true"></i></button>
                        <button type="button" class="bg-organize-rename" data-folder-id="<?php echo esc_attr($id); ?>" data-folder-name="<?php echo esc_attr($name); ?>" aria-label="Rename"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        <button type="button" class="bg-organize-delete" data-folder-id="<?php echo esc_attr($id); ?>" data-folder-name="<?php echo esc_attr($name); ?>" aria-label="Delete"><i class="bi bi-trash" aria-hidden="true"></i></button>
                    </span>
                </div>
                <ul class="bg-organize-branch" data-parent-id="<?php echo esc_attr($id); ?>">
                    <?php if ($has_children) {
                        self::render_tree_nodes($folder['children']);
                    } ?>
                </ul>
            </li>
            <?php
        }
    }

    /**
     * Shared guard for every AJAX handler on this page
     */
    private static function check_request(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }
    }

    /**
     * Get the thumbnail data for a folder's attachments
     */
    public static function ajax_get_folder_attachments(): void
    {
        self::check_request();

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        if ($folder_id === null) {
            wp_send_json_error(array('message' => 'No folder specified.'), 400);
        }

        $ids = BG_Folders::get_attachment_ids($folder_id);
        $items = array();

        foreach ($ids as $id) {
            $thumb = wp_get_attachment_image_url($id, 'thumbnail');
            if (!$thumb) {
                continue;
            }

            $items[] = array(
                'id' => $id,
                'thumb' => $thumb,
                'title' => get_the_title($id) ?: ('Image ' . $id),
            );
        }

        wp_send_json_success(array('items' => $items));
    }

    /**
     * Create a folder
     */
    public static function ajax_create_folder(): void
    {
        self::check_request();

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;

        if ($name === '') {
            wp_send_json_error(array('message' => 'Folder name is required.'), 400);
        }

        $result = BG_Folders::create_folder($name, $parent_id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }

        wp_send_json_success(array('id' => $result, 'name' => $name, 'parent_id' => $parent_id));
    }

    /**
     * Rename a folder
     */
    public static function ajax_rename_folder(): void
    {
        self::check_request();

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if ($folder_id <= 0 || $name === '') {
            wp_send_json_error(array('message' => 'A folder and a new name are required.'), 400);
        }

        if (!BG_Folders::rename_folder($folder_id, $name)) {
            wp_send_json_error(array('message' => 'Could not rename that folder.'), 400);
        }

        wp_send_json_success(array('id' => $folder_id, 'name' => $name));
    }

    /**
     * Delete a folder (its images become Uncategorized automatically)
     */
    public static function ajax_delete_folder(): void
    {
        self::check_request();

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
        if ($folder_id <= 0) {
            wp_send_json_error(array('message' => 'No folder specified.'), 400);
        }

        if (!BG_Folders::delete_folder($folder_id)) {
            wp_send_json_error(array('message' => 'Could not delete that folder.'), 400);
        }

        wp_send_json_success(array('id' => $folder_id));
    }

    /**
     * Move (re-parent) a folder
     */
    public static function ajax_move_folder(): void
    {
        self::check_request();

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
        $new_parent_id = isset($_POST['new_parent_id']) ? (int)$_POST['new_parent_id'] : 0;

        if ($folder_id <= 0) {
            wp_send_json_error(array('message' => 'No folder specified.'), 400);
        }

        if (!BG_Folders::move_folder($folder_id, $new_parent_id)) {
            wp_send_json_error(array('message' => "Can't move a folder into itself or one of its own subfolders."), 400);
        }

        wp_send_json_success(array('id' => $folder_id, 'parent_id' => $new_parent_id));
    }

    /**
     * Persist a new sibling order for one parent's children
     */
    public static function ajax_reorder_siblings(): void
    {
        self::check_request();

        $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
        $ordered_ids = isset($_POST['ordered_ids']) && is_array($_POST['ordered_ids'])
            ? array_map('intval', $_POST['ordered_ids'])
            : array();

        if (empty($ordered_ids)) {
            wp_send_json_error(array('message' => 'No folder order specified.'), 400);
        }

        BG_Folders::reorder_siblings($parent_id, $ordered_ids);
        wp_send_json_success();
    }
}
