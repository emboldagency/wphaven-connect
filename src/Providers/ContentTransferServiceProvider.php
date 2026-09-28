<?php

namespace WPHavenConnect\Providers;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Query;
use WPHavenConnect\ContentTransfer\ContentIdentity;
use WPHavenConnect\ContentTransfer\ContentImporter;
use WPHavenConnect\ContentTransfer\ContentSerializer;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\ContentTransfer\TransferAuth;
use WPHavenConnect\ContentTransfer\TransferPermissions;
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
 * environments that have transfer targets configured, for users who can edit the
 * content in question (see TransferPermissions).
 */
class ContentTransferServiceProvider
{
    const AJAX_ACTION = 'wphaven_content_transfer';

    const SCAN_AJAX_ACTION = 'wphaven_content_sync_scan';

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
        add_action('wp_ajax_' . self::SCAN_AJAX_ACTION, [$this, 'handleScanAjax']);

        // NOTE: no capability or environment gating here -- register() runs on
        // plugins_loaded:0, before post types (and therefore their capabilities)
        // are registered. Each callback below gates itself once the screen is known.
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
        $permission = [TransferAuth::class, 'permissionCheck'];

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

        register_rest_route('wphaven-connect/v1', '/content/match', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleMatch'],
            'permission_callback' => $permission,
        ]);

        register_rest_route('wphaven-connect/v1', '/content/list', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handleList'],
            'permission_callback' => $permission,
        ]);
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

        $envelope = (new ContentSerializer())->export($post_id, ! $request->get_param('preview'));
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
            'relink'             => (bool) $request->get_param('relink'),
        ]);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * REST: locate content on this site that is "clearly the same" as a
     * not-yet-linked post elsewhere (same type + slug, or same post id), and
     * return its export envelope. Used to bootstrap a link in either direction
     * when neither side has a content id yet -- the reverse of the adoption
     * `ContentImporter::resolveTarget()` already does on import. With
     * `allow_linked`, also matches a post already linked under another content
     * id, so a drifted pair can be repaired (the importer then asks to re-link).
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleMatch(WP_REST_Request $request)
    {
        $post_type = sanitize_key((string) $request->get_param('post_type'));
        $slug      = sanitize_title((string) $request->get_param('slug'));
        $candidate = (int) $request->get_param('candidate_post_id');

        if (! $this->isTransferablePostType($post_type)) {
            return new WP_Error('wphaven_invalid_post_type', __('Unsupported post type.', 'wphaven-connect'), ['status' => 400]);
        }

        $post_id = ContentIdentity::findAdoptable($post_type, $slug, $candidate);
        if ($post_id === null && $request->get_param('allow_linked')) {
            $post_id = ContentIdentity::findDrifted($post_type, $slug, $candidate);
        }
        if ($post_id === null) {
            return new WP_Error('wphaven_no_match', __('No matching content on this site.', 'wphaven-connect'), ['status' => 404]);
        }

        $envelope = (new ContentSerializer())->export($post_id, ! $request->get_param('preview'));
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        return new WP_REST_Response($envelope, 200);
    }

    /**
     * REST: list this site's posts of a given type (id, slug, title, status,
     * modified date, content id) so a peer can diff them against what it already
     * has and offer to pull the ones it's missing. Strictly read-only: unlinked
     * rows report a null content id and are addressed by `source_post_id`.
     * (Minting ids here used to seed link drift: every listed post got an id
     * the peer never saw, so a later push from the peer duplicated it.)
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handleList(WP_REST_Request $request)
    {
        $post_type = sanitize_key((string) $request->get_param('post_type'));
        if (! $this->isTransferablePostType($post_type)) {
            return new WP_Error('wphaven_invalid_post_type', __('Unsupported post type.', 'wphaven-connect'), ['status' => 400]);
        }

        $paged    = max(1, (int) $request->get_param('paged'));
        $per_page = min(200, max(1, (int) ($request->get_param('per_page') ?: 100)));

        $query = new WP_Query([
            'post_type'        => $post_type,
            'post_status'      => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page'   => $per_page,
            'paged'            => $paged,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => false,
            'suppress_filters' => false,
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = [
                'content_id'     => ContentIdentity::get((int) $post->ID),
                'source_post_id' => (int) $post->ID,
                'post_type'      => $post->post_type,
                'title'          => get_the_title($post),
                'slug'           => $post->post_name,
                'status'         => $post->post_status,
                'modified_gmt'   => $post->post_modified_gmt,
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'pages' => (int) $query->max_num_pages,
            'total' => (int) $query->found_posts,
        ], 200);
    }

    /**
     * Admin-AJAX entry point for the editor buttons. Drives a push (this site ->
     * production) or pull (production -> this site), optionally as a dry run. A
     * pull may also target content that doesn't exist locally yet -- no post_id,
     * a content_id from a sync scan instead -- in which case it's gated on the
     * post type's edit capability rather than a specific post. A third direction,
     * "link", writes the content id onto an existing local post without pulling
     * or changing anything about it (see doLinkOnly()).
     *
     * A scan row that has never been linked has no content id; it is addressed
     * by `source_post_id` (its post ID on the target) instead. `relink` confirms
     * re-keying a local post that is linked under a different content id.
     */
    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id        = (int) ($_POST['post_id'] ?? 0);
        $content_id     = sanitize_text_field((string) ($_POST['content_id'] ?? ''));
        $source_post_id = (int) ($_POST['source_post_id'] ?? 0);
        $post_type      = sanitize_key((string) ($_POST['post_type'] ?? ''));
        $direction      = sanitize_key($_POST['direction'] ?? '');
        $target         = Environments::cleanLabel($_POST['target'] ?? '');
        $preview        = ! empty($_POST['preview']);
        $args           = [
            'publish'            => ! empty($_POST['publish']),
            'overwrite_conflict' => ! empty($_POST['overwrite_conflict']),
            'relink'             => ! empty($_POST['relink']),
        ];

        $pulling_new = ! $post_id && $direction === 'pull' && ($content_id !== '' || $source_post_id > 0);

        if ($pulling_new) {
            if (! $this->isTransferablePostType($post_type) || ! TransferPermissions::canEditPostType($post_type)) {
                wp_send_json_error(['message' => __('You are not allowed to transfer this content.', 'wphaven-connect')], 403);
            }
        } else {
            $post = $post_id ? get_post($post_id) : null;

            if (! $post instanceof WP_Post || ! $this->isTransferablePostType($post->post_type)) {
                wp_send_json_error(['message' => __('Invalid post.', 'wphaven-connect')], 400);
            }

            if (! TransferPermissions::canEditPost($post_id)) {
                wp_send_json_error(['message' => __('You are not allowed to transfer this content.', 'wphaven-connect')], 403);
            }
        }

        if (Environments::urlFor($target) === null) {
            wp_send_json_error(['message' => __('Choose a destination environment (configure them in WP Haven Connect settings first).', 'wphaven-connect')], 400);
        }
        if (ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set an environment connection secret in WP Haven Connect settings first.', 'wphaven-connect')], 400);
        }

        if ($pulling_new) {
            $result = $this->doPullNew($content_id, $source_post_id, $target, $preview, $args);
        } elseif ($direction === 'pull') {
            $result = $this->doPull($post_id, $target, $preview, $args);
        } elseif ($direction === 'link') {
            $result = $this->doLinkOnly($post_id, $content_id, $source_post_id, $target, $args['relink']);
        } else {
            $result = $this->doPush($post_id, $target, $preview, $args);
        }

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                // Surface the remote's own code (e.g. a conflict or link drift
                // raised by the receiving site on push) so the UI can react.
                'code'    => TransferClient::remoteErrorCode($result),
                'data'    => $result->get_error_data(),
            ], 200);
        }

        wp_send_json_success($result);
    }

    /**
     * Admin-AJAX entry point for the list-screen "sync new" scan: pages through
     * the target environment's content of this post type and returns the rows
     * this site doesn't already have a linked copy of.
     */
    public function handleScanAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_type = sanitize_key((string) ($_POST['post_type'] ?? ''));
        $target    = Environments::cleanLabel($_POST['target'] ?? '');

        if (! $this->isTransferablePostType($post_type) || ! TransferPermissions::canEditPostType($post_type)) {
            wp_send_json_error(['message' => __('You are not allowed to transfer this content.', 'wphaven-connect')], 403);
        }
        if (Environments::urlFor($target) === null) {
            wp_send_json_error(['message' => __('Choose a source environment (configure it in WP Haven Connect settings first).', 'wphaven-connect')], 400);
        }
        if (ConnectionSecret::get() === null) {
            wp_send_json_error(['message' => __('Set an environment connection secret in WP Haven Connect settings first.', 'wphaven-connect')], 400);
        }

        $client    = TransferClient::forLabel($target);
        $cap       = (int) apply_filters('wphaven_content_sync_scan_cap', 2000);
        $new_items = [];
        $scanned   = 0;
        $paged     = 1;
        $pages     = 1;

        do {
            $page = $client->listContent($post_type, $paged, 200);
            if (is_wp_error($page)) {
                wp_send_json_error([
                    'message' => $page->get_error_message(),
                    'code'    => $page->get_error_code(),
                ], 200);
            }

            $items = (array) ($page['items'] ?? []);
            $pages = max(1, (int) ($page['pages'] ?? 1));

            foreach ($items as $item) {
                $scanned++;

                // Null for a row the target has never linked (it no longer
                // mints ids just for being listed).
                $content_id = (string) ($item['content_id'] ?? '');

                if ($content_id !== '') {
                    $local = ContentIdentity::findLocalPost($content_id);
                    if (is_wp_error($local) || $local !== null) {
                        continue; // Already linked locally, or ambiguous -- resolve on the post itself.
                    }
                }

                $item_type      = (string) ($item['post_type'] ?? $post_type);
                $slug           = (string) ($item['slug'] ?? '');
                $source_post_id = (int) ($item['source_post_id'] ?? 0);

                $adopt_id  = ContentIdentity::findAdoptable($item_type, $slug, $source_post_id);
                $relink_id = $adopt_id === null ? ContentIdentity::findDrifted($item_type, $slug, $source_post_id) : null;

                $new_items[] = [
                    'content_id'     => $content_id,
                    'source_post_id' => $source_post_id,
                    'title'          => $item['title'] ?? '',
                    'slug'           => $slug,
                    'status'         => $item['status'] ?? '',
                    'modified_gmt'   => $item['modified_gmt'] ?? '',
                    'adopt_id'       => $adopt_id,
                    // Same post, but linked here under a different content id:
                    // linking/pulling it re-keys the local post instead of
                    // creating a duplicate.
                    'relink_id'      => $relink_id,
                ];
            }

            $paged++;
        } while ($paged <= $pages && $scanned < $cap);

        wp_send_json_success([
            'items'     => $new_items,
            'scanned'   => $scanned,
            'truncated' => $paged <= $pages,
        ]);
    }

    /**
     * @param array{publish?: bool, overwrite_conflict?: bool, relink?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    private function doPush(int $post_id, string $target, bool $preview, array $args)
    {
        // A preview must not mint: if the user cancels, this side would hold a
        // content id the target never saw.
        $envelope = (new ContentSerializer())->export($post_id, ! $preview);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $client = TransferClient::forLabel($target);

        return $preview ? $client->previewOnRemote($envelope) : $client->push($envelope, $args);
    }

    /**
     * @param array{publish?: bool, overwrite_conflict?: bool, relink?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    private function doPull(int $post_id, string $target, bool $preview, array $args)
    {
        $content_id = ContentIdentity::get($post_id);
        $client     = TransferClient::forLabel($target);
        $post       = get_post($post_id);
        $post_type  = $post instanceof WP_Post ? $post->post_type : '';
        $slug       = $post instanceof WP_Post ? $post->post_name : '';

        if ($content_id !== null) {
            $envelope = $client->fetchExport($content_id, $preview);

            // Linked here, but the target doesn't know this content id: the
            // pair has drifted (each side minted its own). Fall back to finding
            // the same post there; the importer then asks to re-link.
            if (is_wp_error($envelope) && TransferClient::remoteErrorCode($envelope) === 'wphaven_export_not_found') {
                $envelope = $client->matchExport($post_type, $slug, $post_id, $preview, true);
            }
        } else {
            // Never linked locally -- before giving up, ask the target whether
            // it has something "clearly the same" (matching type + slug, or
            // matching post id) that this pull can adopt, same as a push would
            // on its receiving end. Covers content created directly on the
            // target (e.g. production) with no push ever having run, including
            // content the target has already linked elsewhere.
            $envelope = $client->matchExport($post_type, $slug, $post_id, $preview, true);
        }

        if (is_wp_error($envelope) && TransferClient::remoteErrorCode($envelope) === 'wphaven_no_match') {
            return new WP_Error(
                'wphaven_no_link',
                sprintf(
                    /* translators: %s: environment label */
                    __('No matching content was found on "%s" to link this post to.', 'wphaven-connect'),
                    $target
                )
            );
        }

        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $importer = new ContentImporter();

        return $preview ? $importer->preview($envelope) : $importer->import($envelope, $args);
    }

    /**
     * Pull content that doesn't exist locally at all yet, addressed by a
     * content id obtained from a "sync new" scan rather than a local post --
     * or, for a row the target has never linked, by its post ID there.
     *
     * @param array{publish?: bool, overwrite_conflict?: bool, relink?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    private function doPullNew(string $content_id, int $source_post_id, string $target, bool $preview, array $args)
    {
        $client   = TransferClient::forLabel($target);
        $envelope = $content_id !== ''
            ? $client->fetchExport($content_id, $preview)
            : $client->fetchExportByPostId($source_post_id, $preview);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $importer = new ContentImporter();

        return $preview ? $importer->preview($envelope) : $importer->import($envelope, $args);
    }

    /**
     * Write a content id onto a local post without importing anything -- for
     * reconciling environments that already carry matching content built
     * outside of a transfer (a database clone, a separate migration), so
     * push/pull can address it going forward without ever overwriting it.
     *
     * When the target row has no content id yet, one is minted there by
     * fetching its export (a real, non-preview export mints), then copied here.
     * With $relink, a local post already linked under a different id is
     * re-keyed -- the fix for a drifted pair.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function doLinkOnly(int $post_id, string $content_id, int $source_post_id, string $target, bool $relink)
    {
        if ($content_id === '' && $source_post_id > 0) {
            $envelope = TransferClient::forLabel($target)->fetchExportByPostId($source_post_id);
            if (is_wp_error($envelope)) {
                return $envelope;
            }
            if (($envelope['post']['post_type'] ?? '') !== get_post_type($post_id)) {
                return new WP_Error('wphaven_link_type_mismatch', __('The remote content is a different post type-- refusing to link.', 'wphaven-connect'), ['status' => 409]);
            }
            $content_id = (string) ($envelope['content_id'] ?? '');
        }

        if ($content_id === '') {
            return new WP_Error('wphaven_link_missing_content_id', __('No content id provided.', 'wphaven-connect'), ['status' => 400]);
        }

        $current = ContentIdentity::get($post_id);
        if ($current === $content_id) {
            return ['post_id' => $post_id, 'linked' => true];
        }

        if ($current !== null && ! $relink) {
            return new WP_Error(
                'wphaven_already_linked',
                __('This post is already linked to something else -- resolve that link before linking it here.', 'wphaven-connect'),
                ['status' => 409]
            );
        }

        // Never let two local posts share an id.
        $holder = ContentIdentity::findLocalPost($content_id);
        if (is_wp_error($holder)) {
            return $holder;
        }
        if ($holder !== null) {
            return new WP_Error(
                'wphaven_content_id_taken',
                sprintf(
                    /* translators: %d: local post id */
                    __('That content is already linked to local post #%d.', 'wphaven-connect'),
                    $holder
                ),
                ['status' => 409]
            );
        }

        ContentIdentity::assign($post_id, $content_id);

        return ['post_id' => $post_id, 'linked' => true, 'relinked_from' => $current];
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
        if (! in_array($hook, ['post.php', 'post-new.php'], true) || ! $this->isEditablePostScreen()) {
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
        if (! $post instanceof WP_Post || ! $this->isEditablePostScreen()) {
            return;
        }

        $environments = Environments::selectableTargets();
        if (empty($environments)) {
            return;
        }

        echo '<div class="wphaven-content-transfer misc-pub-section" style="padding:8px 0;">';
        echo '<select class="wphaven-content-target" style="width:100%;margin-bottom:6px;">';
        foreach ($environments as $environment) {
            echo '<option value="' . esc_attr($environment['label']) . '">' . esc_html($environment['label']) . '</option>';
        }
        echo '</select>';
        echo '<button type="button" class="button wphaven-send-to-production" style="display:block;width:100%;text-align:center;margin-bottom:6px;">' . esc_html__('Push', 'wphaven-connect') . '</button>';
        echo '<button type="button" class="button wphaven-update-from-production" style="display:block;width:100%;text-align:center;">' . esc_html__('Pull', 'wphaven-connect') . '</button>';
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
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce(self::NONCE_ACTION),
            'action'          => self::AJAX_ACTION,
            'postId'          => $post_id,
            'environments'    => Environments::selectableTargetLabels(),
            'productionLabel' => Environments::PRODUCTION_LABEL,
            'i18n'            => [
                'sendTitle'     => __('Push', 'wphaven-connect'),
                'pullTitle'     => __('Pull', 'wphaven-connect'),
                'targetLabel'   => __('Environment', 'wphaven-connect'),
                'confirmSend'   => __('Send this content to "%s"? Review the summary before confirming.', 'wphaven-connect'),
                'confirmPull'   => __('Overwrite this content with the version from "%s"?', 'wphaven-connect'),
                'working'       => __('Working…', 'wphaven-connect'),
                'conflict'      => __('The destination changed more recently than this version. Overwrite anyway?', 'wphaven-connect'),
                'sent'          => __('Sent.', 'wphaven-connect'),
                'pulled'        => __('Updated — reloading to show the new content…', 'wphaven-connect'),
                'error'         => __('Transfer failed.', 'wphaven-connect'),
                'noEnvironments' => $this->noTargetsMessage(),
            ],
        ];
    }

    /**
     * Why there is nothing to transfer to. "None configured" and "every one of
     * them is this site" look identical in the picker but need different fixes,
     * so say which it is.
     */
    private function noTargetsMessage(): string
    {
        $self = Environments::selfLabels();

        if (empty($self)) {
            return __('No other environments are configured for content transfer.', 'wphaven-connect');
        }

        return sprintf(
            /* translators: %s: comma-separated environment labels, e.g. "production, maintenance". */
            __('Every configured environment (%s) points at this site\'s own URL, so there is nothing to transfer to. Fix the URLs in WP Haven Connect settings.', 'wphaven-connect'),
            implode(', ', $self)
        );
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
        if (! $screen || $screen->base !== 'post') {
            return false;
        }

        return TransferPermissions::uiAvailable()
            && TransferPermissions::canEditPostType((string) $screen->post_type)
            && $this->isTransferablePostType((string) $screen->post_type);
    }

    /**
     * Whether this *kind* of content can be transferred at all. Purely about the
     * post type -- permissions live in TransferPermissions.
     */
    private function isTransferablePostType(string $post_type): bool
    {
        if (in_array($post_type, ['attachment', 'revision', 'wp_block', 'wp_template', 'wp_navigation'], true)) {
            return false;
        }

        return (bool) apply_filters('wphaven_content_transfer_supported_post_type', post_type_exists($post_type), $post_type);
    }
}
