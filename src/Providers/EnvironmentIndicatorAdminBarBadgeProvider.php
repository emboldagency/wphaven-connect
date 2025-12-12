<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Utilities\Environment;
use WPHavenConnect\Utilities\ElevatedUsers;

// TODO: Build feature flag system to enable/disable this feature.
// use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Adds hooks to add a badge to the WordPress admin bar showing the current environment type.
 */
class EnvironmentIndicatorAdminBarBadgeProvider
{

    public function register()
    {
        // Check if feature is enabled via setting or constant
        if (!$this->isEnvironmentIndicatorEnabled()) {
            return;
        }

        add_action('init', [$this, 'init_hooks']);
    }

    /**
     * Determine if environment indicator should be shown
     * Checks constant first, then option, defaults to true (show indicator)
     *
     * @return bool True if environment indicator should be shown
     */
    private function isEnvironmentIndicatorEnabled(): bool
    {
        // Constant takes priority
        if (defined('WPH_SHOW_ENVIRONMENT_INDICATOR')) {
            return (bool) constant('WPH_SHOW_ENVIRONMENT_INDICATOR');
        }

        // Plugin option
        $opts = get_option('wphaven_connect_options', []);
        if (isset($opts['show_environment_indicator'])) {
            return (bool) $opts['show_environment_indicator'];
        }

        // Default: show indicator (return true)
        return true;
    }

    /**
     * Sets up the hooks if user has required capabilities.
     *
     * @internal
     */
    public function init_hooks()
    {
        // Only allow administrators who are also elevated users (regardless of custom list).
        $is_elevated = ElevatedUsers::currentIsElevated();
        $is_admin = current_user_can('administrator');

        if (!is_user_logged_in() || !$is_admin || !$is_elevated) {
            return;
        }

        add_action('admin_bar_menu', [$this, 'environment_indicator_badge'], 32);
        add_action('admin_bar_menu', [$this, 'environment_indicator_badge_mobile'], 1);
        add_action('wp_head', [$this, 'output_css']);
        add_action('admin_head', [$this, 'output_css']);
    }

    private bool $calculated = false;

    /**
     * Get environment information shared across badge types.
     * 
     * @return array Environment data including key, labels, and menu_id
     */
    private function get_environment_data(): array
    {
        $labels = [
            'development' => 'Development',
            'staging' => 'Staging',
            'production' => 'Production',
        ];

        $key = Environment::get_environment();
        $menu_id = 'wphaven-environment-indicator-badge';

        return [
            'key' => $key,
            'labels' => $labels,
            'menu_id' => $menu_id,
        ];
    }

    /**
     * Add environment indicator badge to WP admin bar for desktop.
     *
     * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
     */
    public function environment_indicator_badge($wp_admin_bar)
    {
        $env_data = $this->get_environment_data();
        $key = $env_data['key'];
        $labels = $env_data['labels'];
        $menu_id = $env_data['menu_id'];

        $args = [
            'id' => $menu_id,
            'title' => $labels[$key],
            'href' => 'https://wphaven.app',
            'meta' => [
                'class' => "{$menu_id}-{$key}",
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'title' => "This site is running in {$labels[$key]} mode.",
                'aria-label' => "This site is running in {$labels[$key]} mode.",
            ],
        ];

        if ($key == 'default') {
            $args['meta']['title'] = "WP_ENVIRONMENT_TYPE is not set. This is the inferred value, but you should set it explicitly in wp_config.php";
        }

        $wp_admin_bar->add_node($args);

        // Add submenu item
        // $args = [
        //     'parent' => $menu_id,
        //     'title' => 'WP Haven',
        //     'meta' => [
        //         'class' => "{$menu_id}-wphaven",
        //         'target' => '_blank',
        //         'rel' => 'noopener noreferrer',
        //     ],
        //     'id' => "{$menu_id}-wphaven",
        //     'href' => 'https://wphaven.app',
        // ];
        // $wp_admin_bar->add_node($args);
    }

    /**
     * Add environment indicator badge to WP admin bar for mobile.
     *
     * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
     */
    public function environment_indicator_badge_mobile($wp_admin_bar)
    {
        $env_data = $this->get_environment_data();
        $key = $env_data['key'];
        $labels = $env_data['labels'];
        $menu_id = $env_data['menu_id'];

        // Add an item to the appearance menu on mobile
        $args = [
            'parent' => 'site-name',
            'title' => "$labels[$key] Mode",
            'meta' => [
                'class' => "{$menu_id}-{$key}",
                'title' => "This site is running in {$labels[$key]} mode.",
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ],
            'id' => "{$menu_id}-mobile",
            // 'href' => 'https://wphaven.app',
            'href' => false,
        ];

        $wp_admin_bar->add_node($args);
    }

    /**
     * Output CSS for environment indicator badge.
     * 
     * @internal
     */
    public function output_css()
    {
        if (!is_admin_bar_showing()) {
            return;
        }

        $env_key = Environment::get_environment();
        // $env_vars = $this->get_environment_vars($env_key);

        // Inject current environment colors directly into the page
        echo '<style>
            #wpadminbar {
                --current-environment-bg: var(--wphaven-environment-' . $env_key . '-bg);
                --current-environment-bg-hover: var(--wphaven-environment-' . $env_key . '-bg-hover);
                --current-environment-text: var(--wphaven-environment-' . $env_key . '-text);
            }
        </style>';

        // Load the rest of the CSS
        $css_file = dirname(__DIR__, 2) . '/src/assets/css/environment-indicator.css';
        if (file_exists($css_file)) {
            echo '<style>' . file_get_contents($css_file) . '</style>';
        }
    }

    // TODO: Implement this method to return environments details from WP Haven.
    // private function get_environments(): array
    // {
    // }
}
