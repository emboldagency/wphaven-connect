<?php
/**
 * @wordpress-plugin
 * Plugin Name:        WPHaven Connect
 * Plugin URI:         https://embold.com
 * Description:        A plugin that provides functionality to connect to WPHaven.
 * Version:            0.19.2
 * Author:             emBold
 * Author URI:         https://embold.com/
 * Primary Branch:     master
 */

if (!defined('ABSPATH')) {
	exit;
}

require 'plugin-update-checker/plugin-update-checker.php';

function wphaven_connect_vendor_autoload_path()
{
	return __DIR__ . '/vendor/autoload.php';
}

function wphaven_connect_has_vendor()
{
	return file_exists(wphaven_connect_vendor_autoload_path());
}

function wphaven_connect_try_composer_install()
{
	$disabled = ini_get('disable_functions');
	$can_shell = true;
	foreach (['exec', 'shell_exec', 'passthru', 'system'] as $fn) {
		if (stripos($disabled, $fn) !== false) {
			$can_shell = false;
			break;
		}
	}
	if (!$can_shell) {
		return false;
	}
	$bin = getenv('COMPOSER_BIN');
	if (!$bin) {
		$bin = trim(@shell_exec('command -v composer')); 
		if (!$bin) {
			$bin = trim(@shell_exec('which composer'));
		}
	}
	if (!$bin) {
		return false;
	}
	@set_time_limit(300);
	$cmd = escapeshellcmd($bin) . ' install --no-dev --prefer-dist --no-interaction --no-progress';
	$cwd = __DIR__;
	$env = $_ENV;
	$env['COMPOSER'] = 'composer.json';
	$descriptorSpec = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w']
	];
	$process = @proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
	if (!is_resource($process)) {
		return false;
	}
	@fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	@fclose($pipes[1]);
	@fclose($pipes[2]);
	$exitCode = @proc_close($process);
	if ($exitCode !== 0) {
		return false;
	}
	return wphaven_connect_has_vendor();
}

function wphaven_connect_admin_notice()
{
	if (!current_user_can('activate_plugins')) {
		return;
	}
	if (wphaven_connect_has_vendor()) {
		return;
	}
	echo "<div class='notice notice-error'><p>WPHaven Connect requires Composer dependencies. Please run <code>composer install --no-dev</code> in the plugin directory or use WP-CLI: <code>wp wphaven install-deps</code>. If shell access is unavailable, download the release zip.</p></div>";
}

add_action('admin_notices', 'wphaven_connect_admin_notice');

function wphaven_connect_get_filesystem()
{
	if (!function_exists('WP_Filesystem')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$creds = WP_Filesystem();
	global $wp_filesystem;
	if (!$creds || !$wp_filesystem) {
		return null;
	}
	return $wp_filesystem;
}

function wphaven_connect_cleanup_dev_files()
{
	$plugin_dir = __DIR__;
	$distignore_file = $plugin_dir . '/.distignore';

	if (!file_exists($distignore_file)) {
		return;
	}

	$lines = file($distignore_file, FILE_IGNORE_NEW_LINES);
	if (!$lines) {
		return;
	}

	$fs = wphaven_connect_get_filesystem();
	if (!$fs) {
		return;
	}

	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		if ($line[0] === '!') {
			continue; // keep vendor inclusions
		}

		$pattern = rtrim($line, '/');
		$glob_pattern = $plugin_dir . '/' . $pattern;
		$targets = [];
		if (strpbrk($pattern, '*?[') !== false) {
			$targets = glob($glob_pattern, GLOB_BRACE) ?: [];
		} else {
			$targets = [$glob_pattern];
		}

		foreach ($targets as $path) {
			if (is_dir($path)) {
				$fs->delete($path, true, 'd');
			} elseif (is_file($path)) {
				$fs->delete($path, false, 'f');
			}
		}
	}
}

register_activation_hook(__FILE__, function () {
	if (!wphaven_connect_has_vendor()) {
		wphaven_connect_try_composer_install();
	}
	wphaven_connect_cleanup_dev_files();
});

// Also run cleanup on first admin_init after activation as a fallback
add_action('admin_init', function () {
	$cleanup_done = get_transient('wphaven_cleanup_done');
	if (!$cleanup_done) {
		wphaven_connect_cleanup_dev_files();
		set_transient('wphaven_cleanup_done', 1, DAY_IN_SECONDS);
	}
}, 1);

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::add_command('wphaven install-deps', function () {
		if (wphaven_connect_has_vendor()) {
			WP_CLI::success('Dependencies already installed.');
			return;
		}
		$ok = wphaven_connect_try_composer_install();
		if ($ok) {
			WP_CLI::success('Composer dependencies installed.');
		} else {
			WP_CLI::error('Failed to install dependencies. Ensure Composer is available and shell functions are enabled.');
		}
	});

	WP_CLI::add_command('wphaven cleanup-dev', function () {
		wphaven_connect_cleanup_dev_files();
		WP_CLI::success('Development files cleaned up.');
	});
}

if (wphaven_connect_has_vendor()) {
	require_once wphaven_connect_vendor_autoload_path();
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use WPHavenConnect\Providers\ServiceProvider;
use WPHavenConnect\Providers\DeactivationProvider;

// Import the TextdomainNoticeSuppressionProvider to ensure it is registered early
use WPHavenConnect\Providers\TextdomainNoticeSuppressionProvider;

class WPHavenConnect
{

	private $update_checker;

	public function __construct()
	{
		// Register deactivation hook early
		DeactivationProvider::register();

		// Initialize textdomain suppression immediately when plugin loads
		// This needs to happen before other plugins load to ensure MU plugin is created early
		$this->init_textdomain_suppression();

		$this->init_update_checker();
		add_action('plugins_loaded', [$this, 'init_services'], 0);
	}

	private function init_textdomain_suppression()
	{
		// Register the textdomain suppression provider immediately
		(new TextdomainNoticeSuppressionProvider())->register();
	}

	public function init_services()
	{
		new ServiceProvider();
	}

	private function init_update_checker()
	{
		$this->update_checker = PucFactory::buildUpdateChecker(
			'https://github.com/emboldagency/wphaven-connect/',
			__FILE__,
			'wphaven-connect'
		);
		$this->update_checker->getVcsApi()->enableReleaseAssets();
	}
}

if (wphaven_connect_has_vendor()) {
	new WPHavenConnect();
}
