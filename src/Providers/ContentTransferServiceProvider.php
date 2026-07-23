<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPHavenConnect\ContentTransfer\ContentIdentity;
use WPHavenConnect\ContentTransfer\ContentImporter;
use WPHavenConnect\ContentTransfer\ContentSerializer;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\Utilities\ElevatedUsers;
use WPHavenConnect\Utilities\Environment;

/**
 * "Send to / Update from Production": move an individual post, page or CPT
 * between a non-production environment and the configured Production URL.
 *
 * Every environment runs this provider as both exporter and receiver. The three
 * REST routes (export / preview / import) are authenticated by the dedicated
 * content-transfer secret -- deliberately NOT reusing ServiceProvider's
 * apiPermissionsCheck, whose ?debug bypass and IP allowlist are unacceptable on
 * routes that overwrite content. The editor buttons only appear on non-production
 * environments and only for elevated admins.
 */
class ContentTransferServiceProvider
{
    const AJAX_ACTION = 'wphaven_content_transfer';

    const NONCE_ACTION = 'wphaven_content_transfer';

    const SECRET_HEADER = 'Authorization';

    public function register()
    {
        add_action('init', [$this, 'registerMeta']);
        add_action('rest_api_init', [$this, 'registerRoutes']);

        if (! is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);

        // Editor UI only on non-production environments, for elevated admins.
        if (Environment::is_production() || ! $this->userCanTransfer()) {
            return;
        }

        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueClassicAssets']);
        add_action('post_submitbox_start', [$this, 'renderClassicButton']);
    }

    public function registerMeta(): void
    {
        register_meta('post', ContentIdentity::META_KEY, [
            'single'       => true,
            'type'         => 'string',
            'show_in_rest' => false,
        ]);
    }

    public function registerRoutes(): void
    {
        $permission = [$this, 'permissionCheck'];

        register_rest_route('wphaven-connect/v1', '/content/export', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleExport'],
            'permission_callback' => $permission,
        ]);

