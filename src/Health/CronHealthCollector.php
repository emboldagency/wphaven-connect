<?php

namespace WPHavenConnect\Health;

/**
 * Exposes a lightweight WP-Cron health signal so WPHaven (or any allowlisted
 * monitor) can tell when a site's scheduled events have stopped firing without
 * logging into each site.
 *
 * The symptom we watch for: "ready" cron events (scheduled time already passed)
 * piling up because nothing is processing them. On a healthy site the oldest
 * ready event is only seconds old; when cron is dead the lag grows without
 * bound. This is the exact failure RunCloud's `php wp-cron.php` cron produces on
 * Acorn/Sage sites (the CLI boot hijacks the console kernel, so events never
 * fire), and it is stack-agnostic, so it catches any cause of a stalled cron.
 *
 * A high lag is only a reliable FAILURE signal when `disable_wp_cron` is true:
 * the site then relies on a real system cron that should run frequently, so a
 * growing backlog means that cron is not working. With the default page-load
 * cron a low-traffic site can show lag simply because nobody has visited, which
 * is not a failure -- so `stale` is gated on `disable_wp_cron` to avoid false
 * positives, while the raw numbers are exposed for consumers that want their own
 * logic.
 */
class CronHealthCollector implements HealthCollector
{
    /**
     * Ready-event lag (seconds) beyond which a disable_wp_cron site is stale.
     * Our standard system cron runs every minute, so 15 minutes of backlog is
     * well past any healthy jitter. Filterable for unusual cadences.
     */
    const DEFAULT_STALE_THRESHOLD = 900;

    public function key(): string
    {
        return 'cron';
    }

    public function label(): string
    {
        return 'WP-Cron';
    }

    public function isHealthy(array $metrics): bool
    {
        return empty($metrics['stale']);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now = time();

        $crons = _get_cron_array();
        if (!is_array($crons)) {
            $crons = [];
        }

        $total_events  = 0;
        $ready_events  = 0;
        $oldest_ready  = null; // smallest timestamp that is already due
        $next_event_ts = null; // soonest timestamp still in the future

        foreach ($crons as $timestamp => $hooks) {
            $count         = is_array($hooks) ? count($hooks) : 0;
            $total_events += $count;

            if ($timestamp <= $now) {
                $ready_events += $count;
                if ($oldest_ready === null || $timestamp < $oldest_ready) {
                    $oldest_ready = $timestamp;
                }
            } elseif ($next_event_ts === null || $timestamp < $next_event_ts) {
                $next_event_ts = $timestamp;
            }
        }

        $oldest_ready_lag = $oldest_ready === null ? 0 : max(0, $now - $oldest_ready);
        $threshold        = (int) apply_filters('wphaven_connect_cron_stale_threshold', self::DEFAULT_STALE_THRESHOLD);
        $disable_wp_cron  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return [
            'disable_wp_cron'          => $disable_wp_cron,
            'total_events'             => $total_events,
            'ready_events'             => $ready_events,
            'oldest_ready_lag_seconds' => $oldest_ready_lag,
            'next_event_seconds'       => $next_event_ts === null ? null : max(0, $next_event_ts - $now),
            'stale'                    => $disable_wp_cron && $oldest_ready_lag > $threshold,
            'stale_threshold_seconds'  => $threshold,
            'checked_at'               => gmdate('c', $now),
        ];
    }
}
