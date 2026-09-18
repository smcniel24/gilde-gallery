<?php
/**
 * Rewrite rules and query vars for friendly folder URLs.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Router
{
    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'register_rewrite_rules'));
        add_filter('query_vars', array(__CLASS__, 'register_query_vars'));
        add_filter('request', array(__CLASS__, 'resolve_folder_path'));

        add_action('update_option_' . BG_Settings::OPTION_BASE_PATH, array(__CLASS__, 'flush_rewrite_rules_on_save'));
        register_activation_hook(BILDE_PLUGIN_FILE, array(__CLASS__, 'flush_rewrite_rules_on_save'));
        register_deactivation_hook(BILDE_PLUGIN_FILE, array(__CLASS__, 'flush_rewrite_rules_on_save'));
    }

    /**
     * Flush rewrite rules when base path is updated, or on activation/deactivation
     */
    public static function flush_rewrite_rules_on_save(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Register rewrite rules for folder URLs - uses configured base path
     */
    public static function register_rewrite_rules(): void
    {
        $base_path = get_option(BG_Settings::OPTION_BASE_PATH, 'gallery');

        // Sitemap endpoint
        add_rewrite_rule(
            '^gallery-sitemap\.xml/?$',
            'index.php?' . BG_Sitemap::SITEMAP_QUERY_VAR . '=1',
            'top'
        );

        // Everything after the base path - whether it's a real (possibly
        // nested) WordPress page, a folder path directly on the base
        // page, or a real page with a folder path browsed beneath it - is
        // captured here as one raw remainder. Figuring out which segments are
        // a real page vs. a folder path requires looking pages up in the
        // database, so that split happens in resolve_folder_path() below
        // rather than via enumerated per-depth regexes.
        //
        // Must run as 'top' priority so it is tried before WordPress's own
        // generic page-matching catch-all rule (which would otherwise 404 on
        // a "/base-path/some-slug/" URL that isn't a real nested page, before
        // this rule ever gets a chance to run).
        add_rewrite_rule(
            $base_path . '/(.+?)/?$',
            'index.php?pagename=' . $base_path . '/$matches[1]&bg_folder_raw_path=$matches[1]',
            'top'
        );
    }

    /**
     * Split a "/base-path/a/b/c/" request into a real page path and a
     * trailing folder path, at whatever depth each actually starts.
     *
     * The rewrite rule above optimistically targets the full remainder as a
     * nested pagename so a genuine (possibly deeply nested) page still
     * resolves normally. Here we walk from the longest possible page path
     * down to the base page itself, using the first prefix that matches a
     * real page; whatever segments are left over become the folder path.
     */
    public static function resolve_folder_path(array $query_vars): array
    {
        if (empty($query_vars['bg_folder_raw_path'])) {
            return $query_vars;
        }

        $base_path = get_option(BG_Settings::OPTION_BASE_PATH, 'gallery');
        $raw_path = trim((string)$query_vars['bg_folder_raw_path'], '/');
        $segments = $raw_path === '' ? array() : explode('/', $raw_path);

        unset($query_vars['bg_folder_raw_path']);

        for ($i = count($segments); $i >= 0; $i--) {
            $page_segments = array_slice($segments, 0, $i);
            $folder_segments = array_slice($segments, $i);

            $pagename = $i > 0
                ? untrailingslashit(trailingslashit($base_path) . implode('/', $page_segments))
                : $base_path;

            if ($i === 0 || get_page_by_path($pagename)) {
                $query_vars['pagename'] = $pagename;

                if (!empty($folder_segments)) {
                    $query_vars['bg_folder_path'] = implode('/', $folder_segments);
                }

                return $query_vars;
            }
        }

        return $query_vars;
    }

    /**
     * Register custom query vars
     */
    public static function register_query_vars(array $vars): array
    {
        $vars[] = 'bg_folder_path';
        $vars[] = 'bg_folder_raw_path';
        $vars[] = BG_Sitemap::SITEMAP_QUERY_VAR;
        return $vars;
    }
}
