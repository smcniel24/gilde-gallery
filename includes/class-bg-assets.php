<?php
/**
 * Frontend/admin asset registration.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BG_Assets
{
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /**
     * Enqueue plugin styles for the frontend
     */
    public static function enqueue_styles(): void
    {
        self::enqueue_bootstrap_icons();

        wp_enqueue_style(
            'bilde-frontend',
            BILDE_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            BILDE_VERSION
        );

        wp_enqueue_script(
            'bilde-frontend',
            BILDE_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            BILDE_VERSION,
            true
        );
    }

    /**
     * Enqueue Bootstrap Icons font CSS
     */
    public static function enqueue_bootstrap_icons(): void
    {
        wp_enqueue_style(
            'bootstrap-icons',
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
            array(),
            '1.11.3'
        );
    }
}
