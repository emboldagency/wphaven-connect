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

register_activation_hook(__FILE__, function () {
	if (!wphaven_connect_has_vendor()) {
		wphaven_connect_try_composer_install();
	}
});

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
