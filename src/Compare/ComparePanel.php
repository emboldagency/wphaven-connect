<?php

namespace WPHavenConnect\Compare;

use WPHavenConnect\ContentTransfer\ConnectionSecret;
use WPHavenConnect\ContentTransfer\Environments;

/**
 * Renders the "Compare" tab: pick an environment and see how this site diverges
 * from it — table row counts, uploads totals, and per-post-type content
 * divergence. Read-only; the JS fills in the results.
 */
class ComparePanel
{
    public static function render(): void
    {
        $environments = Environments::selectableTargets();
        $has_secret   = ConnectionSecret::get() !== null;
        ?>
        <h2><?php echo esc_html__('Compare', 'wphaven-connect'); ?></h2>

        <p class="description" style="max-width:760px;">
            <?php echo esc_html__('See how this environment differs from another before you transfer anything — table row counts, uploads, and how many posts, pages and products have diverged. Read-only: nothing is changed.', 'wphaven-connect'); ?>
        </p>

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

        <p class="submit" style="display:flex;gap:8px;align-items:center;">
            <label for="wphaven-compare-target"><strong><?php echo esc_html__('Compare with', 'wphaven-connect'); ?></strong></label>
            <select id="wphaven-compare-target">
                <?php foreach ($environments as $environment): ?>
                    <option value="<?php echo esc_attr($environment['label']); ?>"><?php echo esc_html($environment['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button button-primary wphaven-compare-run"><?php echo esc_html__('Compare', 'wphaven-connect'); ?></button>
            <span class="spinner wphaven-compare-spinner" style="float:none;margin:0;"></span>
        </p>

        <div class="wphaven-compare-status description"></div>
        <div class="wphaven-compare-results" style="max-width:900px;"></div>
        <?php
    }
}
