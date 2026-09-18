<?php
/**
 * Admin menu, settings page, and options handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Settings
{
    const OPTION_BASE_PATH = 'bilde_base_path';
    const OPTION_FOLDER_STYLE = 'bilde_folder_style';

    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(BILDE_PLUGIN_FILE), array(__CLASS__, 'add_settings_link'));

        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_styles'));
        add_action('admin_footer', array(__CLASS__, 'output_admin_scripts'));

        // Auto-populate page creation launched from the "Create Page Now" link
        add_action('admin_footer-post-new.php', array(__CLASS__, 'autofill_page_creation'));
    }

    /**
     * Add settings link on plugins page
     */
    public static function add_settings_link(array $links): array
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=bildegallery-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu(): void
    {
        add_options_page(
            'BildeGallery Settings',
            'BildeGallery',
            'manage_options',
            'bildegallery-settings',
            array(__CLASS__, 'settings_page')
        );
    }

    /**
     * Register settings
     */
    public static function register_settings(): void
    {
        register_setting('bildegallery_settings', self::OPTION_BASE_PATH, array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_base_path'),
            'default' => 'gallery'
        ));

        register_setting('bildegallery_settings', self::OPTION_FOLDER_STYLE, array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_folder_style'),
            'default' => 'classic'
        ));

        // One-time cleanup: the GitHub token field was removed now that the
        // update repo is public, so drop any token that was saved earlier.
        delete_option('bilde_github_token');
    }

    /**
     * Sanitize base path
     */
    public static function sanitize_base_path(string $path): string
    {
        $path = trim($path, '/');
        $path = strtolower($path);
        $path = preg_replace('/[^a-z0-9\/-]/', '', $path);

        $page = get_page_by_path($path);

        if (!$page) {
            add_settings_error(
                self::OPTION_BASE_PATH,
                'page_not_found',
                sprintf(
                    'Warning: The page "%s" does not exist. <a href="%s">Create it now</a> or the plugin may not work correctly.',
                    '/' . $path . '/',
                    admin_url('post-new.php?post_type=page&bg_path=' . urlencode($path))
                ),
                'error'
            );
        }

        return $path;
    }

    /**
     * Get allowed folder style options
     */
    private static function get_folder_style_options(): array
    {
        return array('text-link', 'classic', 'gold-outline', 'outline', 'soft', 'solid');
    }

    /**
     * Sanitize folder style
     */
    public static function sanitize_folder_style(string $style): string
    {
        $style = strtolower(trim($style));
        $allowed = self::get_folder_style_options();
        if (!in_array($style, $allowed, true)) {
            return 'classic';
        }

        return $style;
    }

    /**
     * Get current folder style setting
     */
    public static function get_folder_style(): string
    {
        $style = get_option(self::OPTION_FOLDER_STYLE, 'classic');
        if (!is_string($style)) {
            return 'classic';
        }

        return self::sanitize_folder_style($style);
    }

    /**
     * Get URL to create a new page with pre-filled slug
     */
    private static function get_create_page_url(string $path): string
    {
        $segments = array_filter(explode('/', $path));
        $title = ucwords(str_replace('-', ' ', end($segments)));

        return admin_url('post-new.php?post_type=page') .
               '&bg_suggested_path=' . urlencode($path) .
               '&bg_suggested_title=' . urlencode($title);
    }

    /**
     * Enqueue admin styles for settings preview
     */
    public static function enqueue_admin_styles(string $hook): void
    {
        if ($hook !== 'settings_page_bildegallery-settings') {
            return;
        }

        BG_Assets::enqueue_bootstrap_icons();

        wp_enqueue_style(
            'bilde-admin',
            BILDE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BILDE_VERSION
        );
    }

    /**
     * Enqueue admin JS for the sitemap copy button
     */
    public static function output_admin_scripts(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_bildegallery-settings') {
            return;
        }

        wp_enqueue_script(
            'bilde-admin',
            BILDE_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            BILDE_VERSION,
            true
        );
    }

    /**
     * Auto-fill page slug when creating from settings page
     */
    public static function autofill_page_creation(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'page') {
            return;
        }

        $suggested_path = isset($_GET['bg_suggested_path']) ? sanitize_text_field($_GET['bg_suggested_path']) : '';
        $suggested_title = isset($_GET['bg_suggested_title']) ? sanitize_text_field($_GET['bg_suggested_title']) : '';

        if (!$suggested_path) {
            return;
        }

        wp_localize_script('jquery', 'bgAutofill', array(
            'title' => $suggested_title,
            'slug' => $suggested_path,
            'noticeHtml' => '<div class="notice notice-info" style="margin: 15px 0;"><p><strong>BildeGallery:</strong> Creating base gallery page. Suggested permalink: <code>' . esc_html(home_url('/' . $suggested_path . '/')) . '</code></p></div>',
        ));
        ?>
        <script>
        jQuery(document).ready(function($) {
            if (typeof bgAutofill === 'undefined') {
                return;
            }
            if (bgAutofill.title) {
                $('#title').val(bgAutofill.title);
            }
            $('#post_name').val(bgAutofill.slug);
            $('.edit-slug').trigger('click');
            $(bgAutofill.noticeHtml).insertAfter('.wrap h1');
        });
        </script>
        <?php
    }

    /**
     * Settings page
     */
    public static function settings_page(): void
    {
        // Build the list of notices to display. Only the "page not found"
        // warning (added from sanitize_base_path during the options.php
        // save) needs to survive a redirect via the settings-errors
        // transient - everything else here is read straight from the
        // current request's own query args, so it's added directly.
        $notices = array();

        if (isset($_GET['bg_created']) && $_GET['bg_created'] === '1') {
            $page_id = isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0;
            if ($page_id > 0) {
                $page_url = get_permalink($page_id);
                $notices[] = array(
                    'type' => 'success',
                    'message' => sprintf('Page created: <a href="%s" target="_blank">%s</a>', esc_url($page_url), esc_html($page_url)),
                );
            }
        }

        if (isset($_GET['bg_rebuild']) && $_GET['bg_rebuild'] === '1') {
            $notices[] = array('type' => 'success', 'message' => 'Sitemap index rebuilt successfully.');
        }

        if (isset($_GET['bg_migrated']) && $_GET['bg_migrated'] === '1') {
            $notices[] = array('type' => 'success', 'message' => 'Migration from FileBird complete. Review the results below.');
        }

        if (isset($_GET['bg_update_check'])) {
            if ($_GET['bg_update_check'] === 'available') {
                $new_version = isset($_GET['bg_update_version']) ? sanitize_text_field($_GET['bg_update_version']) : '';
                $notices[] = array(
                    'type' => 'success',
                    'message' => sprintf(
                        'A new version (%s) is available. <a href="%s">Go to Plugins to update</a>.',
                        esc_html($new_version),
                        esc_url(admin_url('plugins.php'))
                    ),
                );
            } else {
                $notices[] = array('type' => 'success', 'message' => 'You are running the latest version of BildeGallery.');
            }
        }

        foreach (get_settings_errors('bildegallery_messages') as $error) {
            $notices[] = array(
                'type' => $error['type'] === 'error' ? 'error' : 'success',
                'message' => $error['message'],
            );
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] && empty($notices)) {
            $notices[] = array('type' => 'success', 'message' => 'Settings saved.');
        }

        $base_path = get_option(self::OPTION_BASE_PATH, 'gallery');
        $folder_style = self::get_folder_style();
        $sitemap_url = BG_Sitemap::get_gallery_sitemap_url();
        $sitemap_index = BG_Sitemap::get_sitemap_index();
        $sitemap_index_updated = BG_Sitemap::get_sitemap_index_updated();
        $sitemap_stale_days = 7;
        $sitemap_is_stale = $sitemap_index_updated === 0 || (time() - $sitemap_index_updated) > ($sitemap_stale_days * DAY_IN_SECONDS);

        // Check if base page exists
        $base_page = get_page_by_path($base_path);
        $page_exists = !empty($base_page);

        $style_options = array(
            'text-link' => array(
                'label' => 'Text link',
                'description' => 'Simple text link'
            ),
            'classic' => array(
                'label' => 'Classic gold',
                'description' => 'Current design'
            ),
            'gold-outline' => array(
                'label' => 'Gold outline',
                'description' => 'Classic border, no fill'
            ),
            'outline' => array(
                'label' => 'Outline pill',
                'description' => 'Rounded outline style'
            ),
            'soft' => array(
                'label' => 'Soft card',
                'description' => 'Muted background'
            ),
            'solid' => array(
                'label' => 'Solid block',
                'description' => 'Bold dark button'
            )
        );

        $filebird_active = class_exists('\FileBird\Classes\Tree');
        $migration_report = BG_Migration::filebird_tables_exist() ? BG_Migration::build_report() : null;
        ?>
        <div class="wrap">
            <h1>BildeGallery Settings</h1>

            <?php if (!empty($notices)) : ?>
                <div class="bg-notices">
                    <?php foreach ($notices as $notice) : ?>
                        <div class="bg-notice bg-notice-<?php echo esc_attr($notice['type']); ?>">
                            <span class="bg-notice-icon" aria-hidden="true"></span>
                            <div class="bg-notice-message"><?php echo wp_kses_post($notice['message']); ?></div>
                            <button type="button" class="bg-notice-dismiss" aria-label="Dismiss">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('bildegallery_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(self::OPTION_BASE_PATH); ?>">Base Gallery Path</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="<?php echo esc_attr(self::OPTION_BASE_PATH); ?>"
                                   name="<?php echo esc_attr(self::OPTION_BASE_PATH); ?>"
                                   value="<?php echo esc_attr($base_path); ?>"
                                   class="regular-text"
                                   placeholder="gallery">

                            <?php if ($page_exists) : ?>
                                <p class="description" style="color: #46b450;">
                                    &#10003; Page exists: <a href="<?php echo esc_url(get_permalink($base_page)); ?>" target="_blank"><?php echo esc_html('/' . $base_path . '/'); ?></a>
                                    | <a href="<?php echo esc_url(get_edit_post_link($base_page->ID)); ?>">Edit Page</a>
                                </p>
                            <?php else : ?>
                                <p class="description" style="color: #dc3232;">
                                    &#9888; Page does not exist: <code>/<?php echo esc_html($base_path); ?>/</code>
                                    <br>
                                    <a href="<?php echo esc_url(self::get_create_page_url($base_path)); ?>" class="button button-secondary" style="margin-top: 8px;">
                                        Create Page Now
                                    </a>
                                </p>
                            <?php endif; ?>

                            <p class="description">
                                Enter the parent page slug where your gallery pages are located (without slashes).<br>
                                For example: <code>gallery</code> or <code>projects</code> or <code>portfolio/galleries</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Folder Design</label>
                        </th>
                        <td>
                            <fieldset class="bg-folder-style-options">
                                <?php foreach ($style_options as $style_key => $style_data) : ?>
                                    <div class="bg-folder-style-option">
                                        <label class="bg-folder-style-choice">
                                            <input type="radio"
                                                   name="<?php echo esc_attr(self::OPTION_FOLDER_STYLE); ?>"
                                                   value="<?php echo esc_attr($style_key); ?>"
                                                   <?php checked($folder_style, $style_key); ?>>
                                            <span class="bg-folder-style-label"><?php echo esc_html($style_data['label']); ?></span>
                                            <?php if (!empty($style_data['description'])) : ?>
                                                <span class="bg-folder-style-desc"><?php echo esc_html($style_data['description']); ?></span>
                                            <?php endif; ?>
                                        </label>
                                        <div class="bg-folder-style-preview">
                                            <div class="bg-folder-nav bg-folder-style-<?php echo esc_attr($style_key); ?>">
                                                <a class="bg-folder-link bg-folder-style-<?php echo esc_attr($style_key); ?>" href="#">
                                                    <i class="bi bi-folder-fill bg-folder-icon" aria-hidden="true"></i>
                                                    Sample Folder
                                                </a>
                                                <a class="bg-folder-link bg-folder-style-<?php echo esc_attr($style_key); ?>" href="#">
                                                    <i class="bi bi-folder-fill bg-folder-icon" aria-hidden="true"></i>
                                                    Another Folder
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description">
                                Select the folder look used by the <code>[bilde_folders]</code> shortcode.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Gallery Sitemap</label>
                        </th>
                        <td>
                            <p class="description">
                                This plugin generates gallery pages as virtual URLs, so SEO plugins may not detect them.
                                Use the sitemap below to submit those gallery URLs directly to Google Search Console.
                            </p>
                            <input type="text"
                                   class="regular-text"
                                   readonly
                                   value="<?php echo esc_attr($sitemap_url); ?>"
                                   onclick="this.select();"
                                   aria-label="Gallery sitemap URL">
                            <button type="button" class="button" data-bg-copy="gallery-sitemap">Copy URL</button>
                            <p class="description">
                                You can also open it here: <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($sitemap_url); ?></a>
                            </p>
                            <p class="description">
                                If the link shows a 404, visit Settings &rarr; Permalinks and click Save Changes once.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Manual Refresh</label>
                        </th>
                        <td>
                            <p class="description">
                                Rebuild the sitemap index by scanning pages for gallery shortcodes.
                            </p>
                            <p class="description">
                                Last index update: <?php echo $sitemap_index_updated > 0 ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $sitemap_index_updated)) : 'Never'; ?>
                            </p>
                            <?php if ($sitemap_is_stale) : ?>
                                <p class="description" style="color: #b45309;">
                                    The sitemap index may be stale. Click the rebuild button to refresh it.
                                </p>
                            <?php endif; ?>
                            <button type="submit" form="bg-rebuild-sitemap-form" class="button button-secondary">Rebuild Sitemap Index</button>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Indexed Pages</label>
                        </th>
                        <td>
                            <?php if (empty($sitemap_index)) : ?>
                                <p class="description">No pages found with gallery shortcodes.</p>
                            <?php else : ?>
                                <div class="bg-indexed-pages">
                                    <table class="widefat striped">
                                        <thead>
                                            <tr>
                                                <th>Page</th>
                                                <th>Root Folder IDs</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sitemap_index as $page_id => $root_ids) :
                                                $page_id = (int)$page_id;
                                                $page_title = get_the_title($page_id);
                                                $page_link = get_permalink($page_id);
                                                $root_ids = array_map('intval', (array)$root_ids);
                                                $root_ids_display = implode(', ', $root_ids);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($page_link) : ?>
                                                            <a href="<?php echo esc_url($page_link); ?>" target="_blank" rel="noopener">
                                                                <?php echo esc_html($page_title ?: ('Page #' . $page_id)); ?>
                                                            </a>
                                                        <?php else : ?>
                                                            <?php echo esc_html($page_title ?: ('Page #' . $page_id)); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo esc_html($root_ids_display); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="description">
                                    These pages are used to build gallery URLs in the sitemap.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <h2>Plugin Updates</h2>
            <p class="description">
                Current version: <strong><?php echo esc_html(BILDE_VERSION); ?></strong>.
                Updates are published as tagged releases on GitHub.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bg_check_for_updates'); ?>
                <input type="hidden" name="action" value="bg_check_for_updates">
                <button type="submit" class="button button-secondary">Check for Updates</button>
            </form>

            <form id="bg-rebuild-sitemap-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: none;">
                <?php wp_nonce_field('bg_rebuild_sitemap_index'); ?>
                <input type="hidden" name="action" value="bg_rebuild_sitemap_index">
            </form>

            <?php if ($migration_report !== null) : ?>
                <hr>
                <h2>Migrate from FileBird</h2>
                <div class="card bg-migration-report">
                    <?php if (isset($migration_report['error'])) : ?>
                        <p><?php echo esc_html($migration_report['error']); ?></p>
                    <?php else : ?>
                        <p>
                            FileBird data found on this site:
                            <strong><?php echo esc_html($migration_report['folder_count']); ?></strong> folders,
                            <strong><?php echo esc_html($migration_report['membership_count']); ?></strong> image-to-folder assignments.
                        </p>

                        <?php if ($migration_report['multi_folder_attachment_count'] > 0) : ?>
                            <p class="bg-migration-warning">
                                &#9888; <?php echo esc_html($migration_report['multi_folder_attachment_count']); ?> image(s) are linked to more than one FileBird folder.
                                BildeGallery's own folder assignments are one-per-image going forward, but the migration preserves every link it finds, so review these after importing.
                            </p>
                        <?php endif; ?>

                        <?php if ($migration_report['distinct_owner_count'] > 1) : ?>
                            <p class="bg-migration-warning">
                                &#9888; FileBird folders on this site belong to <?php echo esc_html($migration_report['distinct_owner_count']); ?> different owners (per-user folders). BildeGallery folders are not per-user, so all imported folders will be visible to every user with access to the Media Library.
                            </p>
                        <?php endif; ?>

                        <?php if ($migration_report['already_migrated']) : ?>
                            <p class="description">A migration has already been run on this site. Running it again is safe - existing same-name folders are reused rather than duplicated, and image assignments are re-applied.</p>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Import FileBird folders and image assignments into BildeGallery now?');">
                            <?php wp_nonce_field('bg_run_migration'); ?>
                            <input type="hidden" name="action" value="bg_run_migration">
                            <button type="submit" class="button button-primary">Run Migration</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <hr>

            <div style="max-width: 800px;">
                <h2>How to Use</h2>

                <div class="card">
                    <h3>&#128193; Quick Setup Guide</h3>
                    <ol>
                        <li><strong>Create Base Page:</strong> Make sure the page <code>/<?php echo esc_html($base_path); ?>/</code> exists (create it above if needed)</li>
                        <li><strong>Add Child Pages:</strong> Create child pages like <code>/<?php echo esc_html($base_path); ?>/residential/</code></li>
                        <li><strong>Add Shortcodes:</strong> Put gallery shortcodes on both the base page and child pages</li>
                        <li><strong>Flush Permalinks:</strong> Go to Settings &rarr; Permalinks &rarr; Save Changes</li>
                    </ol>
                </div>

                <div class="card">
                    <h3>&#128221; Available Shortcodes</h3>

                    <h4>Display Gallery Images</h4>
                    <pre>[bilde_gallery folder_id="1" columns="3" size="large" title="My Gallery"]</pre>
                    <p><strong>Attributes:</strong></p>
                    <ul>
                        <li><code>folder_id</code> - folder ID (required). Use <code>-1</code> for All Files or <code>0</code> for Uncategorized.</li>
                        <li><code>columns</code> - Number of columns: 2, 3, or 4 (default: 3)</li>
                        <li><code>size</code> - Image size: thumbnail, medium, large, full (default: large)</li>
                        <li><code>title</code> - Gallery title (only shown on root folder view)</li>
                    </ul>

                    <h4>Show Child Folders</h4>
                    <pre>[bilde_folders parent_id="1"]</pre>
                    <p>Displays clickable folder buttons for navigating subfolders.</p>

                    <h4>Show Breadcrumbs</h4>
                    <pre>[bilde_breadcrumbs root_id="1"]</pre>
                    <p>Displays navigation breadcrumb trail.</p>
                </div>

                <div class="card">
                    <h3>&#128269; Finding Your Folder ID</h3>
                    <p>Add <code>[bilde_debug_folders]</code> to any page to see all folders and their IDs.</p>
                </div>

                <div class="card" style="border-left: 4px solid #dc3545;">
                    <h3>&#9888;&#65039; Important Notes</h3>
                    <ul>
                        <li><strong>Real pages always take priority</strong> - If you have a page at <code>/<?php echo esc_html($base_path); ?>/commercial/</code>, it loads that page, not folder navigation</li>
                        <li><strong>After changing settings:</strong> Go to Settings &rarr; Permalinks &rarr; Save Changes to flush rewrite rules</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}
