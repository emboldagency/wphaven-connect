<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\TransferAuth;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\UploadsSync\UploadsRepository;
use WPHavenConnect\Utilities\ElevatedUsers;
use WPHavenConnect\Utilities\Environment;

/**
 * "Uploads Sync": additively copy the wp-content/uploads tree between this
 * environment and the configured Production URL, in either direction.
 *
 * Mirrors the Database Transfer pattern: every environment runs the receiver
 * REST routes; only non-production initiates. A plan (the diff of the two file
 * manifests) is computed once and stored in a transient, then the browser loops
 * a batch step that transfers files a byte-budget at a time — large files stream
 * in ranges so nothing is memory-bound. Additive only: files are created or
 * overwritten, never deleted.
 */
class UploadsSyncServiceProvider
{
    const AJAX_ACTION = 'wphaven_uploads_sync';

    const NONCE_ACTION = 'wphaven_uploads_sync';

    const DEFAULT_CHUNK_BYTES = 4194304; // 4 MB

    const PLAN_TRANSIENT_PREFIX = 'wphaven_uploads_plan_';

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
        $routes = [
            '/uploads/manifest' => 'handleManifest',
            '/uploads/fetch'    => 'handleFetch',
            '/uploads/receive'  => 'handleReceive',
        ];

        foreach ($routes as $path => $callback) {
            register_rest_route('wphaven-connect/v1', $path, [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, $callback],
                'permission_callback' => $permission,
            ]);
        }
    }

    // --- REST receiver endpoints ---------------------------------------------

    public function handleManifest(): WP_REST_Response
    {
        return new WP_REST_Response(['files' => (new UploadsRepository())->manifest()], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleFetch(WP_REST_Request $request)
    {
        $result = (new UploadsRepository())->readRange(
            (string) $request->get_param('path'),
            (int) $request->get_param('offset'),
            (int) $request->get_param('length')
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleReceive(WP_REST_Request $request)
    {
        $repo   = new UploadsRepository();
        $path   = (string) $request->get_param('path');
        $offset = (int) $request->get_param('offset');
        $bytes  = base64_decode((string) $request->get_param('data'), true);
        if ($bytes === false) {
            return new WP_Error('wphaven_uploads_bad_data', __('Invalid file data.', 'wphaven-connect'), ['status' => 400]);
        }

        $written = $repo->writeRange($path, $offset, $bytes);
        if (is_wp_error($written)) {
            return $written;
        }

        if ($request->get_param('done')) {
            $repo->setMtime($path, (int) $request->get_param('mtime'));
        }

        return new WP_REST_Response(['ok' => true, 'written' => strlen($bytes)], 200);
    }

    // --- Admin-ajax orchestrator (runs on the initiating, non-prod site) ------

    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! $this->userCanTransfer() || Environment::is_production()) {
            wp_send_json_error(['message' => __('Uploads sync can only be started from a non-production environment.', 'wphaven-connect')], 403);
        }
        if (TransferClient::productionUrl() === null || ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set a Production URL and connection secret first.', 'wphaven-connect')], 400);
        }

        $phase = sanitize_key($_POST['phase'] ?? '');

        $result = $phase === 'plan' ? $this->plan() : $this->batch();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], 200);
        }

        wp_send_json_success($result);
    }

    /**
     * Diff the two manifests into a work list and stash it in a transient.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function plan()
    {
        $direction = sanitize_key($_POST['direction'] ?? '');
        $overwrite = ! empty($_POST['overwrite']);
        $client    = new TransferClient();

        $remote = $client->uploadsManifest();
        if (is_wp_error($remote)) {
            return $remote;
        }
        $remote_files = isset($remote['files']) && is_array($remote['files']) ? $remote['files'] : [];
        $local_files  = (new UploadsRepository())->manifest();

        $map_of = static function (array $files): array {
            $map = [];
            foreach ($files as $file) {
                $map[$file['path']] = (int) $file['size'];
            }
            return $map;
        };

        // Push sends local files the remote lacks; pull fetches remote files this site lacks.
        $source_files = $direction === 'pull' ? $remote_files : $local_files;
        $other_map    = $direction === 'pull' ? $map_of($local_files) : $map_of($remote_files);

        $plan_files  = UploadsRepository::diff($source_files, $other_map, $overwrite);
        $total_bytes = array_sum(array_column($plan_files, 'size'));

        $token = wp_generate_uuid4();
        set_transient(self::PLAN_TRANSIENT_PREFIX . $token, [
            'direction' => $direction,
            'files'     => $plan_files,
        ], HOUR_IN_SECONDS);

        return ['token' => $token, 'total' => count($plan_files), 'totalBytes' => $total_bytes];
    }

    /**
     * Transfer one byte-budget's worth of the plan and return the next cursor.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function batch()
    {
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $plan  = get_transient(self::PLAN_TRANSIENT_PREFIX . $token);
        if (! is_array($plan) || ! isset($plan['files'])) {
            return new WP_Error('wphaven_uploads_plan_expired', __('The transfer plan expired. Start again.', 'wphaven-connect'), ['status' => 410]);
        }

        $files = $plan['files'];
        $total = count($files);
        $index = max(0, (int) ($_POST['fileIndex'] ?? 0));
        $offset = max(0, (int) ($_POST['fileOffset'] ?? 0));

        if ($index >= $total) {
            return ['done' => true, 'index' => $index, 'offset' => 0, 'total' => $total];
        }

        $file   = $files[$index];
        $budget = $this->chunkBytes();
        $result = $plan['direction'] === 'pull'
            ? $this->pullChunk($file, $offset, $budget)
            : $this->pushChunk($file, $offset, $budget);

        if (is_wp_error($result)) {
            // Skip this file and continue; surface a warning.
            return ['index' => $index + 1, 'offset' => 0, 'done' => ($index + 1) >= $total, 'total' => $total, 'path' => $file['path'], 'warning' => $result->get_error_message()];
        }

        $next_index  = $result['eof'] ? $index + 1 : $index;
        $next_offset = $result['eof'] ? 0 : $offset + $result['length'];

        return [
            'index'  => $next_index,
            'offset' => $next_offset,
            'done'   => $next_index >= $total,
            'total'  => $total,
            'path'   => $file['path'],
        ];
    }

    /**
     * Read a range locally and send it to the remote.
     *
     * @param array{path: string, size: int, mtime: int} $file
     * @return array{eof: bool, length: int}|WP_Error
     */
    private function pushChunk(array $file, int $offset, int $budget)
    {
        $read = (new UploadsRepository())->readRange($file['path'], $offset, $budget);
        if (is_wp_error($read)) {
            return $read;
        }

        $length = $read['eof'] ? max(0, $file['size'] - $offset) : $budget;
        $sent = (new TransferClient())->uploadsReceive(
            $file['path'],
            $offset,
            $read['data'],
            (int) $file['size'],
            (int) $file['mtime'],
            (bool) $read['eof']
        );
        if (is_wp_error($sent)) {
            return $sent;
        }

        return ['eof' => (bool) $read['eof'], 'length' => $length];
    }

    /**
     * Fetch a range from the remote and write it locally.
     *
     * @param array{path: string, size: int, mtime: int} $file
     * @return array{eof: bool, length: int}|WP_Error
     */
    private function pullChunk(array $file, int $offset, int $budget)
    {
        $fetch = (new TransferClient())->uploadsFetch($file['path'], $offset, $budget);
        if (is_wp_error($fetch)) {
            return $fetch;
        }

        $bytes = base64_decode((string) ($fetch['data'] ?? ''), true);
        if ($bytes === false) {
            return new WP_Error('wphaven_uploads_bad_data', __('Invalid file data from remote.', 'wphaven-connect'));
        }

        $repo    = new UploadsRepository();
        $written = $repo->writeRange($file['path'], $offset, $bytes);
        if (is_wp_error($written)) {
            return $written;
        }

        $eof = ! empty($fetch['eof']);
        if ($eof) {
            $repo->setMtime($file['path'], (int) $file['mtime']);
        }

        return ['eof' => $eof, 'length' => strlen($bytes)];
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'uploads' || Environment::is_production() || ! $this->userCanTransfer()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-uploads-sync',
            plugins_url('../../src/assets/js/uploads-sync.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/uploads-sync.js'),
            true
        );

        wp_localize_script('wphaven-uploads-sync', 'wphavenUploadsSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'action'  => self::AJAX_ACTION,
            'i18n'    => [
                'confirmPush' => __('Copy missing uploads to production? (Nothing will be deleted.)', 'wphaven-connect'),
                'confirmPull' => __('Copy missing uploads from production to this environment? (Nothing will be deleted.)', 'wphaven-connect'),
                'planning'    => __('Comparing files…', 'wphaven-connect'),
                'nothing'     => __('Everything is already in sync.', 'wphaven-connect'),
                'working'     => __('%1$s of %2$s files…', 'wphaven-connect'),
                'warn'        => __('! %1$s — %2$s', 'wphaven-connect'),
                'done'        => __('Done — %s files transferred.', 'wphaven-connect'),
                'error'       => __('Uploads sync failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function chunkBytes(): int
    {
        return max(65536, (int) apply_filters('wphaven_uploads_sync_chunk_bytes', self::DEFAULT_CHUNK_BYTES));
    }

    private function userCanTransfer(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
