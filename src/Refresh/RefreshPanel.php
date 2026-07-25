<?php

namespace WPHavenConnect\Refresh;

use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;

/**
 * Renders the "Refresh" tab: a one-click Database + Uploads transfer to/from a
 * chosen environment. It reuses the existing Database Transfer and Uploads Sync
 * flows back to back — it does NOT deploy code.
 */
class RefreshPanel
{
    public static function render(): void
    {
        $environments = Environments::selectableTargets();
        $has_secret   = ConnectionSecret::get() !== null;
        ?>
        <h2><?php echo esc_html__('Full Transfer', 'wphaven-connect'); ?></h2>

        <div class="notice notice-error inline" style="border-left-color:#d63638;padding:12px;max-width:760px;">
            <p style="margin:0 0 8px;">
                <strong><?php echo esc_html__('Danger:', 'wphaven-connect'); ?></strong>
                <?php echo esc_html__('This overwrites the ENTIRE database (every table, including users and settings) on the destination and syncs the uploads directory. It is a full content clone in the chosen direction.', 'wphaven-connect'); ?>
            </p>
            <p style="margin:0;">
                <strong><?php echo esc_html__('It does NOT deploy code.', 'wphaven-connect'); ?></strong>
                <?php echo esc_html__('Make sure the destination\'s plugins, themes and other code are already deployed and up to date with git first — a database that expects newer (or older) code than what is deployed can break the site.', 'wphaven-connect'); ?>
            </p>
        </div>

        <?php if (empty($environments) || ! $has_secret): ?>
            <div class="notice notice-warning inline">
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: settings tab URL */
                        __('Add at least one environment and an environment connection secret on the <a href="%s">Settings</a> tab first.', 'wphaven-connect'),
                        esc_url(admin_url('options-general.php?page=wphaven-connect&tab=settings'))
                    ));
                    ?>
                </p>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <p>
            <label for="wphaven-refresh-target"><strong><?php echo esc_html__('Environment', 'wphaven-connect'); ?></strong></label><br>
            <select id="wphaven-refresh-target">
                <?php foreach ($environments as $environment): ?>
                    <option value="<?php echo esc_attr($environment['label']); ?>"><?php echo esc_html($environment['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="wphaven-refresh-confirm"><strong><?php echo esc_html__('Confirmation', 'wphaven-connect'); ?></strong></label><br>
            <span class="description"><?php echo esc_html__('Type the phrase for the action you are taking, e.g. "I am pushing to production" or "I am pulling from staging".', 'wphaven-connect'); ?></span><br>
            <input type="text" id="wphaven-refresh-confirm" class="large-text code" autocomplete="off">
        </p>

        <p class="submit" style="display:flex;gap:8px;">
            <button type="button" class="button button-primary wphaven-refresh-action" data-direction="push"></button>
            <button type="button" class="button wphaven-refresh-action" data-direction="pull"></button>
        </p>

        <div class="wphaven-refresh-progress" style="display:none;max-width:760px;">
            <div style="background:#dcdcde;border-radius:3px;overflow:hidden;height:18px;">
                <div class="wphaven-refresh-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
            </div>
            <p class="wphaven-refresh-progress-label description" style="margin-top:6px;"></p>
        </div>

        <div class="wphaven-refresh-log" style="max-width:760px;margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:240px;overflow:auto;"></div>
        <?php
    }
}
