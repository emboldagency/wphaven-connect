<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\ContentTransfer\TransferPermissions;

/**
 * Bulk content transfer: adds a target picker + Push/Pull buttons to the
 * Posts/Pages/CPT list tables so several items can be transferred at once. It
 * has no backend of its own — the JS loops the existing per-post
 * content-transfer ajax action (ContentTransferServiceProvider) over the
 * checked rows. Non-production only, and only for users who can edit that post
 * type (each row is still permission-checked server side).
 */
class BulkContentTransferProvider
{
    public function register()
    {
        if (! is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('restrict_manage_posts', [$this, 'renderControls'], 20, 2);
    }

    /**
     * @param string $post_type
     * @param string $which
     */
    public function renderControls($post_type, $which = 'top'): void
    {
        if ($which !== 'top' || ! $this->active() || ! $this->isTransferablePostType((string) $post_type)) {
            return;
        }

        $targets = Environments::selectableTargets();
        ?>
        <span class="wphaven-bulk-content" style="display:inline-flex;gap:6px;align-items:center;margin:0 0 0 6px;vertical-align:middle;">
            <select class="wphaven-bulk-target">
                <?php foreach ($targets as $environment): ?>
                    <option value="<?php echo esc_attr($environment['label']); ?>"><?php echo esc_html($environment['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button wphaven-bulk-push"></button>
            <button type="button" class="button wphaven-bulk-pull"></button>
            <label style="white-space:nowrap;"><input type="checkbox" class="wphaven-bulk-overwrite"> <?php echo esc_html__('Overwrite if changed', 'wphaven-connect'); ?></label>
            <button type="button" class="button wphaven-sync-new"></button>
            <span class="wphaven-bulk-status description"></span>
        </span>
        <?php
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'edit.php' || ! $this->active()) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || $screen->base !== 'edit' || ! $this->isTransferablePostType((string) $screen->post_type)) {
            return;
        }

        wp_enqueue_script(
            'wphaven-bulk-content',
            plugins_url('../../src/assets/js/bulk-content.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/bulk-content.js'),
            true
        );

        wp_localize_script('wphaven-bulk-content', 'wphavenBulkContent', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(ContentTransferServiceProvider::NONCE_ACTION),
            'action'    => ContentTransferServiceProvider::AJAX_ACTION,
            'scanAction' => ContentTransferServiceProvider::SCAN_AJAX_ACTION,
            'postType'  => (string) $screen->post_type,
            'i18n'      => [
                'pushTo'        => __('Push to %s', 'wphaven-connect'),
                'pullFrom'      => __('Pull from %s', 'wphaven-connect'),
                'syncFrom'      => __('Sync new from %s', 'wphaven-connect'),
                'none'          => __('Select one or more items first (tick the checkboxes).', 'wphaven-connect'),
                'confirmPush'   => __('Push %1$s selected item(s) to "%2$s"?', 'wphaven-connect'),
                'confirmPull'   => __('Overwrite %1$s selected item(s) with the version from "%2$s"?', 'wphaven-connect'),
                'working'       => __('Transferring %1$s of %2$s…', 'wphaven-connect'),
                'done'          => __('Done: %1$s transferred, %2$s skipped, %3$s failed.', 'wphaven-connect'),
                'scanning'      => __('Checking "%s" for content this site doesn\'t have yet…', 'wphaven-connect'),
                'noneNew'       => __('Nothing new on "%s" — every item there is already linked here.', 'wphaven-connect'),
                'foundNew'      => __('Found %1$s new item(s) on "%2$s".', 'wphaven-connect'),
                'truncated'     => __(' (stopped early after scanning %s — narrow it down and run again for the rest.)', 'wphaven-connect'),
                'modalTitle'    => __('New on %s', 'wphaven-connect'),
                'selectAll'     => __('Select all', 'wphaven-connect'),
                'willAdopt'     => __('will overwrite existing #%s to match', 'wphaven-connect'),
                'willCreate'    => __('will create new', 'wphaven-connect'),
                'checking'      => __('checking for conflicts…', 'wphaven-connect'),
                'willUpdate'    => __('%s field(s) will change', 'wphaven-connect'),
                'conflictWarn'  => __('⚠ edited locally more recently than this version — unchecked; re-check to overwrite anyway', 'wphaven-connect'),
                'previewFailed' => __('couldn\'t check this one: %s', 'wphaven-connect'),
                'pullSelected'  => __('Pull %s selected', 'wphaven-connect'),
                'linkSelected'  => __('Link %s matched (no content changes)', 'wphaven-connect'),
                'cancel'        => __('Cancel', 'wphaven-connect'),
                'pullingNew'    => __('Pulling %1$s of %2$s…', 'wphaven-connect'),
                'pullingRow'    => __('pulling…', 'wphaven-connect'),
                'pulledRow'     => __('done', 'wphaven-connect'),
                'failedRow'     => __('failed: %s', 'wphaven-connect'),
                'doneNew'       => __('Done: %1$s pulled, %2$s failed.', 'wphaven-connect'),
                'linkingRow'    => __('Linking %1$s of %2$s…', 'wphaven-connect'),
                'linkingRowStatus' => __('linking…', 'wphaven-connect'),
                'linkedRow'     => __('linked (content untouched)', 'wphaven-connect'),
                'doneLink'      => __('Done: %1$s linked, %2$s failed.', 'wphaven-connect'),
                'failedList'    => __('Failed: %s', 'wphaven-connect'),
                'error'         => __('Something went wrong.', 'wphaven-connect'),
            ],
        ]);
    }

    private function active(): bool
    {
        return TransferPermissions::uiAvailable() && TransferPermissions::hasTargets();
    }

    private function isTransferablePostType(string $post_type): bool
    {
        if ($post_type === '' || in_array($post_type, ['attachment', 'revision', 'wp_block', 'wp_template', 'wp_navigation'], true)) {
            return false;
        }

        if (! TransferPermissions::canEditPostType($post_type)) {
            return false;
        }

        return (bool) apply_filters('wphaven_content_transfer_supported_post_type', post_type_exists($post_type), $post_type);
    }
}
