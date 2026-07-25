<?php

namespace WPHavenConnect\Providers;

use WP_REST_Response;
use WP_REST_Server;
use WPHavenConnect\Compare\CompareRepository;
use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\ContentTransfer\TransferAuth;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\Utilities\ElevatedUsers;

/**
 * "Compare" tab: a read-only divergence report between this site and a chosen
 * environment — table row counts, uploads totals, and per-post-type content
 * divergence. Environment-to-environment over the connection secret (no WP
 * Haven platform involvement); usable on any environment since it writes
 * nothing.
 */
class CompareServiceProvider
{
    const AJAX_ACTION = 'wphaven_compare';

    const NONCE_ACTION = 'wphaven_compare';

    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);

        if (! is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerRoutes(): void
    {
        $permission = [TransferAuth::class, 'permissionCheck'];

        register_rest_route('wphaven-connect/v1', '/compare/stats', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleStats'],
            'permission_callback' => $permission,
        ]);
        register_rest_route('wphaven-connect/v1', '/compare/content', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleContent'],
            'permission_callback' => $permission,
        ]);
    }

    public function handleStats(): WP_REST_Response
    {
        $repo = new CompareRepository();

        return new WP_REST_Response([
            'tables'  => $repo->tableStats(),
            'uploads' => $repo->uploadsStats(),
        ], 200);
    }

    public function handleContent(): WP_REST_Response
    {
        return new WP_REST_Response([
            'fingerprints' => (new CompareRepository())->contentFingerprints(),
        ], 200);
    }

    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! $this->userCanCompare()) {
            wp_send_json_error(['message' => __('You are not allowed to compare environments.', 'wphaven-connect')], 403);
        }
        if (ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set an environment connection secret first.', 'wphaven-connect')], 400);
        }

        $target = Environments::cleanLabel($_POST['target'] ?? '');
        if (Environments::urlFor($target) === null) {
            wp_send_json_error(['message' => __('Choose an environment to compare with.', 'wphaven-connect')], 400);
        }

        $client = TransferClient::forLabel($target);
        $repo   = new CompareRepository();
        $phase  = sanitize_key($_POST['phase'] ?? 'stats');

        if ($phase === 'content') {
            $remote = $client->compareContent();
            if (is_wp_error($remote)) {
                wp_send_json_error(['message' => $remote->get_error_message()], 200);
            }
            $remote_fp = isset($remote['fingerprints']) && is_array($remote['fingerprints']) ? $remote['fingerprints'] : [];
            wp_send_json_success([
                'content' => CompareRepository::diffContent($repo->contentFingerprints(), $remote_fp),
            ]);
        }

        $remote = $client->compareStats();
        if (is_wp_error($remote)) {
            wp_send_json_error(['message' => $remote->get_error_message()], 200);
        }
        $remote_tables  = isset($remote['tables']) && is_array($remote['tables']) ? $remote['tables'] : [];
        $remote_uploads = isset($remote['uploads']) && is_array($remote['uploads']) ? $remote['uploads'] : ['files' => 0, 'bytes' => 0];

        wp_send_json_success([
            'tables'  => CompareRepository::diffTables($repo->tableStats(), $remote_tables),
            'uploads' => [
                'here'  => $repo->uploadsStats(),
                'there' => $remote_uploads,
            ],
        ]);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'compare' || ! $this->userCanCompare()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-compare',
            plugins_url('../../src/assets/js/compare.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/compare.js'),
            true
        );

        wp_localize_script('wphaven-compare', 'wphavenCompare', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'action'  => self::AJAX_ACTION,
            'i18n'    => [
                'comparing'   => __('Comparing…', 'wphaven-connect'),
                'analyzing'   => __('Analyzing content…', 'wphaven-connect'),
                'inSync'      => __('In sync', 'wphaven-connect'),
                'here'        => __('Here', 'wphaven-connect'),
                'there'       => __('There', 'wphaven-connect'),
                'tables'      => __('Database tables', 'wphaven-connect'),
                'uploads'     => __('Uploads', 'wphaven-connect'),
                'content'     => __('Content divergence', 'wphaven-connect'),
                'files'       => __('files', 'wphaven-connect'),
                'type'        => __('Type', 'wphaven-connect'),
                'differ'      => __('Differ', 'wphaven-connect'),
                'onlyHere'    => __('Only here', 'wphaven-connect'),
                'onlyThere'   => __('Only there', 'wphaven-connect'),
                'identical'   => __('Identical — nothing differs.', 'wphaven-connect'),
                'error'       => __('Compare failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function userCanCompare(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
