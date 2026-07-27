<?php

namespace WPHavenConnect\SearchReplace;

use WPHavenConnect\DatabaseTransfer\TableRepository;

/**
 * Renders the "Search & Replace" tab: arbitrary, serialized-data-safe find and
 * replace across selected tables, with a dry run that reports how many matches
 * would be replaced before you commit.
 */
class SearchReplacePanel
{
    public static function render(): void
    {
        $tables = (new TableRepository())->listTransferableTables();
        ?>
        <h2><?php echo esc_html__('Search & Replace', 'wphaven-connect'); ?></h2>

        <p class="description" style="max-width:760px;">
            <?php echo esc_html__('Find and replace text across the selected database tables. Safe for PHP-serialized data (ACF, widgets, options). Run a dry run first to see how many matches will be replaced. Replacing writes to this site\'s database directly — there is no undo, so take a backup first.', 'wphaven-connect'); ?>
        </p>

        <table class="form-table" role="presentation" style="max-width:760px;">
            <tr>
                <th scope="row"><label for="wphaven-sr-search"><?php echo esc_html__('Search for', 'wphaven-connect'); ?></label></th>
                <td><input type="text" id="wphaven-sr-search" class="large-text code" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="wphaven-sr-replace"><?php echo esc_html__('Replace with', 'wphaven-connect'); ?></label></th>
                <td><input type="text" id="wphaven-sr-replace" class="large-text code" autocomplete="off"></td>
            </tr>
        </table>

        <table class="widefat striped" style="max-width:760px;margin:12px 0;">
            <thead>
                <tr>
                    <td style="width:28px;"><input type="checkbox" class="wphaven-sr-select-all"></td>
                    <th style="padding-left:12px;"><?php echo esc_html__('Table', 'wphaven-connect'); ?></th>
                    <th style="width:120px;"><?php echo esc_html__('Rows (approx)', 'wphaven-connect'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $table): ?>
                    <tr>
                        <td><input type="checkbox" class="wphaven-sr-table" value="<?php echo esc_attr($table['base']); ?>"></td>
                        <td><code><?php echo esc_html($table['base']); ?></code></td>
                        <td><?php echo esc_html(number_format_i18n($table['rows'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="submit" style="display:flex;gap:8px;">
            <button type="button" class="button wphaven-sr-action" data-mode="dry"><?php echo esc_html__('Dry Run', 'wphaven-connect'); ?></button>
            <button type="button" class="button button-primary wphaven-sr-action" data-mode="live"><?php echo esc_html__('Replace', 'wphaven-connect'); ?></button>
        </p>

        <div class="wphaven-sr-progress" style="display:none;max-width:760px;">
            <div style="background:#dcdcde;border-radius:3px;overflow:hidden;height:18px;">
                <div class="wphaven-sr-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
            </div>
            <p class="wphaven-sr-progress-label description" style="margin-top:6px;"></p>
        </div>

        <div class="wphaven-sr-log" style="max-width:760px;margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:240px;overflow:auto;"></div>
        <?php
    }
}
