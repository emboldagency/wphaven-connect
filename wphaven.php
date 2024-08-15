<?php
/*
Plugin Name: WPHaven Connect
Description: A plugin that provides functionality to connect to WPHaven.
Version: 0.0.5
Author: Embold
*/

if (!defined('ABSPATH')) {
    exit;
}

require 'vendor/autoload.php';
require 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use WPHavenConnect\Providers\ServiceProvider;

class WPHavenConnect {

    private $update_checker;

    public function __construct() {
        $this->init_update_checker();
        new ServiceProvider();
    }

    private function init_update_checker() {
        $this->update_checker = PucFactory::buildUpdateChecker(
            'https://github.com/emboldagency/wphaven-connect/',
            __FILE__,
            'wphaven-connect'
        );
        // Set authentication and enable release assets
        $this->update_checker->getVcsApi()->enableReleaseAssets();
    }
}

// Initialize the plugin
new WPHavenConnect();
