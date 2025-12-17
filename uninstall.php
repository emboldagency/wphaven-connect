<?php

/**
 * WP Haven Connect - Uninstall Hook
 *
 * This file is called when the plugin is deleted via the WordPress dashboard.
 * It handles cleanup of MU-plugin files created by this plugin.
 *
 * Note: Plugin settings are preserved to allow for reinstallation without losing configuration.
 * Users can manually reset settings using the "Reset Settings" button if desired.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the autoloader
$plugin_dir = __DIR__;
if (file_exists($plugin_dir . '/vendor/autoload.php')) {
    require_once $plugin_dir . '/vendor/autoload.php';
}

use WPHavenConnect\Utilities\MuPluginManager;

// Clean up MU-plugin
if (class_exists('WPHavenConnect\Utilities\MuPluginManager')) {
    MuPluginManager::deleteMuPlugin();
}

// Log uninstall completion
error_log('[WPHavenConnect] Plugin uninstalled and MU-plugin cleaned up');
