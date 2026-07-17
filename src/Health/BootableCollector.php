<?php

namespace WPHavenConnect\Health;

/**
 * Opt-in seam for stateful collectors. A collector implementing this interface
 * has boot() called once when the plugin loads, letting it register long-lived
 * hooks (e.g. wp_mail_failed) so it can record state that later polls read back.
 *
 * Stateless collectors (cron, disk, ssl, ...) skip this entirely.
 */
interface BootableCollector
{
    public function boot(): void;
}
