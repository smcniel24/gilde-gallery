<?php
/**
 * Shared folder-tree utilities used by the router, sitemap, and shortcodes.
 * Operates on the generic [id, text, title, parent, children] array shape
 * BG_Folders::get_tree() returns, independent of the taxonomy storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Folder_Helper
{
    /**
     * Convert folder name to URL-friendly slug
     */
    public static function folder_name_to_slug(string $name): string
    {
        // Folder names may contain HTML entities (e.g. "&amp;" for a
        // literal "&"); decode first so stripped entities don't leave
        // stray words like "amp" behind in the slug.
        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Recursively find a folder by ID
     */
    public static function find_folder_by_id(array $folders, int $target_id): ?array
    {
        foreach ($folders as $folder) {
            $fid = isset($folder['id']) ? (int)$folder['id'] : 0;
            if ($fid === $target_id) {
                return $folder;
            }

            if (!empty($folder['children']) && is_array($folder['children'])) {
                $found = self::find_folder_by_id($folder['children'], $target_id);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find folder by slug path
     */
    public static function find_folder_by_slug_path(array $folders, string $slug_path, int $parent_id = 0): ?array
    {
        $path_parts = explode('/', trim($slug_path, '/'));
        $current_slug = array_shift($path_parts);

        foreach ($folders as $folder) {
            $folder_parent = isset($folder['parent']) ? (int)$folder['parent'] : 0;

            if ($folder_parent === $parent_id) {
                $folder_name = $folder['text'] ?? $folder['title'] ?? '';
                $folder_slug = self::folder_name_to_slug($folder_name);

                if ($folder_slug === $current_slug) {
                    if (empty($path_parts)) {
                        return $folder;
                    }

                    if (!empty($folder['children'])) {
                        $remaining_path = implode('/', $path_parts);
                        return self::find_folder_by_slug_path(
                            $folder['children'],
                            $remaining_path,
                            (int)$folder['id']
                        );
                    }
                }
            }

            if (!empty($folder['children'])) {
                $found = self::find_folder_by_slug_path($folder['children'], $slug_path, $parent_id);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Build slug path for a folder (from root to current)
     */
    public static function build_folder_slug_path(array $tree, int $folder_id, int $root_id): string
    {
        $path = array();
        $current_id = $folder_id;

        while ($current_id > 0 && $current_id !== $root_id) {
            $folder = self::find_folder_by_id($tree, $current_id);
            if (!$folder) {
                break;
            }

            $folder_name = $folder['text'] ?? $folder['title'] ?? '';
            $slug = self::folder_name_to_slug($folder_name);
            array_unshift($path, $slug);

            $parent = isset($folder['parent']) ? (int)$folder['parent'] : 0;
            if ($parent === 0) {
                break;
            }

            $current_id = $parent;
        }

        return implode('/', $path);
    }

    /**
     * Get current folder ID from URL (friendly slug path, legacy query
     * string, or fallback to root)
     */
    public static function get_current_folder_id(int $root_folder_id): int
    {
        // The virtual "All Files" (-1) / "Uncategorized" (0) pseudo-folders
        // have no nested structure to browse into.
        if ($root_folder_id <= 0) {
            return $root_folder_id;
        }

        $tree = BG_Folders::get_tree();
        if (empty($tree)) {
            return $root_folder_id;
        }

        $slug_path = get_query_var('bg_folder_path', '');

        if ($slug_path !== '') {
            $folder = self::find_folder_by_slug_path($tree, $slug_path, $root_folder_id);
            if ($folder && isset($folder['id'])) {
                return (int)$folder['id'];
            }
            return $root_folder_id;
        }

        if (isset($_GET['bg_folder']) && (int)$_GET['bg_folder'] > 0) {
            return (int)$_GET['bg_folder'];
        }

        return $root_folder_id;
    }
}
