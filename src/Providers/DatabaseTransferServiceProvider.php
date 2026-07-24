<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\ContentTransfer\TransferAuth;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\DatabaseTransfer\SearchReplace;
use WPHavenConnect\DatabaseTransfer\TableRepository;
use WPHavenConnect\Utilities\ElevatedUsers;
use WPHavenConnect\Utilities\Environment;

/**
 * "Database Transfer": overwrite whole tables between this environment and the
 * configured Production URL, then rewrite the source domain to the destination's
 * (serialized-safe). Adds the second tab to the WP Haven Connect settings page.
 *
 * Every environment runs the receiver REST routes; only non-production may
 * initiate a transfer (the ajax orchestrator is gated). Import uses a stage
 * table + atomic rename so the live table is never empty mid-transfer.
 *
 * @see \WPHavenConnect\DatabaseTransfer\DatabaseTransferPanel for the tab UI.
 */
class DatabaseTransferServiceProvider
{
    const AJAX_ACTION = 'wphaven_db_transfer';

    const NONCE_ACTION = 'wphaven_db_transfer';

    const DEFAULT_CHUNK = 500;

    const PUSH_PHRASE = 'I am pushing to production';

    const PULL_PHRASE = 'I am pulling from production';

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
            '/database/tables'   => 'handleDbTables',
            '/database/begin'    => 'handleDbBegin',
            '/database/chunk'    => 'handleDbChunk',
            '/database/finalize' => 'handleDbFinalize',
            '/database/export'   => 'handleDbExport',
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

