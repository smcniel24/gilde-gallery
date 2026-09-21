<?php
/**
 * Plugin Name: BildeGallery
 * Description: Native WordPress Media Library folders with friendly URL navigation for folders and images. No third-party folder plugin required.
 * Version: 1.1.0
 * Author: GoDev Technologies
 * Author URI: https://godev.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: bildegallery
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BILDE_VERSION', '1.1.0');
define('BILDE_PLUGIN_FILE', __FILE__);
define('BILDE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BILDE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once BILDE_PLUGIN_DIR . 'includes/class-bg-folders.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-folder-helper.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-settings.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-router.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-sitemap.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-assets.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-shortcodes.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-blocks.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-media-ui.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-media-grid.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-organize.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-migration.php';
require_once BILDE_PLUGIN_DIR . 'includes/class-bg-updater.php';

final class Bilde_Gallery_Plugin
{
    public static function init(): void
    {
        BG_Folders::init();
        BG_Settings::init();
        BG_Router::init();
        BG_Sitemap::init();
        BG_Assets::init();
        BG_Shortcodes::init();
        BG_Blocks::init();
        BG_Media_UI::init();
        BG_Media_Grid::init();
        BG_Organize::init();
        BG_Migration::init();
        BG_Updater::init();
    }
}

Bilde_Gallery_Plugin::init();