        register_rest_route('wphaven-connect/v1', '/content/preview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handlePreview'],
            'permission_callback' => $permission,
        ]);

        register_rest_route('wphaven-connect/v1', '/content/import', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleImport'],
            'permission_callback' => $permission,
        ]);
    }

    /**
     * Authenticate a transfer request against the dedicated shared secret only.
     *
     * @return true|WP_Error
     */
    public function permissionCheck()
    {
        if (ConnectionSecret::get() === null) {
            return new WP_Error('wphaven_transfer_disabled', __('Content transfer is not configured on this site.', 'wphaven-connect'), ['status' => 403]);
        }

        if (ConnectionSecret::matches($this->bearerToken())) {
            return true;
        }

        return new WP_Error('wphaven_transfer_forbidden', __('Invalid environment connection secret.', 'wphaven-connect'), ['status' => 401]);
    }

    /**
     * Extract the Bearer token, accounting for servers that relocate or strip the
     * Authorization header.
     */
    private function bearerToken(): ?string
    {
        $header = '';

        if (! empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }

    /**
     * REST: return the envelope for a piece of content identified by content id.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleExport(WP_REST_Request $request)
    {
        $content_id = sanitize_text_field((string) $request->get_param('content_id'));
        $post_id    = (int) $request->get_param('local_post_id');

        if ($content_id !== '') {
            $found = ContentIdentity::findLocalPost($content_id);
            if (is_wp_error($found)) {
                return $found;
            }
            $post_id = (int) $found;
        }

        if (! $post_id) {
            return new WP_Error('wphaven_export_not_found', __('No matching content on this site.', 'wphaven-connect'), ['status' => 404]);
        }

        $envelope = (new ContentSerializer())->export($post_id);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        return new WP_REST_Response($envelope, 200);
    }

    /**
     * REST: dry-run an incoming envelope and return the computed diff.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handlePreview(WP_REST_Request $request)
    {
        $envelope = $request->get_param('envelope');
        if (! is_array($envelope)) {
            return new WP_Error('wphaven_envelope_missing', __('No envelope provided.', 'wphaven-connect'), ['status' => 400]);
        }

        $diff = (new ContentImporter())->preview($envelope);
        if (is_wp_error($diff)) {
            return $diff;
        }

        return new WP_REST_Response($diff, 200);
    }

    /**
     * REST: apply an incoming envelope.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleImport(WP_REST_Request $request)
    {
        $envelope = $request->get_param('envelope');
        if (! is_array($envelope)) {
            return new WP_Error('wphaven_envelope_missing', __('No envelope provided.', 'wphaven-connect'), ['status' => 400]);
        }

        $result = (new ContentImporter())->import($envelope, [
            'publish'            => (bool) $request->get_param('publish'),
            'overwrite_conflict' => (bool) $request->get_param('overwrite_conflict'),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Admin-AJAX entry point for the editor buttons. Drives a push (this site ->
     * production) or pull (production -> this site), optionally as a dry run.
     */
    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! $this->userCanTransfer()) {
            wp_send_json_error(['message' => __('You are not allowed to transfer content.', 'wphaven-connect')], 403);
        }

        $post_id   = (int) ($_POST['post_id'] ?? 0);
        $direction = sanitize_key($_POST['direction'] ?? '');
        $preview   = ! empty($_POST['preview']);
        $args      = [
            'publish'            => ! empty($_POST['publish']),
            'overwrite_conflict' => ! empty($_POST['overwrite_conflict']),
        ];

        if (! $post_id || ! current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Invalid post.', 'wphaven-connect')], 400);
        }

        if (TransferClient::productionUrl() === null) {
            wp_send_json_error(['message' => __('Set a Production URL in WP Haven Connect settings first.', 'wphaven-connect')], 400);
        }
        if (ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set an environment connection secret in WP Haven Connect settings first.', 'wphaven-connect')], 400);
        }

        $result = $direction === 'pull'
            ? $this->doPull($post_id, $preview, $args)
            : $this->doPush($post_id, $preview, $args);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'data'    => $result->get_error_data(),
            ], 200);
        }

        wp_send_json_success($result);
    }

    /**
     * @param array{publish?: bool, overwrite_conflict?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    private function doPush(int $post_id, bool $preview, array $args)
    {
        $envelope = (new ContentSerializer())->export($post_id);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $client = new TransferClient();

        return $preview ? $client->previewOnRemote($envelope) : $client->push($envelope, $args);
    }

    /**
     * @param array{publish?: bool, overwrite_conflict?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    private function doPull(int $post_id, bool $preview, array $args)
    {
        $content_id = ContentIdentity::get($post_id);
        if ($content_id === null) {
            return new WP_Error('wphaven_no_link', __('This post has never been transferred, so there is no linked production copy to pull.', 'wphaven-connect'));
        }

        $envelope = (new TransferClient())->fetchExport($content_id);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $importer = new ContentImporter();

        return $preview ? $importer->preview($envelope) : $importer->import($envelope, $args);
    }

    public function enqueueBlockEditorAssets(): void
    {
        if (! $this->isEditablePostScreen()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-content-transfer',
            $this->assetUrl('src/assets/js/content-transfer.js'),
            ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
            $this->assetVersion('src/assets/js/content-transfer.js'),
            true
        );

        wp_localize_script('wphaven-content-transfer', 'wphavenContentTransfer', $this->localizeData());
    }

    public function enqueueClassicAssets(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return; // Handled by enqueueBlockEditorAssets().
        }

        wp_enqueue_script(
            'wphaven-content-transfer',
            $this->assetUrl('src/assets/js/content-transfer.js'),
            ['jquery'],
            $this->assetVersion('src/assets/js/content-transfer.js'),
            true
        );

        wp_localize_script('wphaven-content-transfer', 'wphavenContentTransfer', $this->localizeData());
    }

    public function renderClassicButton(): void
    {
        global $post;
        if (! $post instanceof WP_Post || ! $this->isTransferablePostType($post->post_type)) {
            return;
        }

        echo '<div class="wphaven-content-transfer misc-pub-section" style="padding:8px 0;">';
        echo '<button type="button" class="button wphaven-send-to-production" style="display:block;width:100%;text-align:center;margin-bottom:6px;">' . esc_html__('Send to Production', 'wphaven-connect') . '</button>';
        echo '<button type="button" class="button wphaven-update-from-production" style="display:block;width:100%;text-align:center;">' . esc_html__('Update from Production', 'wphaven-connect') . '</button>';
        echo '<p class="wphaven-transfer-status description"></p>';
        echo '</div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function localizeData(): array
    {
        global $post;
        $post_id = $post instanceof WP_Post ? $post->ID : 0;

        return [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(self::NONCE_ACTION),
            'action'        => self::AJAX_ACTION,
            'postId'        => $post_id,
            'productionUrl' => TransferClient::productionUrl(),
            'i18n'          => [
                'sendTitle'     => __('Send to Production', 'wphaven-connect'),
                'pullTitle'     => __('Update from Production', 'wphaven-connect'),
                'confirmSend'   => __('Send this content to production? Review the summary before confirming.', 'wphaven-connect'),
                'confirmPull'   => __('Overwrite this content with the production version?', 'wphaven-connect'),
                'working'       => __('Working…', 'wphaven-connect'),
                'conflict'      => __('Production changed more recently than this version. Overwrite anyway?', 'wphaven-connect'),
                'sent'          => __('Sent to production.', 'wphaven-connect'),
                'pulled'        => __('Updated from production — reloading to show the new content…', 'wphaven-connect'),
                'error'         => __('Transfer failed.', 'wphaven-connect'),
            ],
        ];
    }

    private function assetUrl(string $relative): string
    {
        return plugins_url('../../' . $relative, __FILE__);
    }

    private function assetVersion(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        return file_exists($path) ? (string) filemtime($path) : '1';
    }

    private function isEditablePostScreen(): bool
    {
        $screen = get_current_screen();

        return $screen && $screen->base === 'post' && $this->isTransferablePostType($screen->post_type);
    }

    private function isTransferablePostType(string $post_type): bool
    {
        if (in_array($post_type, ['attachment', 'revision', 'wp_block', 'wp_template', 'wp_navigation'], true)) {
            return false;
        }

        return (bool) apply_filters('wphaven_content_transfer_supported_post_type', post_type_exists($post_type), $post_type);
    }

    private function userCanTransfer(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
