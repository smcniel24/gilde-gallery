<?php
/**
 * Frontend shortcodes: gallery, child folders, breadcrumbs, debug.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Shortcodes
{
    public static function init(): void
    {
        add_shortcode('bilde_gallery', array(__CLASS__, 'shortcode_gallery'));
        add_shortcode('bilde_folders', array(__CLASS__, 'shortcode_child_folders'));
        add_shortcode('bilde_breadcrumbs', array(__CLASS__, 'shortcode_breadcrumbs'));
        add_shortcode('bilde_debug_folders', array(__CLASS__, 'shortcode_debug'));
    }

    /**
     * Parse a shortcode ID attribute that may legitimately be a "virtual"
     * value (-1 = All Files, 0 = Uncategorized) as well as a real positive
     * folder ID. Returns null if the attribute was never provided.
     */
    private static function parse_folder_id_attr($raw): ?int
    {
        if ($raw === '' || $raw === null || !is_numeric($raw)) {
            return null;
        }

        return (int)$raw;
    }

    /**
     * GALLERY SHORTCODE
     */
    public static function shortcode_gallery(array $atts): string
    {
        $atts = shortcode_atts(
            array(
                'folder_id' => '',
                'size'      => 'large',
                'columns'   => 3,
                'title'     => '',
            ),
            $atts,
            'bilde_gallery'
        );

        $root_folder_id = self::parse_folder_id_attr($atts['folder_id']);
        if ($root_folder_id === null) {
            return '<p>No gallery folder selected.</p>';
        }

        $folder_id = BG_Folder_Helper::get_current_folder_id($root_folder_id);

        $ids = BG_Folders::get_attachment_ids($folder_id);
        if (empty($ids)) {
            return '<p>No images found in this gallery.</p>';
        }

        $cols = max(1, min(6, (int)$atts['columns']));
        $title = trim($atts['title']);
        $is_root_view = ($folder_id === $root_folder_id);

        ob_start();

        if ($title !== '' && $is_root_view) {
            echo '<h2 class="bg-gallery-title">' . esc_html($title) . '</h2>';
        }
        ?>

        <div class="bg-gallery bg-cols-<?php echo esc_attr($cols); ?>">
            <?php foreach ($ids as $id) :
                $id = (int)$id;
                $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                if (!$alt) {
                    $alt = get_the_title($id);
                }

                $full_url = wp_get_attachment_image_url($id, 'full');
                $img = wp_get_attachment_image(
                    $id,
                    $atts['size'],
                    false,
                    array(
                        'alt'     => $alt,
                        'class'   => 'bg-gallery-img',
                        'loading' => 'lazy',
                    )
                );
                ?>
                <figure class="bg-gallery-item">
                    <a href="<?php echo esc_url($full_url); ?>" class="bg-lightbox" data-bg-lightbox="gallery">
                        <?php echo $img; ?>
                    </a>
                </figure>
            <?php endforeach; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    /**
     * CHILD FOLDERS SHORTCODE
     */
    public static function shortcode_child_folders(array $atts): string
    {
        $atts = shortcode_atts(
            array(
                'parent_id' => '',
            ),
            $atts,
            'bilde_folders'
        );

        $root_id = self::parse_folder_id_attr($atts['parent_id']);
        if ($root_id === null) {
            return '';
        }

        $parent_id = BG_Folder_Helper::get_current_folder_id($root_id);
        $folder_style = BG_Settings::get_folder_style();

        $tree = BG_Folders::get_tree();
        if (empty($tree) || $parent_id <= 0) {
            return '';
        }

        $parent_folder = BG_Folder_Helper::find_folder_by_id($tree, $parent_id);
        if (!$parent_folder || empty($parent_folder['children'])) {
            return '';
        }

        $children = $parent_folder['children'];
        $base_url = get_permalink();

        ob_start(); ?>
        <div class="bg-folder-nav bg-folder-style-<?php echo esc_attr($folder_style); ?>">
            <?php foreach ($children as $child) :
                $child_id = isset($child['id']) ? (int)$child['id'] : 0;
                $child_name = $child['text'] ?? $child['title'] ?? ('Folder ' . $child_id);
                $child_slug = BG_Folder_Helper::folder_name_to_slug($child_name);

                // Build friendly URL path
                $current_path = BG_Folder_Helper::build_folder_slug_path($tree, $parent_id, $root_id);
                if ($current_path !== '') {
                    $url = trailingslashit($base_url) . $current_path . '/' . $child_slug . '/';
                } else {
                    $url = trailingslashit($base_url) . $child_slug . '/';
                }
                ?>
                <a class="bg-folder-link bg-folder-style-<?php echo esc_attr($folder_style); ?>" href="<?php echo esc_url($url); ?>">
                    <i class="bi bi-folder-fill bg-folder-icon"></i>
                    <?php echo esc_html($child_name); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * BREADCRUMBS SHORTCODE
     */
    public static function shortcode_breadcrumbs(array $atts): string
    {
        $atts = shortcode_atts(
            array(
                'root_id' => '',
            ),
            $atts,
            'bilde_breadcrumbs'
        );

        $root_id = self::parse_folder_id_attr($atts['root_id']);
        if ($root_id === null) {
            return '';
        }

        $tree = BG_Folders::get_tree();
        if (empty($tree) && $root_id > 0) {
            return '';
        }

        $current_id = BG_Folder_Helper::get_current_folder_id($root_id);

        // Build path from current folder up to root
        $path = array();
        $id = $current_id;

        while ($id > 0) {
            $folder = BG_Folder_Helper::find_folder_by_id($tree, $id);
            if (!$folder) {
                break;
            }

            $path[] = $folder;

            if (isset($folder['id']) && (int)$folder['id'] === $root_id) {
                break;
            }

            $parent = isset($folder['parent']) ? (int)$folder['parent'] : 0;
            if ($parent === 0) {
                break;
            }

            $id = $parent;
        }

        $root_label = BG_Folders::get_folder_label($root_id);
        $base_url = get_permalink();

        if (empty($path)) {
            // Still show the home crumb for virtual roots (All
            // Files / Uncategorized) even with nothing nested beneath.
            if ($root_id > 0) {
                return '';
            }
        }

        $path = array_reverse($path);

        ob_start(); ?>
        <nav class="bg-breadcrumbs" aria-label="Breadcrumb">
            <a href="<?php echo esc_url($base_url); ?>" class="bg-bc-link bg-bc-home">
                <i class="bi bi-house-door-fill bg-bc-home-icon"></i>
                <?php echo esc_html($root_label); ?>
            </a>

            <?php
            $total = count($path);
            $accumulated_path = '';

            foreach ($path as $index => $folder) :
                if (isset($folder['id']) && (int)$folder['id'] === $root_id) {
                    continue;
                }

                $folder_id = isset($folder['id']) ? (int)$folder['id'] : 0;
                $name = $folder['text'] ?? $folder['title'] ?? ('Folder ' . $folder_id);
                $slug = BG_Folder_Helper::folder_name_to_slug($name);

                if ($accumulated_path !== '') {
                    $accumulated_path .= '/' . $slug;
                } else {
                    $accumulated_path = $slug;
                }
                ?>
                <span class="bg-bc-sep">&rsaquo;</span>

                <?php if ($index === $total - 1) : ?>
                    <span class="bg-bc-current"><?php echo esc_html($name); ?></span>
                <?php else :
                    $url = trailingslashit($base_url) . $accumulated_path . '/';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" class="bg-bc-link">
                        <?php echo esc_html($name); ?>
                    </a>
                <?php endif; ?>

            <?php endforeach; ?>
        </nav>
        <?php

        return ob_get_clean();
    }

    /**
     * DEBUG SHORTCODE
     */
    public static function shortcode_debug(): string
    {
        $tree = BG_Folders::get_tree();

        ob_start();
        echo '<pre>';
        var_export($tree);
        echo '</pre>';
        return ob_get_clean();
    }
}
