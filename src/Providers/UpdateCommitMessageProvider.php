<?php

namespace WPHavenConnect\Providers;

use function wp_localize_script;

/**
 * Provides functionality to generate git commit messages for WordPress plugin and theme updates.
 * Integrates with wp-admin/update-core.php and wp-admin/plugins.php pages.
 */
class UpdateCommitMessageProvider
{
    public function register()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wphaven_get_update_items', [$this, 'ajax_get_update_items']);
    }

    /**
     * Enqueue the commit message generator script and Alpine.js on relevant admin pages.
     */
    public function enqueue_assets($hook_suffix)
    {
        // Only load on update-core.php, plugins.php, and themes.php
        if (!in_array($hook_suffix, ['update-core.php', 'plugins.php', 'themes.php'], true)) {
            return;
        }

        // Enqueue Alpine.js from CDN
        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.x.x',
            true // Load in footer
        );

        // Enqueue our commit message generator script
        wp_enqueue_script(
            'wphaven-commit-message-generator',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'src/assets/js/commit-message-generator.js',
            ['alpinejs'],
            '1.0.0',
            true // Load in footer to ensure it runs after Alpine and DOM is ready
        );

        // Enqueue styles
        wp_enqueue_style(
            'wphaven-commit-message-generator',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'src/assets/css/commit-message-generator.css',
            [],
            '1.0.0'
        );

        // Localize script with AJAX data and page context
        wp_localize_script(
            'wphaven-commit-message-generator',
            'wphavenCommitGen',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wphaven_commit_gen'),
                'page' => $this->get_current_page_type($hook_suffix),
            ]
        );
    }

    /**
     * Determine the current page type (updates, plugins, or themes).
     */
    private function get_current_page_type($hook_suffix)
    {
        if ($hook_suffix === 'update-core.php') {
            return 'updates';
        } elseif ($hook_suffix === 'themes.php') {
            return 'themes';
        }
        return 'plugins';
    }

    /**
     * AJAX handler to retrieve update items (plugins and themes).
     * Returns data without relying on fragile DOM selectors.
     */
    public function ajax_get_update_items()
    {
        // Verify nonce - check POST data for WordPress AJAX
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wphaven_commit_gen')) {
            wp_send_json_error(['message' => 'Security check failed'], 403);
        }

        // Verify user can update plugins/themes
        if (!current_user_can('update_plugins') && !current_user_can('update_themes')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $page = isset($_POST['page']) ? sanitize_text_field($_POST['page']) : 'updates';
        $items = [];

        if ($page === 'updates') {
            $items = array_merge(
                $this->get_plugin_updates(),
                $this->get_theme_updates()
            );
        } elseif ($page === 'plugins') {
            $items = $this->get_installed_plugins();
        } elseif ($page === 'themes') {
            $items = $this->get_installed_themes();
        }

        wp_send_json_success(['items' => $items]);
    }

    /**
     * Get available plugin updates from WordPress core.
     */
    private function get_plugin_updates()
    {
        $items = [];

        // Get available updates
        $updates = get_site_transient('update_plugins');
        if (!$updates || empty($updates->response)) {
            return $items;
        }

        // Get all plugins to access current versions
        $all_plugins = get_plugins();

        foreach ($updates->response as $plugin_file => $plugin_data) {
            $slug = dirname($plugin_file);

            // Skip mu-plugins and invalid entries
            if (empty($slug) || $slug === '.') {
                continue;
            }

            $current_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';
            $new_version = $plugin_data->new_version ?? 'unknown';

            // Only include if both versions are available and different
            if ($current_version !== 'unknown' && $new_version !== 'unknown' && $current_version !== $new_version) {
                $items[] = [
                    'slug' => $slug,
                    'path' => $plugin_file,
                    'type' => 'plugin',
                    'versions' => [
                        'current' => $current_version,
                        'new' => $new_version,
                    ],
                ];
            }
        }

        return $items;
    }

    /**
     * Get available theme updates from WordPress core.
     */
    private function get_theme_updates()
    {
        $items = [];

        // Get available updates
        $updates = get_site_transient('update_themes');
        if (!$updates || empty($updates->response)) {
            return $items;
        }

        // Get all themes to access current versions
        $all_themes = wp_get_themes();

        foreach ($updates->response as $theme_slug => $theme_data) {
            $theme = isset($all_themes[$theme_slug]) ? $all_themes[$theme_slug] : null;

            if (!$theme) {
                continue;
            }

            $current_version = $theme->get('Version') ?? 'unknown';
            $new_version = $theme_data['new_version'] ?? 'unknown';

            // Only include if both versions are available and different
            if ($current_version !== 'unknown' && $new_version !== 'unknown' && $current_version !== $new_version) {
                $items[] = [
                    'slug' => $theme_slug,
                    'path' => $theme_slug,
                    'type' => 'theme',
                    'versions' => [
                        'current' => $current_version,
                        'new' => $new_version,
                    ],
                ];
            }
        }

        return $items;
    }

    /**
     * Get all installed plugins (for plugins.php page).
     * Includes information about whether they have available updates.
     */
    private function get_installed_plugins()
    {
        $items = [];

        $all_plugins = get_plugins();
        $updates = get_site_transient('update_plugins');
        $update_response = !empty($updates->response) ? $updates->response : [];

        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = dirname($plugin_file);

            // Skip mu-plugins
            if (empty($slug) || $slug === '.') {
                continue;
            }

            // Check if this plugin has an available update
            if (isset($update_response[$plugin_file])) {
                $update_data = $update_response[$plugin_file];
                $current_version = $plugin_data['Version'] ?? 'unknown';
                $new_version = $update_data->new_version ?? 'unknown';

                if ($current_version !== 'unknown' && $new_version !== 'unknown' && $current_version !== $new_version) {
                    $items[] = [
                        'slug' => $slug,
                        'path' => $plugin_file,
                        'type' => 'plugin',
                        'has_update' => true,
                        'versions' => [
                            'current' => $current_version,
                            'new' => $new_version,
                        ],
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Get all installed themes (for themes.php page).
     * Includes information about whether they have available updates.
     */
    private function get_installed_themes()
    {
        $items = [];

        $all_themes = wp_get_themes();
        $updates = get_site_transient('update_themes');
        $update_response = !empty($updates->response) ? $updates->response : [];

        foreach ($all_themes as $theme_slug => $theme) {
            // Check if this theme has an available update
            if (isset($update_response[$theme_slug])) {
                $current_version = $theme->get('Version') ?? 'unknown';
                $new_version = $update_response[$theme_slug]['new_version'] ?? 'unknown';

                if ($current_version !== 'unknown' && $new_version !== 'unknown' && $current_version !== $new_version) {
                    $items[] = [
                        'slug' => $theme_slug,
                        'path' => $theme_slug,
                        'type' => 'theme',
                        'has_update' => true,
                        'versions' => [
                            'current' => $current_version,
                            'new' => $new_version,
                        ],
                    ];
                }
            }
        }

        return $items;
    }
}