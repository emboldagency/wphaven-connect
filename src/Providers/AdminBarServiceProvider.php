<?php

namespace WPHavenConnect\Providers;

class AdminBarServiceProvider
{
    private $calculated = false;

    public function register()
    {
        add_action('admin_bar_menu', [$this, 'add_environment_indicator_to_admin_bar'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_environment_indicator_admin_css']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_environment_indicator_css']);
    }

    public function add_environment_indicator_to_admin_bar($wp_admin_bar)
    {
        $environment = $this->determine_environment();
        $vars = $this->get_environment_vars($environment);
        $background_color = $vars['background-color'];
        $text_color = $vars['text-color'];
        $label = esc_html(ucfirst($vars['label']));
        $label_abbr = esc_html(ucfirst($vars['label-abbr']));
        $calculated = $this->calculated;

        // Add a warning if the environment was calculated
        $tooltip = '';
        if ($calculated) {
            $label .= ' ⚠️';
            $label_abbr .= '⚠️';
            $tooltip = 'WP_ENVIRONMENT_TYPE is not set. This is the inferred value, but you should set it explicitly in wp_config.php';
        }

        $title = <<<HTML
            <span class="ab-icon">$label_abbr</span><span class="ab-label">$label</span>
        HTML;

        $style = <<<HTML
        <style>#wp-admin-bar-environment-indicator{--background-color:$background_color;--text-color:$text_color;}</style>
        HTML;

        $environment_indicator_node = [
            'id' => 'environment-indicator',
            'title' => $title,
            'href' => false,
            'meta' => [
                'html' => $style,
            ],
            'parent' => 'top-secondary', // Add to the secondary top-level admin bar menu
        ];

        if (!empty($tooltip)) {
            $environment_indicator_node['meta'] = [
                'title' => $tooltip
            ];
        }

        // Add the main environment indicator node
        $wp_admin_bar->add_node($environment_indicator_node);
    }

    function enqueue_environment_indicator_admin_css() {
        wp_enqueue_style('environment-indicator', plugins_url('../assets/css/environment-indicator.css', __FILE__));
    }

    function enqueue_environment_indicator_css() {
        // If the user is logged in and not in the admin area, enqueue the CSS
        if (is_user_logged_in() && !is_admin()) {
            wp_enqueue_style('environment-indicator', plugins_url('../assets/css/environment-indicator.css', __FILE__));
        }
    }

    private function determine_environment()
    {
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return WP_ENVIRONMENT_TYPE;
        } elseif (defined('WP_ENV')) {
            return WP_ENV;
        } elseif (defined('WP_ENVIRONMENT')) {
            return WP_ENVIRONMENT;
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $this->calculated = true;
            return $this->calculate_environment_from_server_name($_SERVER['SERVER_NAME']);
        } else {
            $this->calculated = true;
            return 'Unknown Environment';
        }
    }

    private function calculate_environment_from_server_name($server_name)
    {
        if (strpos($server_name, '.embold.dev') !== false || strpos($server_name, '.local') !== false) {
            return 'development';
        } elseif (strpos($server_name, '.embold.net') !== false || strpos($server_name, 'staging.') !== false) {
            return 'staging';
        } elseif (strpos($server_name, '.wphaven.dev') !== false) {
            return 'maintenance';
        } else {
            return 'production';
        }
    }

    private function get_environment_vars($environment)
    {
        $vars = [
            'development' => [
                'background-color' => 'rgb(255, 245, 242)',
                'text-color' => 'rgb(255, 60, 56)',
                'label' => 'Development',
                'label-abbr' => 'Dev'
            ],
            'staging' => [
                'background-color' => 'rgb(254, 249, 195)',
                'text-color' => 'rgb(202, 138, 4)',
                'label' => 'Staging',
                'label-abbr' => 'Stage'
            ],
            'maintenance' => [
                'background-color' => 'rgb(224, 242, 254)',
                'text-color' => 'rgb(90, 144, 191)',
                'label' => 'Maintenance',
                'label-abbr' => 'Maint'
            ],
            'production' => [
                'background-color' => 'rgb(220, 252, 231)',
                'text-color' => 'rgb(22, 163, 74)',
                'label' => 'Production',
                'label-abbr' => 'Prod'
            ],
            'default' => [
                'background-color' => 'rgb(219, 213, 220)',
                'text-color' => 'rgb(72, 67, 73)',
                'label' => 'Unknown Environment',
                'label-abbr' => '???'
            ],
        ];

        return $vars[strtolower($environment)] ?? $vars['default'];
    }
}
