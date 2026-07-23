<?php

namespace WPHavenConnect\DatabaseTransfer;

use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\TransferClient;
use WPHavenConnect\Providers\DatabaseTransferServiceProvider;

/**
 * Renders the "Database Transfer" tab: a blunt warning, the selectable table
 * list, the two direction buttons, the typed-confirmation input, and the
 * progress area the JS drives.
 */
class DatabaseTransferPanel
{
    public static function render(): void
    {
        $production_url = TransferClient::productionUrl();
        $has_secret     = ConnectionSecret::get() !== null;
        $tables         = (new TableRepository())->listTransferableTables();
        ?>
        <h2><?php echo esc_html__('Database Transfer', 'wphaven-connect'); ?></h2>

        <div class="notice notice-error inline" style="border-left-color:#d63638;padding:12px;">
            <p style="margin:0;">
                <strong><?php echo esc_html__('Danger:', 'wphaven-connect'); ?></strong>
                <?php echo esc_html__('This overwrites the selected tables on the destination entirely, then rewrites the source domain to the destination throughout. Selecting wp_options, wp_users or wp_usermeta will overwrite the destination\'s own configuration and accounts. Each table is imported into a temporary table and swapped in atomically; the old table is renamed aside only for the duration of the swap and dropped as soon as it succeeds (kept only if the transfer fails). There is no persistent backup, so take a RunCloud on-demand backup from WP Haven before pushing to production. The destination needs free disk space roughly equal to the largest table being transferred.', 'wphaven-connect'); ?>
            </p>
        </div>

        <?php if (! $production_url || ! $has_secret): ?>
            <div class="notice notice-warning inline">
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: settings tab URL */
                        __('Set a Production URL and an environment connection secret on the <a href="%s">Settings</a> tab first.', 'wphaven-connect'),
                        esc_url(admin_url('options-general.php?page=wphaven-connect&tab=settings'))
                    ));
                    ?>
                </p>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: production URL */
                __('Paired production site: %s', 'wphaven-connect'),
                $production_url
            ));
            ?>
        </p>

        <table class="widefat striped" style="max-width:760px;margin:12px 0;">
            <thead>
                <tr>
                    <td style="width:28px;"><input type="checkbox" class="wphaven-db-select-all"></td>
                    <th><?php echo esc_html__('Table', 'wphaven-connect'); ?></th>
                    <th style="width:120px;"><?php echo esc_html__('Rows (approx)', 'wphaven-connect'); ?></th>
                    <th style="width:120px;"><?php echo esc_html__('Size', 'wphaven-connect'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $table): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="wphaven-db-table"
                                value="<?php echo esc_attr($table['base']); ?>">
                        </td>
                        <td><code><?php echo esc_html($table['base']); ?></code></td>
                        <td><?php echo esc_html(number_format_i18n($table['rows'])); ?></td>
                        <td><?php echo esc_html(size_format($table['size'], 1) ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <label for="wphaven-db-confirm"><strong><?php echo esc_html__('Confirmation', 'wphaven-connect'); ?></strong></label><br>
            <span class="description">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: push phrase, 2: pull phrase */
                    __('To send, type: “%1$s”. To pull, type: “%2$s”.', 'wphaven-connect'),
                    DatabaseTransferServiceProvider::PUSH_PHRASE,
                    DatabaseTransferServiceProvider::PULL_PHRASE
                ));
                ?>
            </span><br>
            <input type="text" id="wphaven-db-confirm" class="large-text code" autocomplete="off"
                placeholder="<?php echo esc_attr(DatabaseTransferServiceProvider::PUSH_PHRASE); ?>">
        </p>

        <p class="submit" style="display:flex;gap:8px;">
            <button type="button" class="button button-primary wphaven-db-action" data-direction="push" disabled>
                <?php echo esc_html__('Send to Production', 'wphaven-connect'); ?>
            </button>
            <button type="button" class="button wphaven-db-action" data-direction="pull" disabled>
                <?php echo esc_html__('Pull from Production', 'wphaven-connect'); ?>
            </button>
        </p>

        <div class="wphaven-db-progress" style="display:none;max-width:760px;">
            <div style="background:#dcdcde;border-radius:3px;overflow:hidden;height:18px;">
                <div class="wphaven-db-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
            </div>
            <p class="wphaven-db-progress-label description" style="margin-top:6px;"></p>
        </div>

        <div class="wphaven-db-log" style="max-width:760px;margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
        <?php
    }
}
