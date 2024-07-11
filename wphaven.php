<?php
/*
Plugin Name: WP Haven
Description: A plugin that provides functionality to connect to WPHaven.
Version: 0.0.1
Author: Embold
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Include the WP CLI command
if (defined('WP_CLI') && WP_CLI) {
    include __DIR__ . '/includes/wp-cli-commands.php';
}

// Add action to handle the wp_admin_token parameter
add_action('init', 'wphaven_handle_wp_admin_token');

require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$embold_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/emboldagency/wphaven-connect/',
    __FILE__,
    'wphaven-connect'
);

// Set authentication and enable release assets
$embold_update_checker->getVcsApi()->enableReleaseAssets();

function wphaven_handle_wp_admin_token() {
    if (isset($_GET['wp_admin_token'])) {
        $user_id = wp_validate_auth_cookie($_GET['wp_admin_token'], 'admin');
        if ($user_id) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            wp_redirect(admin_url());
            exit;
        }
    }
}
