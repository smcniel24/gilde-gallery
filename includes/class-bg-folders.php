<?php
/**
 * Native folder system: registers the bilde_folder taxonomy on attachments
 * and provides tree/membership queries in the same array shape the rest
 * of the plugin (router, sitemap, shortcodes) expects.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Folders
{
    const TAXONOMY = 'bilde_folder';

    // Virtual, non-taxonomy pseudo-folders, matching FileBird's own
    // conventions so migrated content and mental models carry over:
    // -1 shows every attachment regardless of folder, 0 shows only
    // attachments with no folder assigned.
    const ALL_FILES_ID = -1;
    const UNCATEGORIZED_ID = 0;

    // Folder tree and attachment-ID lookups are cached in transients keyed
    // by this version number. Any change that could affect their results
    // (a folder is created/renamed/deleted, an attachment's folder changes,
    // or an attachment is added/removed) bumps the version instead of
    // hunting down which specific cache entries to clear, so stale reads
    // are never possible - only orphaned transients, which just expire.
    const CACHE_VERSION_OPTION = 'bilde_folder_cache_version';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'register_taxonomy'));
        add_filter('update_post_term_count_statuses', array(__CLASS__, 'include_inherit_status_in_counts'), 10, 2);

        add_action('saved_term', array(__CLASS__, 'bump_cache_version_for_taxonomy'), 10, 3);
        add_action('delete_term', array(__CLASS__, 'bump_cache_version_for_taxonomy'), 10, 3);
        add_action('set_object_terms', array(__CLASS__, 'bump_cache_version_for_object_terms'), 10, 4);
        add_action('add_attachment', array(__CLASS__, 'bump_cache_version'));
        add_action('delete_attachment', array(__CLASS__, 'bump_cache_version'));
    }

    /**
     * Invalidate cached folder data when a term in our taxonomy changes
     */
    public static function bump_cache_version_for_taxonomy($term_id, $tt_id, $taxonomy): void
    {
        if ($taxonomy === self::TAXONOMY) {
            self::bump_cache_version();
        }
    }

    /**
     * Invalidate cached folder data when an attachment's folder assignment changes
     */
    public static function bump_cache_version_for_object_terms($object_id, $terms, $tt_ids, $taxonomy): void
    {
        if ($taxonomy === self::TAXONOMY) {
            self::bump_cache_version();
        }
    }

    /**
     * Bump the cache version, invalidating every cached tree/attachment lookup
     */
    public static function bump_cache_version(): void
    {
        update_option(self::CACHE_VERSION_OPTION, self::get_cache_version() + 1, false);
    }

    /**
     * Current cache version, used as a transient key suffix
     */
    private static function get_cache_version(): int
    {
        return (int)get_option(self::CACHE_VERSION_OPTION, 1);
    }

    /**
     * WordPress's default term-count query only counts an attachment if its
     * own post_status is 'publish', or it's 'inherit' with a post_parent
     * that is itself published. Standalone Media Library uploads (the
     * normal case here) have post_parent = 0, so they never satisfy either
     * condition and the "Count" column always reads 0 for this taxonomy
     * unless 'inherit' is explicitly added to the countable statuses.
     */
    public static function include_inherit_status_in_counts(array $statuses, $taxonomy): array
    {
        if ($taxonomy->name === self::TAXONOMY && !in_array('inherit', $statuses, true)) {
            $statuses[] = 'inherit';
        }

        return $statuses;
    }

    /**
     * Register the folder taxonomy on attachments
     */
    public static function register_taxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, 'attachment', array(
            'labels' => array(
                'name' => 'Folders',
                'singular_name' => 'Folder',
            ),
            'hierarchical' => true,
            'public' => false,
            'show_ui' => true,
            // Organize Folders (BG_Organize) is now the real management UI -
            // hide the native flat taxonomy screen from the Media submenu so
            // there's only one obvious place to manage folders. show_ui stays
            // true so the screen (and any core functionality relying on it)
            // still exists, just not linked in the menu.
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => false,
        ));
    }

    /**
     * Is the given ID one of the virtual pseudo-folders?
     */
    public static function is_virtual_folder(int $folder_id): bool
    {
        return $folder_id === self::ALL_FILES_ID || $folder_id === self::UNCATEGORIZED_ID;
    }

    /**
     * Human label for a folder ID, including the virtual pseudo-folders
     */
    public static function get_folder_label(int $folder_id): string
    {
        if ($folder_id === self::ALL_FILES_ID) {
            return 'All Files';
        }

        if ($folder_id === self::UNCATEGORIZED_ID) {
            return 'Uncategorized';
        }

        $term = get_term($folder_id, self::TAXONOMY);
        if ($term && !is_wp_error($term)) {
            return $term->name;
        }

        return 'Folder ' . $folder_id;
    }

    /**
     * Build the full folder tree as an array of
     * [id, text, title, parent, children] nodes. Each level sorts by the
     * manual "bg_folder_order" term meta (set only when a folder is
     * actually dragged to reorder in the Organize Folders page) first,
     * falling back to alphabetical for anything never manually ordered -
     * so untouched folders keep behaving exactly as before. Same shape
     * FileBird's Tree::getFolders() returned, so router/sitemap/shortcodes
     * work against either unchanged.
     */
    public static function get_tree(): array
    {
        $cache_key = 'bilde_tree_' . self::get_cache_version();
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $terms = get_terms(array(
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
        ));

        if (is_wp_error($terms) || empty($terms)) {
            $tree = array();
            set_transient($cache_key, $tree, self::CACHE_TTL);
            return $tree;
        }

        $order_meta = array();
        foreach ($terms as $term) {
            $meta = get_term_meta($term->term_id, 'bg_folder_order', true);
            $order_meta[$term->term_id] = ($meta === '' || $meta === false) ? null : (int)$meta;
        }

        $by_parent = array();
        foreach ($terms as $term) {
            $parent = (int)$term->parent;
            if (!isset($by_parent[$parent])) {
                $by_parent[$parent] = array();
            }

            $by_parent[$parent][] = array(
                'id' => (int)$term->term_id,
                'text' => $term->name,
                'title' => $term->name,
                'parent' => $parent,
            );
        }

        foreach ($by_parent as &$siblings) {
            usort($siblings, function ($a, $b) use ($order_meta) {
                $order_a = $order_meta[$a['id']];
                $order_b = $order_meta[$b['id']];

                if ($order_a !== null && $order_b !== null) {
                    return $order_a !== $order_b
                        ? ($order_a <=> $order_b)
                        : strcasecmp($a['text'], $b['text']);
                }

                if ($order_a !== null) {
                    return -1;
                }

                if ($order_b !== null) {
                    return 1;
                }

                return strcasecmp($a['text'], $b['text']);
            });
        }
        unset($siblings);

        $tree = self::build_branch($by_parent, 0);
        set_transient($cache_key, $tree, self::CACHE_TTL);
        return $tree;
    }

    /**
     * Recursively assemble a branch of the tree from parent-grouped nodes
     */
    private static function build_branch(array $by_parent, int $parent_id): array
    {
        if (empty($by_parent[$parent_id])) {
            return array();
        }

        $branch = array();
        foreach ($by_parent[$parent_id] as $node) {
            $node['children'] = self::build_branch($by_parent, $node['id']);
            $branch[] = $node;
        }

        return $branch;
    }

    /**
     * Get attachment IDs belonging to a folder, including the virtual
     * "All Files" (-1) and "Uncategorized" (0) pseudo-folders.
     */
    public static function get_attachment_ids(int $folder_id): array
    {
        $cache_key = 'bilde_attach_' . self::get_cache_version() . '_' . $folder_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $ids = self::query_attachment_ids($folder_id);
        set_transient($cache_key, $ids, self::CACHE_TTL);
        return $ids;
    }

    /**
     * Run the actual attachment lookup for a folder, uncached
     */
    private static function query_attachment_ids(int $folder_id): array
    {
        if ($folder_id <= 0 && $folder_id !== self::ALL_FILES_ID && $folder_id !== self::UNCATEGORIZED_ID) {
            return array();
        }

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $tax_query = self::get_tax_query_for_folder($folder_id);
        if ($tax_query !== null) {
            $args['tax_query'] = $tax_query;
        }

        $ids = get_posts($args);

        return array_map('intval', $ids);
    }

    /**
     * Build the tax_query clause for a folder, including the virtual
     * "All Files" (-1, no filter) and "Uncategorized" (0, unassigned)
     * pseudo-folders. Shared by query_attachment_ids() and the
     * ajax_query_attachments_args filter (BG_Media_Grid) so the native
     * Media Library grid/modal and the frontend shortcodes apply the exact
     * same folder-membership rules.
     */
    public static function get_tax_query_for_folder(int $folder_id): ?array
    {
        if ($folder_id === self::ALL_FILES_ID) {
            return null;
        }

        if ($folder_id === self::UNCATEGORIZED_ID) {
            return array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'operator' => 'NOT EXISTS',
                ),
            );
        }

        if ($folder_id <= 0) {
            return null;
        }

        return array(
            array(
                'taxonomy' => self::TAXONOMY,
                'field' => 'term_id',
                'terms' => $folder_id,
                // WP_Query's tax_query defaults to including every
                // descendant term for a hierarchical taxonomy. A folder
                // should only ever show its own directly assigned images -
                // subfolder browsing is a separate, explicit action - so
                // without this, a parent folder silently included every
                // image from every folder beneath it too.
                'include_children' => false,
            ),
        );
    }

    /**
     * Assign an attachment to a single folder (removes any prior folder).
     * Pass 0 or -1 to clear the assignment (Uncategorized).
     */
    public static function set_attachment_folder(int $attachment_id, int $folder_id): bool
    {
        if ($folder_id <= 0) {
            $result = wp_set_object_terms($attachment_id, array(), self::TAXONOMY);
        } else {
            $result = wp_set_object_terms($attachment_id, array($folder_id), self::TAXONOMY, false);
        }

        return !is_wp_error($result);
    }

    /**
     * Create a folder. Returns the new term ID, or a WP_Error on failure.
     */
    public static function create_folder(string $name, int $parent_id = 0)
    {
        $args = array();
        if ($parent_id > 0) {
            $args['parent'] = $parent_id;
        }

        $result = wp_insert_term($name, self::TAXONOMY, $args);
        if (is_wp_error($result)) {
            return $result;
        }

        return (int)$result['term_id'];
    }

    /**
     * Rename a folder
     */
    public static function rename_folder(int $folder_id, string $name): bool
    {
        $result = wp_update_term($folder_id, self::TAXONOMY, array('name' => $name));
        return !is_wp_error($result);
    }

    /**
     * Delete a folder. Attachments inside become Uncategorized automatically
     * (WordPress removes the term relationship on term delete).
     */
    public static function delete_folder(int $folder_id): bool
    {
        $result = wp_delete_term($folder_id, self::TAXONOMY);
        return $result === true;
    }

    /**
     * Move a folder under a new parent (0 = top level). Rejects moving a
     * folder into itself or into one of its own descendants - allowing
     * that would create a circular parent chain, sending get_tree()'s
     * recursive build_branch() into infinite recursion the next time
     * anything requests the tree.
     */
    public static function move_folder(int $folder_id, int $new_parent_id): bool
    {
        if ($new_parent_id === $folder_id) {
            return false;
        }

        if ($new_parent_id > 0 && self::is_within_subtree($folder_id, $new_parent_id)) {
            return false;
        }

        $result = wp_update_term($folder_id, self::TAXONOMY, array('parent' => $new_parent_id));
        return !is_wp_error($result);
    }

    /**
     * Is $candidate_id equal to, or a descendant of, $ancestor_id?
     */
    private static function is_within_subtree(int $ancestor_id, int $candidate_id): bool
    {
        $current_id = $candidate_id;

        while ($current_id > 0) {
            $term = get_term($current_id, self::TAXONOMY);
            if (!$term || is_wp_error($term)) {
                return false;
            }

            if ((int)$term->parent === $ancestor_id) {
                return true;
            }

            $current_id = (int)$term->parent;
        }

        return false;
    }

    /**
     * Persist a manual display order for one parent's full set of
     * children. Called with the complete new sibling order every time a
     * folder is dragged to reorder - recomputing the whole group (rather
     * than fractional "insert between" math) keeps the values simple and
     * avoids float-precision creep over repeated drags.
     */
    public static function reorder_siblings(int $parent_id, array $ordered_folder_ids): bool
    {
        foreach ($ordered_folder_ids as $index => $folder_id) {
            update_term_meta((int)$folder_id, 'bg_folder_order', $index);
        }

        // update_term_meta() doesn't fire 'saved_term', so it wouldn't
        // otherwise trigger the cache-version bump that keeps get_tree()
        // from serving a stale (unsorted) result.
        self::bump_cache_version();

        return true;
    }
}
