<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\Utilities\ElevatedUsers;
use WPHavenConnect\Utilities\Environment;

/**
 * Bulk content transfer: adds a target picker + Push/Pull buttons to the
 * Posts/Pages/CPT list tables so several items can be transferred at once. It
 * has no backend of its own — the JS loops the existing per-post
 * content-transfer ajax action (ContentTransferServiceProvider) over the
 * checked rows. Non-production only, elevated admins only.
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
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(ContentTransferServiceProvider::NONCE_ACTION),
            'action'  => ContentTransferServiceProvider::AJAX_ACTION,
            'i18n'    => [
                'pushTo'      => __('Push to %s', 'wphaven-connect'),
                'pullFrom'    => __('Pull from %s', 'wphaven-connect'),
                'none'        => __('Select one or more items first (tick the checkboxes).', 'wphaven-connect'),
                'confirmPush' => __('Push %1$s selected item(s) to "%2$s"?', 'wphaven-connect'),
                'confirmPull' => __('Overwrite %1$s selected item(s) with the version from "%2$s"?', 'wphaven-connect'),
                'working'     => __('Transferring %1$s of %2$s…', 'wphaven-connect'),
                'done'        => __('Done: %1$s transferred, %2$s skipped, %3$s failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function active(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options')
            && $elevated
            && ! Environment::is_production()
            && ! empty(Environments::selectableTargets());
    }

    private function isTransferablePostType(string $post_type): bool
    {
        if ($post_type === '' || in_array($post_type, ['attachment', 'revision', 'wp_block', 'wp_template', 'wp_navigation'], true)) {
            return false;
        }

        return (bool) apply_filters('wphaven_content_transfer_supported_post_type', post_type_exists($post_type), $post_type);
    }
}
