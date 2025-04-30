<?php
/**
 * @wordpress-plugin
 * Plugin Name:        WPHaven Connect
 * Plugin URI:         https://embold.com
 * Description:        A plugin that provides functionality to connect to WPHaven.
 * Version:            0.13.0
 * Author:             emBold
 * Author URI:         https://embold.com/
 * Primary Branch:     master
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use WPHavenConnect\Providers\ServiceProvider;

class WPHavenConnect {

    private $update_checker;

    public function __construct() {
        $this->init_update_checker();
        add_action('plugins_loaded', [$this, 'init_services'], 0);
    }

    public function init_services() {
        new ServiceProvider();
    }

    private function init_update_checker() {
        $this->update_checker = PucFactory::buildUpdateChecker(
            'https://github.com/emboldagency/wphaven-connect/',
            __FILE__,
            'wphaven-connect'
        );
        $this->update_checker->getVcsApi()->enableReleaseAssets();
    }
}

// Initialize the plugin
new WPHavenConnect();
