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
    public static function init(): void
    {
        add_action('restrict_manage_posts', array(__CLASS__, 'render_folder_filter'));
        add_filter('parse_query', array(__CLASS__, 'apply_folder_filter'));

        add_filter('manage_media_columns', array(__CLASS__, 'add_folder_column'));
        add_action('manage_media_custom_column', array(__CLASS__, 'render_folder_column'), 10, 2);

        add_filter('bulk_actions-upload', array(__CLASS__, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_bulk_action'), 10, 3);

        add_action('admin_notices', array(__CLASS__, 'render_bulk_action_notice'));
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
     * Register the "Move to folder" bulk action
     */
    public static function register_bulk_actions(array $actions): array
    {
        $tree = BG_Folders::get_tree();
        $flat = self::flatten_tree($tree);

        foreach ($flat as $folder) {
            $actions['bg_move_to_' . $folder['id']] = 'Move to: ' . $folder['label'];
        }

        $actions['bg_move_to_uncategorized'] = 'Move to: Uncategorized';

        return $actions;
    }

    /**
     * Flatten a folder tree into a list with indented labels, for use in
     * dropdown/bulk-action option lists
     */
    private static function flatten_tree(array $folders, int $depth = 0): array
    {
        $flat = array();

        foreach ($folders as $folder) {
            $id = isset($folder['id']) ? (int)$folder['id'] : 0;
            $name = $folder['text'] ?? $folder['title'] ?? ('Folder ' . $id);
            $flat[] = array(
                'id' => $id,
                'label' => str_repeat('— ', $depth) . $name,
            );

            if (!empty($folder['children'])) {
                $flat = array_merge($flat, self::flatten_tree($folder['children'], $depth + 1));
            }
        }

        return $flat;
    }

    /**
     * Handle the "Move to folder" bulk action
     */
    public static function handle_bulk_action(string $redirect_to, string $action, array $post_ids): string
    {
        if (strpos($action, 'bg_move_to_') !== 0) {
            return $redirect_to;
        }

        $target = substr($action, strlen('bg_move_to_'));
        $folder_id = $target === 'uncategorized' ? 0 : (int)$target;

        $moved = 0;
        foreach ($post_ids as $post_id) {
            if (BG_Folders::set_attachment_folder((int)$post_id, $folder_id)) {
                $moved++;
            }
        }

        return add_query_arg('bg_moved', $moved, $redirect_to);
    }

    /**
     * Show a confirmation notice after a bulk move
     */
    public static function render_bulk_action_notice(): void
    {
        if (!isset($_GET['bg_moved'])) {
            return;
        }

        $moved = (int)$_GET['bg_moved'];
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(sprintf('Moved %d item(s) to the selected folder.', $moved))
        );
    }
}