    public function handleDbTables(): WP_REST_Response
    {
        return new WP_REST_Response(['tables' => (new TableRepository())->listTransferableTables()], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleDbBegin(WP_REST_Request $request)
    {
        $repo = new TableRepository();
        $base = $this->paramBase($request);
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        $repo->createStageLike($base, $full);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleDbChunk(WP_REST_Request $request)
    {
        $repo = new TableRepository();
        $base = $this->paramBase($request);
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        $rows = $request->get_param('rows');
        if (! is_array($rows)) {
            return new WP_Error('wphaven_db_no_rows', __('No rows provided.', 'wphaven-connect'), ['status' => 400]);
        }

        $repo->insertRows($repo->stageName($base), $rows);

        return new WP_REST_Response(['inserted' => count($rows)], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleDbFinalize(WP_REST_Request $request)
    {
        $repo = new TableRepository();
        $base = $this->paramBase($request);
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        $source = esc_url_raw((string) $request->get_param('source_site_url'));
        $replaced = (new SearchReplace($source, site_url()))
            ->replaceInTable($repo->wpdb(), $repo->stageName($base), $repo->primaryKey($full));

        $repo->atomicSwap($base, $full);
        $repo->dropBackup($base);

        return new WP_REST_Response(['ok' => true, 'replaced' => $replaced], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function handleDbExport(WP_REST_Request $request)
    {
        $repo = new TableRepository();
        $base = $this->paramBase($request);
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        $offset = max(0, (int) $request->get_param('offset'));
        $limit  = max(1, (int) $request->get_param('limit'));
        $rows   = $repo->readChunk($full, $offset, $limit);

        $response = ['rows' => $rows];
        if ($offset === 0) {
            $response['total'] = $repo->rowCount($full);
        }

        return new WP_REST_Response($response, 200);
    }

    // --- Admin-ajax orchestrator (runs on the initiating, non-prod site) ------

    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! $this->userCanTransfer() || Environment::is_production()) {
            wp_send_json_error(['message' => __('Database transfer can only be started from a non-production environment.', 'wphaven-connect')], 403);
        }
        if (ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set an environment connection secret first.', 'wphaven-connect')], 400);
        }

        $direction = sanitize_key($_POST['direction'] ?? '');
        $phase     = sanitize_key($_POST['phase'] ?? '');
        $base      = sanitize_text_field(wp_unslash($_POST['base'] ?? ''));
        $offset    = max(0, (int) ($_POST['offset'] ?? 0));
        $target    = Environments::cleanLabel($_POST['target'] ?? '');

        if (Environments::urlFor($target) === null) {
            wp_send_json_error(['message' => __('Choose a destination environment first.', 'wphaven-connect')], 400);
        }
        $client = TransferClient::forLabel($target);

        $result = $direction === 'pull'
            ? $this->stepPull($client, $phase, $base, $offset)
            : $this->stepPush($client, $phase, $base, $offset);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], 200);
        }

        wp_send_json_success($result);
    }

    /**
     * One unit of a push (this env → production): read locally, send to remote.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function stepPush(TransferClient $client, string $phase, string $base, int $offset)
    {
        $repo = new TableRepository();
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        if ($phase === 'begin') {
            $begin = $client->dbBegin($base);
            if (is_wp_error($begin)) {
                return $begin;
            }
            return ['phase' => 'chunk', 'offset' => 0, 'total' => $repo->rowCount($full), 'done' => false];
        }

        if ($phase === 'chunk') {
            $limit = $this->chunkRows();
            $rows  = $repo->readChunk($full, $offset, $limit);
            if ($rows) {
                $sent = $client->dbChunk($base, $rows);
                if (is_wp_error($sent)) {
                    return $sent;
                }
            }
            $count = count($rows);
            $next  = $count < $limit ? 'finalize' : 'chunk';
            return ['phase' => $next, 'offset' => $offset + $count, 'done' => false];
        }

        // finalize
        $final = $client->dbFinalize($base, site_url());
        if (is_wp_error($final)) {
            return $final;
        }
        return ['phase' => 'done', 'done' => true, 'replaced' => $final['replaced'] ?? null];
    }

    /**
     * One unit of a pull (production → this env): fetch from remote, write here.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function stepPull(TransferClient $client, string $phase, string $base, int $offset)
    {
        $repo = new TableRepository();
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            return $full;
        }

        if ($phase === 'begin') {
            $repo->createStageLike($base, $full);
            return ['phase' => 'chunk', 'offset' => 0, 'done' => false];
        }

        if ($phase === 'chunk') {
            $limit  = $this->chunkRows();
            $export = $client->dbExport($base, $offset, $limit);
            if (is_wp_error($export)) {
                return $export;
            }
            $rows = isset($export['rows']) && is_array($export['rows']) ? $export['rows'] : [];
            if ($rows) {
                $repo->insertRows($repo->stageName($base), $rows);
            }
            $count    = count($rows);
            $response = ['phase' => $count < $limit ? 'finalize' : 'chunk', 'offset' => $offset + $count, 'done' => false];
            if (isset($export['total'])) {
                $response['total'] = (int) $export['total'];
            }
            return $response;
        }

        // finalize — rewrite the source (peer) domain to this site's.
        $replaced = (new SearchReplace((string) $client->peerUrl(), site_url()))
            ->replaceInTable($repo->wpdb(), $repo->stageName($base), $repo->primaryKey($full));
        $repo->atomicSwap($base, $full);
        $repo->dropBackup($base);

        return ['phase' => 'done', 'done' => true, 'replaced' => $replaced];
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'database' || Environment::is_production() || ! $this->userCanTransfer()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-db-transfer',
            plugins_url('../../src/assets/js/database-transfer.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/database-transfer.js'),
            true
        );

        wp_localize_script('wphaven-db-transfer', 'wphavenDbTransfer', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce(self::NONCE_ACTION),
            'action'          => self::AJAX_ACTION,
            'productionLabel' => Environments::PRODUCTION_LABEL,
            'pushPhrase'      => self::PUSH_PHRASE,
            'i18n'            => [
                'noTables'    => __('Select at least one table.', 'wphaven-connect'),
                'pushTo'      => __('Send to %s', 'wphaven-connect'),
                'pullFrom'    => __('Pull from %s', 'wphaven-connect'),
                'confirmPush' => __('This will OVERWRITE the selected tables on "%s". Continue?', 'wphaven-connect'),
                'confirmPull' => __('This will OVERWRITE the selected tables on this environment with "%s". Continue?', 'wphaven-connect'),
                'working'     => __('Transferring %1$s (%2$s)…', 'wphaven-connect'),
                'tableDone'   => __('✓ %s', 'wphaven-connect'),
                'tableFail'   => __('✗ %1$s — %2$s', 'wphaven-connect'),
                'allDone'     => __('Done.', 'wphaven-connect'),
                'error'       => __('Transfer failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function chunkRows(): int
    {
        return max(1, (int) apply_filters('wphaven_db_transfer_chunk_rows', self::DEFAULT_CHUNK));
    }

    private function paramBase(WP_REST_Request $request): string
    {
        return sanitize_text_field((string) $request->get_param('base'));
    }

    private function userCanTransfer(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
