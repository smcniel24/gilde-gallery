<?php
/**
 * Minimal Media Library folder UI: a folder filter dropdown above the
 * grid/list view, a "Folder" admin column, and a bulk action to assign
 * selected attachments to a folder. Not a drag-and-drop tree - that's a
 * larger follow-on effort tracked separately.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Media_UI
{
    const BULK_MOVE_NONCE_ACTION = 'bg_bulk_move_to_folder';

    public static function init(): void
    {
        add_action('restrict_manage_posts', array(__CLASS__, 'render_folder_filter'));
        add_filter('parse_query', array(__CLASS__, 'apply_folder_filter'));

        add_filter('manage_media_columns', array(__CLASS__, 'add_folder_column'));
        add_action('manage_media_custom_column', array(__CLASS__, 'render_folder_column'), 10, 2);

        add_filter('bulk_actions-upload', array(__CLASS__, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_bulk_action'), 10, 3);

        add_action('admin_notices', array(__CLASS__, 'render_bulk_action_notice'));

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_bulk_move_assets'));
        add_action('admin_footer-upload.php', array(__CLASS__, 'render_move_modal'));
        add_action('wp_ajax_' . self::BULK_MOVE_NONCE_ACTION, array(__CLASS__, 'ajax_bulk_move_to_folder'));
    }

    /**
     * Folder filter dropdown, shown above the Media Library list view
     */
    public static function render_folder_filter(string $post_type): void
    {
        if ($post_type !== 'attachment') {
            return;
        }

        $tree = BG_Folders::get_tree();
        $selected = isset($_GET['bg_folder_filter']) ? (int)$_GET['bg_folder_filter'] : '';
        ?>
        <label for="bg-folder-filter" class="screen-reader-text">Filter by folder</label>
        <select name="bg_folder_filter" id="bg-folder-filter">
            <option value="">All folders</option>
            <option value="<?php echo esc_attr(BG_Folders::ALL_FILES_ID); ?>" <?php selected($selected, BG_Folders::ALL_FILES_ID); ?>>All Files</option>
            <option value="<?php echo esc_attr(BG_Folders::UNCATEGORIZED_ID); ?>" <?php selected($selected, BG_Folders::UNCATEGORIZED_ID); ?>>Uncategorized</option>
            <?php self::render_folder_options($tree, $selected); ?>
        </select>
        <?php
    }

    /**
     * Recursively render <option> elements with indentation for nesting
     */
    private static function render_folder_options(array $folders, $selected, int $depth = 0): void
    {
        foreach ($folders as $folder) {
            $id = isset($folder['id']) ? (int)$folder['id'] : 0;
            $name = $folder['text'] ?? $folder['title'] ?? ('Folder ' . $id);
            $prefix = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
            printf(
                '<option value="%1$d" %2$s>%3$s%4$s</option>',
                $id,
                selected($selected, $id, false),
                $prefix,
                esc_html($name)
            );

            if (!empty($folder['children'])) {
                self::render_folder_options($folder['children'], $selected, $depth + 1);
            }
        }
    }

    /**
     * Apply the folder filter to the Media Library query
     */
    public static function apply_folder_filter(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if (!isset($_GET['bg_folder_filter']) || $_GET['bg_folder_filter'] === '') {
            return;
        }

        $folder_id = (int)$_GET['bg_folder_filter'];

        if ($folder_id === BG_Folders::ALL_FILES_ID) {
            return;
        }

        if ($folder_id === BG_Folders::UNCATEGORIZED_ID) {
            $query->set('tax_query', array(
                array(
                    'taxonomy' => BG_Folders::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ),
            ));
            return;
        }

        $query->set('tax_query', array(
            array(
                'taxonomy' => BG_Folders::TAXONOMY,
                'field' => 'term_id',
                'terms' => $folder_id,
            ),
        ));
    }

    /**
     * Add a "Folder" column to the Media Library list view
     */
    public static function add_folder_column(array $columns): array
    {
        $columns['bg_folder'] = 'Folder';
        return $columns;
    }

    /**
     * Render the folder name in the "Folder" column
     */
    public static function render_folder_column(string $column_name, int $attachment_id): void
    {
        if ($column_name !== 'bg_folder') {
            return;
        }

        $terms = get_the_terms($attachment_id, BG_Folders::TAXONOMY);
        if (empty($terms) || is_wp_error($terms)) {
            echo esc_html('Uncategorized');
            return;
        }

        echo esc_html($terms[0]->name);
    }

    /**
     * Register the "Move to folder" bulk action. A single entry point -
     * selecting it and clicking Apply opens a folder-tree picker modal
     * (see render_move_modal()) instead of listing every folder as its
     * own flat dropdown option, which stops scaling once a site has more
     * than a handful of folders.
     */
    public static function register_bulk_actions(array $actions): array
    {
        $actions['bg_move_to_folder'] = 'Move to folder…';
        return $actions;
    }

    /**
     * Fallback for the "Move to folder" bulk action submitting without JS
     * (the picker modal never opened, so nothing was chosen to move to).
     */
    public static function handle_bulk_action(string $redirect_to, string $action, array $post_ids): string
    {
        if ($action !== 'bg_move_to_folder') {
            return $redirect_to;
        }

        return add_query_arg('bg_move_needs_js', '1', $redirect_to);
    }

    /**
     * Show a confirmation notice after a bulk move, or a JS-required
     * notice if the picker's own AJAX flow never ran
     */
    public static function render_bulk_action_notice(): void
    {
        if (isset($_GET['bg_moved'])) {
            $moved = (int)$_GET['bg_moved'];
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf('Moved %d item(s) to the selected folder.', $moved))
            );
            return;
        }

        if (isset($_GET['bg_move_needs_js'])) {
            echo '<div class="notice notice-error is-dismissible"><p>Moving items to a folder requires JavaScript. Nothing was moved.</p></div>';
        }
    }

    /**
     * Enqueue the folder-picker modal's script/styles on the Media Library
     * list screen only
     */
    public static function enqueue_bulk_move_assets(string $hook): void
    {
        if ($hook !== 'upload.php') {
            return;
        }

        BG_Assets::enqueue_bootstrap_icons();

        wp_enqueue_style(
            'bilde-media-bulk-move',
            BILDE_PLUGIN_URL . 'assets/css/media-bulk-move.css',
            array(),
            BILDE_VERSION
        );

        wp_enqueue_script(
            'bilde-media-bulk-move',
            BILDE_PLUGIN_URL . 'assets/js/media-bulk-move.js',
            array(),
            BILDE_VERSION,
            true
        );

        wp_localize_script('bilde-media-bulk-move', 'bgBulkMove', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::BULK_MOVE_NONCE_ACTION),
            'action' => self::BULK_MOVE_NONCE_ACTION,
        ));
    }

    /**
     * Render the hidden folder-picker modal into the Media Library page footer
     */
    public static function render_move_modal(): void
    {
        $tree = BG_Folders::get_tree();
        ?>
        <div id="bg-move-modal" class="bg-modal" hidden>
            <div class="bg-modal-backdrop" data-bg-move-dismiss></div>
            <div class="bg-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bg-move-modal-title">
                <div class="bg-modal-header">
                    <h2 id="bg-move-modal-title">Move to Folder</h2>
                    <button type="button" class="bg-modal-close" data-bg-move-dismiss aria-label="Close">&times;</button>
                </div>
                <div class="bg-modal-body">
                    <input type="search" id="bg-move-search" class="bg-move-search" placeholder="Search folders&hellip;" autocomplete="off">
                    <ul class="bg-move-tree">
                        <li class="bg-move-node">
                            <button type="button" class="bg-move-option" data-folder-id="0" data-folder-name="Uncategorized">Uncategorized</button>
                        </li>
                        <?php self::render_move_tree_nodes($tree); ?>
                    </ul>
                </div>
                <div class="bg-modal-footer">
                    <span class="bg-move-status" aria-live="polite"></span>
                    <button type="button" class="button" data-bg-move-dismiss>Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Recursively render the folder tree as nested, expandable <ul>/<li> nodes
     */
    private static function render_move_tree_nodes(array $folders): void
    {
        foreach ($folders as $folder) {
            $id = isset($folder['id']) ? (int)$folder['id'] : 0;
            $name = $folder['text'] ?? $folder['title'] ?? ('Folder ' . $id);
            $has_children = !empty($folder['children']);
            ?>
            <li class="bg-move-node<?php echo $has_children ? ' has-children' : ''; ?>">
                <div class="bg-move-row">
                    <?php if ($has_children) : ?>
                        <button type="button" class="bg-move-toggle" aria-expanded="false" aria-label="Expand">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="bg-move-option" data-folder-id="<?php echo esc_attr($id); ?>" data-folder-name="<?php echo esc_attr($name); ?>">
                        <?php echo esc_html($name); ?>
                    </button>
                </div>
                <?php if ($has_children) : ?>
                    <ul class="bg-move-branch">
                        <?php self::render_move_tree_nodes($folder['children']); ?>
                    </ul>
                <?php endif; ?>
            </li>
            <?php
        }
    }

    /**
     * AJAX handler: move the given attachments to the given folder
     */
    public static function ajax_bulk_move_to_folder(): void
    {
        check_ajax_referer(self::BULK_MOVE_NONCE_ACTION, 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Insufficient permissions.'), 403);
        }

        $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        if ($folder_id === null) {
            wp_send_json_error(array('message' => 'No destination folder specified.'), 400);
        }

        if ($folder_id > 0 && !term_exists($folder_id, BG_Folders::TAXONOMY)) {
            wp_send_json_error(array('message' => 'That folder no longer exists.'), 400);
        }

        $attachment_ids = isset($_POST['attachment_ids']) && is_array($_POST['attachment_ids'])
            ? array_map('intval', $_POST['attachment_ids'])
            : array();

        if (empty($attachment_ids)) {
            wp_send_json_error(array('message' => 'No items selected.'), 400);
        }

        $moved = 0;
        foreach ($attachment_ids as $attachment_id) {
            if (!current_user_can('edit_post', $attachment_id)) {
                continue;
            }

            if (BG_Folders::set_attachment_folder($attachment_id, $folder_id)) {
                $moved++;
            }
        }

        wp_send_json_success(array('moved' => $moved));
    }
}
