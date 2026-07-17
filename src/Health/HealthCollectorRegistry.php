<?php

namespace WPHavenConnect\Health;

/**
 * Single source of truth for the health collector set, shared by the REST/CLI
 * HealthServiceProvider and the Site Health integration so the JSON a monitor
 * polls and the admin Site Health view never drift apart.
 */
class HealthCollectorRegistry
{
    /**
     * @return HealthCollector[]
     */
    public static function all(): array
    {
        $collectors = [
            new CronHealthCollector(),
            new EmailHealthCollector(),
            new DiskHealthCollector(),
            new FatalHealthCollector(),
            new ScheduledPostsHealthCollector(),
            new SslHealthCollector(),
        ];

        /**
         * Filters the registered health collectors, letting add-ons contribute
         * additional signals to /health and the Site Health screen.
         *
         * @param HealthCollector[] $collectors
         */
        $collectors = apply_filters('wphaven_connect_health_collectors', $collectors);

        return array_values(array_filter($collectors, function ($collector) {
            return $collector instanceof HealthCollector;
        }));
    }
}
