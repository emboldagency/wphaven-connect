<?php

namespace WPHavenConnect\UploadsSync;

use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;

/**
 * Renders the "Uploads" tab: direction buttons, an overwrite-changed toggle, and
 * the progress area the JS drives. Additive by design, so no typed confirmation
 * phrase — a native confirm dialog is enough.
 */
class UploadsSyncPanel
{
    public static function render(): void
    {
        $environments = Environments::all();
        $has_secret   = ConnectionSecret::get() !== null;
        ?>
        <h2><?php echo esc_html__('Uploads Sync', 'wphaven-connect'); ?></h2>

        <div class="notice notice-info inline" style="padding:12px;">
            <p style="margin:0;">
                <?php echo esc_html__('Copies files in wp-content/uploads between this environment and the chosen one. This is additive — files missing on the destination are copied over and nothing is ever deleted. Pair it with a Database Transfer so migrated content finds its media.', 'wphaven-connect'); ?>
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
            <label for="wphaven-uploads-target"><strong><?php echo esc_html__('Environment', 'wphaven-connect'); ?></strong></label><br>
            <select id="wphaven-uploads-target">
                <?php foreach ($environments as $environment): ?>
                    <option value="<?php echo esc_attr($environment['label']); ?>"><?php echo esc_html($environment['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label>
                <input type="checkbox" id="wphaven-uploads-overwrite">
                <?php echo esc_html__('Also overwrite files that differ (by default only missing files are copied)', 'wphaven-connect'); ?>
            </label>
        </p>

        <p class="submit" style="display:flex;gap:8px;">
            <button type="button" class="button button-primary wphaven-uploads-action" data-direction="push"></button>
            <button type="button" class="button wphaven-uploads-action" data-direction="pull"></button>
        </p>

        <div class="wphaven-uploads-progress" style="display:none;max-width:760px;">
            <div style="background:#dcdcde;border-radius:3px;overflow:hidden;height:18px;">
                <div class="wphaven-uploads-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
            </div>
            <p class="wphaven-uploads-progress-label description" style="margin-top:6px;"></p>
        </div>

        <div class="wphaven-uploads-log" style="max-width:760px;margin-top:8px;font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:240px;overflow:auto;"></div>
        <?php
    }
}
