<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\DatabaseTransfer\SearchReplace;
use WPHavenConnect\DatabaseTransfer\TableRepository;
use WPHavenConnect\Utilities\ElevatedUsers;

/**
 * "Search & Replace" tab: a general, serialized-data-safe find/replace across
 * selected tables, with a dry run. Reuses the SearchReplace engine (literal
 * mode) and TableRepository (table validation + primary key) built for the
 * transfer features. Operates on this site's database only — usable on any
 * environment (unlike the transfer tabs), gated to elevated admins.
 */
class SearchReplaceServiceProvider
{
    const AJAX_ACTION = 'wphaven_search_replace';

    const NONCE_ACTION = 'wphaven_search_replace';

    public function register()
    {
        if (! is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (! $this->userCanRun()) {
            wp_send_json_error(['message' => __('You are not allowed to run search & replace.', 'wphaven-connect')], 403);
        }

        // Preserve the exact search/replace strings (only strip WP's added slashes).
        $search  = isset($_POST['search']) ? (string) wp_unslash($_POST['search']) : '';
        $replace = isset($_POST['replace']) ? (string) wp_unslash($_POST['replace']) : '';
        $base    = sanitize_text_field(wp_unslash($_POST['base'] ?? ''));
        $dry_run = ! empty($_POST['dry']);

        if ($search === '') {
            wp_send_json_error(['message' => __('Enter a search value.', 'wphaven-connect')], 400);
        }

        $repo = new TableRepository();
        $full = $repo->resolveFull($base);
        if (is_wp_error($full)) {
            wp_send_json_error(['message' => $full->get_error_message()], 400);
        }

        $stats = SearchReplace::literal($search, $replace)
            ->replaceInTable($repo->wpdb(), $full, $repo->primaryKey($full), $dry_run);

        wp_send_json_success([
            'base'         => $base,
            'rows'         => $stats['rows'],
            'replacements' => $stats['replacements'],
        ]);
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'search-replace' || ! $this->userCanRun()) {
            return;
        }

        wp_enqueue_script(
            'wphaven-search-replace',
            plugins_url('../../src/assets/js/search-replace.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/search-replace.js'),
            true
        );

        wp_localize_script('wphaven-search-replace', 'wphavenSearchReplace', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'action'  => self::AJAX_ACTION,
            'i18n'    => [
                'noTables'    => __('Select at least one table.', 'wphaven-connect'),
                'noSearch'    => __('Enter a search value.', 'wphaven-connect'),
                'confirm'     => __('Replace "%1$s" with "%2$s" across the selected tables? This writes to the database and cannot be undone.', 'wphaven-connect'),
                'working'     => __('%1$s (%2$s/%3$s tables)…', 'wphaven-connect'),
                'tableResult' => __('%1$s: %2$s replacement(s) in %3$s row(s)', 'wphaven-connect'),
                'tableFail'   => __('✗ %1$s — %2$s', 'wphaven-connect'),
                'dryDone'     => __('Dry run: %1$s replacement(s) across %2$s row(s). Nothing was written.', 'wphaven-connect'),
                'liveDone'    => __('Done: %1$s replacement(s) across %2$s row(s).', 'wphaven-connect'),
                'error'       => __('Search & replace failed.', 'wphaven-connect'),
            ],
        ]);
    }

    private function userCanRun(): bool
    {
        $elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();

        return current_user_can('manage_options') && $elevated;
    }
}
