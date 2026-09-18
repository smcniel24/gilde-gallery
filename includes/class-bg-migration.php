<?php
/**
 * One-time importer that recreates a FileBird folder tree (and attachment
 * assignments) inside our own bilde_folder taxonomy. Read-only against
 * FileBird's own tables (wp_fbv / wp_fbv_attachment_folder) - never writes
 * to them, so this is safe to run, re-run, or ignore without risking
 * FileBird's own data or requiring FileBird to be deactivated first.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Migration
{
    const OPTION_ID_MAP = 'bilde_migration_id_map';
    const OPTION_COMPLETED_AT = 'bilde_migration_completed';

    public static function init(): void
    {
        add_action('admin_post_bg_run_migration', array(__CLASS__, 'handle_run_migration'));
    }

    /**
     * Is FileBird's schema present to migrate from?
     */
    public static function filebird_tables_exist(): bool
    {
        global $wpdb;

        $fbv_table = $wpdb->prefix . 'fbv';
        return $wpdb->get_var("SHOW TABLES LIKE '{$fbv_table}'") === $fbv_table;
    }

    /**
     * Read-only dry-run report: what would be imported, and any data
     * shapes (multi-folder attachments, multiple folder owners) that the
     * simple "one folder per attachment" model can't fully represent.
     */
    public static function build_report(): array
    {
        global $wpdb;

        if (!self::filebird_tables_exist()) {
            return array('error' => 'FileBird tables were not found on this site.');
        }

        $fbv_table = $wpdb->prefix . 'fbv';
        $join_table = $wpdb->prefix . 'fbv_attachment_folder';

        $folders = $wpdb->get_results("SELECT id, name, parent, created_by FROM {$fbv_table} WHERE type = 0");
        $folder_count = is_array($folders) ? count($folders) : 0;

        $membership_rows = $wpdb->get_results("SELECT folder_id, attachment_id FROM {$join_table}");
        $membership_count = is_array($membership_rows) ? count($membership_rows) : 0;

        // Detect attachments linked to more than one folder - our
        // "one folder per attachment" model keeps only the last one seen.
        $seen = array();
        $multi_folder_attachments = array();
        foreach ((array)$membership_rows as $row) {
            $aid = (int)$row->attachment_id;
            $fid = (int)$row->folder_id;
            if (isset($seen[$aid]) && $seen[$aid] !== $fid) {
                $multi_folder_attachments[$aid] = true;
            }
            $seen[$aid] = $fid;
        }

        $owners = array();
        foreach ((array)$folders as $folder) {
            $owners[(int)$folder->created_by] = true;
        }

        return array(
            'folder_count' => $folder_count,
            'membership_count' => $membership_count,
            'multi_folder_attachment_count' => count($multi_folder_attachments),
            'distinct_owner_count' => count($owners),
            'already_migrated' => (int)get_option(self::OPTION_COMPLETED_AT, 0) > 0,
        );
    }

    /**
     * Run the import: FileBird folders become bilde_folder terms
     * (parent/child structure preserved), and attachment memberships are
     * copied via wp_set_object_terms(). Safe to re-run - existing
     * same-name/same-parent terms are reused rather than duplicated.
     */
    public static function run_import(): array
    {
        global $wpdb;

        if (!self::filebird_tables_exist()) {
            return array('error' => 'FileBird tables were not found on this site.');
        }

        $fbv_table = $wpdb->prefix . 'fbv';
        $join_table = $wpdb->prefix . 'fbv_attachment_folder';

        $folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv_table} WHERE type = 0 ORDER BY parent, id");
        if (empty($folders)) {
            return array('imported_folders' => 0, 'imported_assignments' => 0, 'id_map' => array());
        }

        // Import folders parent-before-child. FileBird IDs don't guarantee
        // a parent's row precedes its children's in the result set, so
        // repeatedly sweep the remaining rows until every one either
        // resolves (its parent is already mapped, or it's a root folder)
        // or we give up after a bounded number of passes (guards against
        // a corrupt/cyclic parent chain in the source data).
        $id_map = array(); // FileBird folder id => our term id
        $remaining = $folders;
        $passes = 0;

        while (!empty($remaining) && $passes < 20) {
            $next_remaining = array();

            foreach ($remaining as $folder) {
                $fb_id = (int)$folder->id;
                $fb_parent = (int)$folder->parent;

                if ($fb_parent > 0 && !isset($id_map[$fb_parent])) {
                    $next_remaining[] = $folder;
                    continue;
                }

                $our_parent = $fb_parent > 0 ? $id_map[$fb_parent] : 0;
                $name = html_entity_decode($folder->name, ENT_QUOTES, 'UTF-8');

                $existing = get_term_by('name', $name, BG_Folders::TAXONOMY);
                if ($existing && (int)$existing->parent === (int)$our_parent) {
                    $id_map[$fb_id] = (int)$existing->term_id;
                    continue;
                }

                $result = BG_Folders::create_folder($name, $our_parent);
                if (is_wp_error($result)) {
                    continue;
                }

                $id_map[$fb_id] = $result;
            }

            $remaining = $next_remaining;
            $passes++;
        }

        // Import attachment memberships. Uses append=true (rather than
        // BG_Folders::set_attachment_folder's replace behavior) so an
        // attachment FileBird had linked to multiple folders keeps all of
        // them here - the report above flags when that happened.
        $rows = $wpdb->get_results("SELECT folder_id, attachment_id FROM {$join_table}");
        $imported_assignments = 0;

        foreach ((array)$rows as $row) {
            $fb_folder_id = (int)$row->folder_id;
            if (!isset($id_map[$fb_folder_id])) {
                continue;
            }

            $attachment_id = (int)$row->attachment_id;
            $result = wp_set_object_terms($attachment_id, array($id_map[$fb_folder_id]), BG_Folders::TAXONOMY, true);
            if (!is_wp_error($result)) {
                $imported_assignments++;
            }
        }

        // wp_set_object_terms() with $append = true doesn't always trigger
        // WordPress's automatic term count recalculation for every
        // relationship it inserts, so the "Count" column in the Folders
        // admin list can stay at 0 even though the relationships are
        // correctly saved. Force a recount for every imported folder so
        // the displayed counts are accurate immediately.
        if (!empty($id_map)) {
            $tt_ids = array();
            foreach (array_values($id_map) as $term_id) {
                $term = get_term((int)$term_id, BG_Folders::TAXONOMY);
                if ($term && !is_wp_error($term)) {
                    $tt_ids[] = (int)$term->term_taxonomy_id;
                }
            }

            if (!empty($tt_ids)) {
                wp_update_term_count_now($tt_ids, BG_Folders::TAXONOMY);
            }
        }

        update_option(self::OPTION_ID_MAP, $id_map, false);
        update_option(self::OPTION_COMPLETED_AT, current_time('timestamp'), false);

        return array(
            'imported_folders' => count($id_map),
            'imported_assignments' => $imported_assignments,
            'id_map' => $id_map,
        );
    }

    /**
     * Compare FileBird's own per-folder counts against ours, post-import.
     * Requires FileBird still active (reads its live count logic).
     */
    public static function verify(array $id_map): array
    {
        if (!class_exists('\FileBird\Classes\Tree')) {
            return array();
        }

        $fb_counts = \FileBird\Classes\Tree::getAllFoldersAndCount();
        $mismatches = array();

        foreach ($id_map as $fb_id => $our_id) {
            $fb_count = isset($fb_counts[$fb_id]) ? (int)$fb_counts[$fb_id] : 0;
            $our_count = count(BG_Folders::get_attachment_ids((int)$our_id));

            if ($fb_count !== $our_count) {
                $mismatches[] = array(
                    'filebird_folder_id' => (int)$fb_id,
                    'bilde_folder_id' => (int)$our_id,
                    'filebird_count' => $fb_count,
                    'bilde_count' => $our_count,
                );
            }
        }

        return $mismatches;
    }

    /**
     * Get the FileBird ID -> bilde_folder term ID map from the last run
     */
    public static function get_id_map(): array
    {
        $map = get_option(self::OPTION_ID_MAP, array());
        return is_array($map) ? $map : array();
    }

    /**
     * Handle the "Run Migration" admin-post action
     */
    public static function handle_run_migration(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }

        check_admin_referer('bg_run_migration');

        self::run_import();

        $redirect_url = add_query_arg(
            array(
                'page' => 'bildegallery-settings',
                'bg_migrated' => '1',
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }
}
