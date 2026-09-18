<?php
/**
 * Gallery sitemap index tracking + XML sitemap rendering.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Sitemap
{
    const SITEMAP_QUERY_VAR = 'bilde_gallery_sitemap';
    const OPTION_SITEMAP_INDEX = 'bilde_sitemap_index';
    const OPTION_SITEMAP_INDEX_UPDATED = 'bilde_sitemap_index_updated';

    public static function init(): void
    {
        add_action('parse_request', array(__CLASS__, 'render_gallery_sitemap'), 0);
        add_action('template_redirect', array(__CLASS__, 'render_gallery_sitemap'));

        // Keep sitemap index in sync with page content
        add_action('save_post_page', array(__CLASS__, 'handle_page_save'), 10, 3);
        add_action('before_delete_post', array(__CLASS__, 'handle_page_delete'));

        // Manual sitemap index rebuild
        add_action('admin_post_bg_rebuild_sitemap_index', array(__CLASS__, 'handle_rebuild_sitemap_index'));
    }

    /**
     * Update sitemap index when a page is saved
     */
    public static function handle_page_save(int $post_id, $post, bool $update): void
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (!$post instanceof WP_Post) {
            return;
        }

        if ($post->post_status !== 'publish') {
            self::remove_page_from_sitemap_index($post_id);
            return;
        }

        self::update_sitemap_index_for_page($post_id, $post->post_content);
    }

    /**
     * Remove sitemap index entry when a page is deleted
     */
    public static function handle_page_delete(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') {
            return;
        }

        self::remove_page_from_sitemap_index($post_id);
    }

    /**
     * Build absolute URL for the gallery sitemap
     */
    public static function get_gallery_sitemap_url(): string
    {
        return home_url('/gallery-sitemap.xml');
    }

    /**
     * Render XML sitemap of folder galleries
     */
    public static function render_gallery_sitemap($wp = null): void
    {
        if (is_admin()) {
            return;
        }

        $is_query_var = (int)get_query_var(self::SITEMAP_QUERY_VAR, 0) === 1;
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $is_direct_request = $request_uri !== '' && preg_match('#/gallery-sitemap\.xml/?$#i', $request_uri) === 1;

        if (!$is_query_var && !$is_direct_request) {
            return;
        }

        $tree = BG_Folders::get_tree();
        if (empty($tree)) {
            status_header(404);
            exit;
        }

        $index = self::get_sitemap_index();
        if (empty($index)) {
            $index = self::rebuild_full_sitemap_index();
        }

        $excluded_names = array('All Files', 'Uncategorized');
        $urls = array();

        foreach ($index as $page_id => $root_ids) {
            $page_url = get_permalink((int)$page_id);
            if (!$page_url) {
                continue;
            }

            $page_url = trailingslashit($page_url);
            foreach ($root_ids as $root_id) {
                $root_id = (int)$root_id;
                if ($root_id <= 0) {
                    continue;
                }

                $root_folder = BG_Folder_Helper::find_folder_by_id($tree, $root_id);
                if (!$root_folder) {
                    continue;
                }

                $root_name = $root_folder['text'] ?? $root_folder['title'] ?? '';
                $root_slug = BG_Folder_Helper::folder_name_to_slug($root_name);

                $page_urls = self::collect_folder_sitemap_urls(
                    array($root_folder),
                    $page_url,
                    $excluded_names,
                    '',
                    $root_slug
                );

                if (!empty($page_urls)) {
                    $urls = array_merge($urls, $page_urls);
                }
            }
        }

        if (!empty($urls)) {
            $urls = array_values(array_unique($urls));
        }

        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            echo '<url><loc>' . esc_url($url) . '</loc></url>';
        }

        echo '</urlset>';
        exit;
    }

    /**
     * Get cached sitemap index, keyed by page ID
     */
    public static function get_sitemap_index(): array
    {
        $index = get_option(self::OPTION_SITEMAP_INDEX, array());
        if (!is_array($index)) {
            return array();
        }

        return $index;
    }

    /**
     * Persist sitemap index for all pages
     */
    private static function set_sitemap_index(array $index): void
    {
        update_option(self::OPTION_SITEMAP_INDEX, $index, false);
        update_option(self::OPTION_SITEMAP_INDEX_UPDATED, current_time('timestamp'), false);
    }

    /**
     * Get last sitemap index update timestamp
     */
    public static function get_sitemap_index_updated(): int
    {
        return (int)get_option(self::OPTION_SITEMAP_INDEX_UPDATED, 0);
    }

    /**
     * Rebuild sitemap index by scanning published pages
     */
    private static function rebuild_full_sitemap_index(): array
    {
        $index = array();
        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ));

        foreach ($query->posts as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }

            $root_ids = self::extract_root_ids_from_content($post->post_content);
            if (!empty($root_ids)) {
                $index[(int)$post->ID] = $root_ids;
            }
        }

        wp_reset_postdata();
        self::set_sitemap_index($index);

        return $index;
    }

    /**
     * Update sitemap index entry for a single page
     */
    private static function update_sitemap_index_for_page(int $post_id, string $content): void
    {
        $index = self::get_sitemap_index();
        $root_ids = self::extract_root_ids_from_content($content);

        if (empty($root_ids)) {
            unset($index[$post_id]);
        } else {
            $index[$post_id] = $root_ids;
        }

        self::set_sitemap_index($index);
    }

    /**
     * Remove a page from the sitemap index
     */
    private static function remove_page_from_sitemap_index(int $post_id): void
    {
        $index = self::get_sitemap_index();
        if (isset($index[$post_id])) {
            unset($index[$post_id]);
            self::set_sitemap_index($index);
        }
    }

    /**
     * Handle manual sitemap index rebuild
     */
    public static function handle_rebuild_sitemap_index(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('bg_rebuild_sitemap_index');

        self::rebuild_full_sitemap_index();

        $redirect_url = add_query_arg(
            array(
                'page' => 'bildegallery-settings',
                'bg_rebuild' => '1',
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Extract root folder IDs from page content
     */
    private static function extract_root_ids_from_content(string $content): array
    {
        if ($content === '') {
            return array();
        }

        $tags = array(
            'bilde_gallery' => 'folder_id',
            'bilde_folders' => 'parent_id',
            'bilde_breadcrumbs' => 'root_id',
        );

        $pattern = get_shortcode_regex(array_keys($tags));
        $root_ids = array();

        // Scan both the raw content and a JSON-unescaped copy. Some page
        // builders (e.g. Divi 5) store module content as a JSON string
        // inside a block comment, so shortcode attribute quotes come
        // through as literal """ rather than a real quote character
        // and never match get_shortcode_regex() against the raw content.
        foreach (array($content, self::decode_json_escaped_content($content)) as $haystack) {
            if (!preg_match_all('/' . $pattern . '/s', $haystack, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $shortcode) {
                $tag = $shortcode[2] ?? '';
                if (!isset($tags[$tag])) {
                    continue;
                }

                $attr_name = $tags[$tag];
                $atts = shortcode_parse_atts($shortcode[3] ?? '');
                if (!is_array($atts) || !isset($atts[$attr_name])) {
                    continue;
                }

                $value = (int)$atts[$attr_name];
                if ($value > 0) {
                    $root_ids[] = $value;
                }
            }
        }

        if (empty($root_ids)) {
            return array();
        }

        $root_ids = array_values(array_unique($root_ids));
        sort($root_ids);

        return $root_ids;
    }

    /**
     * Decode common JSON string escapes (\uXXXX, \n, \/, \") so shortcodes
     * embedded as JSON string values inside block comments become plain
     * text the shortcode regex can match.
     */
    private static function decode_json_escaped_content(string $content): string
    {
        if (strpos($content, '\\u') === false
            && strpos($content, '\\n') === false
            && strpos($content, '\\/') === false
            && strpos($content, '\\"') === false
        ) {
            return $content;
        }

        $decoded = preg_replace_callback('/\\\\u[0-9a-fA-F]{4}/', static function (array $m): string {
            $char = json_decode('"' . $m[0] . '"');
            return is_string($char) ? $char : $m[0];
        }, $content);

        if ($decoded === null) {
            $decoded = $content;
        }

        return str_replace(
            array('\\n', '\\r', '\\t', '\\/', '\\"'),
            array("\n", "\r", "\t", '/', '"'),
            $decoded
        );
    }

    /**
     * Collect sitemap URLs from a folder tree
     */
    private static function collect_folder_sitemap_urls(
        array $folders,
        string $base_url,
        array $excluded_names,
        string $prefix = '',
        string $root_slug = ''
    ): array
    {
        $urls = array();

        foreach ($folders as $folder) {
            $name = $folder['text'] ?? $folder['title'] ?? '';
            if ($name === '' || in_array($name, $excluded_names, true)) {
                continue;
            }

            $slug = BG_Folder_Helper::folder_name_to_slug($name);
            if ($slug === '') {
                continue;
            }

            $path = $prefix === '' ? $slug : $prefix . '/' . $slug;

            $is_root_segment = $prefix === '' && $root_slug !== '' && $slug === $root_slug;
            if (!$is_root_segment) {
                $urls[] = $base_url . trailingslashit($path);
            }

            if (!empty($folder['children']) && is_array($folder['children'])) {
                $next_prefix = $is_root_segment ? '' : $path;
                $child_urls = self::collect_folder_sitemap_urls(
                    $folder['children'],
                    $base_url,
                    $excluded_names,
                    $next_prefix,
                    $root_slug
                );
                if (!empty($child_urls)) {
                    $urls = array_merge($urls, $child_urls);
                }
            }
        }

        return $urls;
    }
}
