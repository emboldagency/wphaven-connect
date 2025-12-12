<?php

/**
 * WP Haven Connect - Uninstall Hook
 *
 * This file is called when the plugin is deleted via the WordPress dashboard.
 * It handles complete cleanup of all plugin data and files.
 *
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Exit if accessed directly or not uninstalling
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the autoloader
$plugin_dir = dirname(__DIR__);
if (file_exists($plugin_dir . '/vendor/autoload.php')) {
    require_once $plugin_dir . '/vendor/autoload.php';
}

use WPHavenConnect\Utilities\MuPluginManager;

// Clean up MU-plugin
MuPluginManager::deleteMuPlugin();

// Delete all plugin settings from wp_options
MuPluginManager::deleteSettings();

// Log uninstall completion
error_log('[WPHavenConnect] Plugin uninstalled and cleaned up');
