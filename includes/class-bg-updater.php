<?php
/**
 * GitHub-based update checking via the vendored Plugin Update Checker
 * library. Checks tagged releases on a private GitHub repo, authenticating
 * with a personal access token stored in settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once BILDE_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class BG_Updater
{
    const OPTION_GITHUB_TOKEN = 'bilde_github_token';
    const GITHUB_REPO_URL = 'https://github.com/smcniel24/gilde-gallery';

    /** @var object|null */
    private static $update_checker = null;

    public static function init(): void
    {
        add_action('init', array(__CLASS__, 'setup_update_checker'), 20);
        add_action('admin_post_bg_check_for_updates', array(__CLASS__, 'handle_check_for_updates'));
    }

    /**
     * Register the GitHub update checker. Runs on 'init' so BG_Settings'
     * options are available and this only happens once per request.
     */
    public static function setup_update_checker(): void
    {
        if (!is_admin() && !wp_doing_cron()) {
            return;
        }

        self::$update_checker = PucFactory::buildUpdateChecker(
            self::GITHUB_REPO_URL,
            BILDE_PLUGIN_FILE,
            'bildegallery'
        );

        $token = self::get_github_token();
        if ($token !== '') {
            self::$update_checker->setAuthentication($token);
        }

        // Default behavior tracks the latest tagged release (e.g. v0.2.0),
        // not raw branch commits, using GitHub's auto-generated source zip.
    }

    /**
     * Get the stored GitHub PAT
     */
    public static function get_github_token(): string
    {
        $token = get_option(self::OPTION_GITHUB_TOKEN, '');
        return is_string($token) ? trim($token) : '';
    }

    /**
     * Sanitize the GitHub token field
     */
    public static function sanitize_github_token(string $token): string
    {
        return trim($token);
    }

    /**
     * Handle the "Check for Updates" button: forces a fresh check against
     * GitHub, bypassing the update checker's own cache, then redirects
     * back to the settings page with a result notice.
     */
    public static function handle_check_for_updates(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        check_admin_referer('bg_check_for_updates');

        if (self::$update_checker === null) {
            self::setup_update_checker();
        }

        $update = self::$update_checker->checkForUpdates();

        $redirect_args = array('page' => 'bildegallery-settings');

        if ($update !== null && version_compare($update->version, BILDE_VERSION, '>')) {
            $redirect_args['bg_update_check'] = 'available';
            $redirect_args['bg_update_version'] = $update->version;
        } else {
            $redirect_args['bg_update_check'] = 'current';
        }

        wp_safe_redirect(add_query_arg($redirect_args, admin_url('options-general.php')));
        exit;
    }
}
