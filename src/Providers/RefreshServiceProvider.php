<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\DatabaseTransfer\TableRepository;
use WPHavenConnect\Utilities\ElevatedUsers;
use WPHavenConnect\Utilities\Environment;

/**
 * "Refresh" tab: a one-click Database + Uploads transfer. It has no backend of
 * its own — the JS drives the existing `wphaven_db_transfer` and
 * `wphaven_uploads_sync` ajax flows in sequence (all tables, then uploads). This
 * provider only enqueues that JS and hands it the reused action names, nonces,
 * table list and phrase templates.
 */
class RefreshServiceProvider
{
    public function register()
    {
        if (! is_admin()) {
            return;
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'refresh' || Environment::is_production() || ! $this->userCanTransfer()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-refresh',
            plugins_url('../../src/assets/js/refresh.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/refresh.js'),
            true
        );

        $tables = array_map(
            static fn ($table) => $table['base'],
            (new TableRepository())->listTransferableTables()
        );

        wp_localize_script('wphaven-refresh', 'wphavenRefresh', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'db'      => [
                'action' => DatabaseTransferServiceProvider::AJAX_ACTION,
                'nonce'  => wp_create_nonce(DatabaseTransferServiceProvider::NONCE_ACTION),
            ],
            'uploads' => [
                'action' => UploadsSyncServiceProvider::AJAX_ACTION,
                'nonce'  => wp_create_nonce(UploadsSyncServiceProvider::NONCE_ACTION),
            ],
            'tables'  => $tables,
            'i18n'    => [
                'pushPhrase' => __('I am pushing to %s', 'wphaven-connect'),
                'pullPhrase' => __('I am pulling from %s', 'wphaven-connect'),
                'pushTo'     => __('Push to %s', 'wphaven-connect'),
                'pullFrom'   => __('Pull from %s', 'wphaven-connect'),
                'dbPhase'    => __('Database: %1$s (%2$s/%3$s tables)…', 'wphaven-connect'),
                'uploadsPhase' => __('Uploads: %1$s/%2$s files…', 'wphaven-connect'),
                'tableFail'  => __('✗ %1$s — %2$s', 'wphaven-connect'),
                'warn'       => __('! %1$s — %2$s', 'wphaven-connect'),
                'allDone'    => __('Refresh complete.', 'wphaven-connect'),
                'error'      => __('Refresh failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function userCanTransfer(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
