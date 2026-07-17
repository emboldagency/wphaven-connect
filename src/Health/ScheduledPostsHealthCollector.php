<?php

namespace WPHavenConnect\Health;

/**
 * The client-facing face of a dead cron: scheduled posts stuck on "Missed
 * schedule". WordPress publishes future-dated posts via the `publish_future_post`
 * WP-Cron event; when cron stops firing, those posts sit at post_status 'future'
 * with a past date and never go live -- which is what clients actually report
 * ("my post didn't publish") long before anyone looks at cron internals.
 *
 * Complements the raw cron signal ([[CronHealthCollector]]) with the concrete,
 * business-visible symptom. A short grace window avoids flagging a post that is
 * only seconds past due and about to publish on the next cron tick.
 *
 * Stateless read: one COUNT/MIN query.
 */
class ScheduledPostsHealthCollector implements HealthCollector
{
    /** Grace (seconds) a post may be past-due before it counts as truly missed. */
    const DEFAULT_GRACE = 900;

    public function key(): string
    {
        return 'scheduled_posts';
    }

    public function label(): string
    {
        return 'Scheduled posts';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        global $wpdb;

        $grace = (int) apply_filters('wphaven_connect_missed_schedule_threshold', self::DEFAULT_GRACE);

        $missed_count = 0;
        $oldest_gmt   = null;

        if (isset($wpdb) && is_object($wpdb)) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) AS cnt, MIN(post_date_gmt) AS oldest
                     FROM {$wpdb->posts}
                     WHERE post_status = 'future' AND post_date_gmt < %s",
                    gmdate('Y-m-d H:i:s')
                )
            );

            if ($row) {
                $missed_count = (int) $row->cnt;
                $oldest_gmt   = $row->oldest;
            }
        }

        $oldest_ts  = ($oldest_gmt && $oldest_gmt !== '0000-00-00 00:00:00') ? strtotime($oldest_gmt . ' UTC') : null;
        $oldest_age = $oldest_ts === null ? null : max(0, time() - $oldest_ts);

        return [
            'missed_count'             => $missed_count,
            'oldest_missed_at'         => $oldest_ts === null ? null : gmdate('c', $oldest_ts),
            'oldest_missed_age_seconds' => $oldest_age,
            'grace_seconds'            => $grace,
        ];
    }

    public function isHealthy(array $metrics): bool
    {
        $age = $metrics['oldest_missed_age_seconds'];

        // No past-due posts, or the oldest is still within the grace window.
        return $age === null || $age <= (int) $metrics['grace_seconds'];
    }
}
