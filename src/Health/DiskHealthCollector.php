<?php

namespace WPHavenConnect\Health;

/**
 * Reports filesystem usage for the volume the site lives on, so a near-full disk
 * is caught before writes/backups start failing silently. Most valuable on hosts
 * that don't already alert on storage (AWS, generic VPS, etc.) -- on RunCloud
 * this overlaps existing alerting, so a site can drop it via the
 * `wphaven_connect_health_collectors` filter.
 *
 * Stateless read: a single disk_free_space()/disk_total_space() pair. When the
 * host disables those functions or the path can't be measured, `available` is
 * false and the signal stays healthy rather than false-alarming.
 *
 * Note: percent_used is raw (total - free) / total; it can read a touch lower
 * than `df` "Use%", which excludes root-reserved space from its denominator.
 */
class DiskHealthCollector implements HealthCollector
{
    /** Percent-used at or above which the volume is considered unhealthy. */
    const DEFAULT_USAGE_THRESHOLD = 90;

    public function key(): string
    {
        return 'disk';
    }

    public function label(): string
    {
        return 'Disk usage';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $path      = $this->path();
        $threshold = (int) apply_filters('wphaven_connect_disk_usage_threshold', self::DEFAULT_USAGE_THRESHOLD);

        $total = $this->size('disk_total_space', $path);
        $free  = $this->size('disk_free_space', $path);

        if ($total === null || $free === null || $total <= 0) {
            return [
                'available'         => false,
                'path'              => $path,
                'total_bytes'       => null,
                'free_bytes'        => null,
                'used_bytes'        => null,
                'percent_used'      => null,
                'threshold_percent' => $threshold,
            ];
        }

        $used = max(0.0, $total - $free);

        return [
            'available'         => true,
            'path'              => $path,
            'total_bytes'       => $total,
            'free_bytes'        => $free,
            'used_bytes'        => $used,
            'percent_used'      => round(($used / $total) * 100, 1),
            'threshold_percent' => $threshold,
        ];
    }

    public function isHealthy(array $metrics): bool
    {
        // Can't measure -> don't false-alarm.
        if (empty($metrics['available'])) {
            return true;
        }

        return $metrics['percent_used'] < $metrics['threshold_percent'];
    }

    /**
     * The volume to measure -- defaults to the WordPress root. Filterable for
     * sites whose uploads live on a separate mount.
     */
    private function path(): string
    {
        $default = defined('ABSPATH') ? ABSPATH : '/';

        return (string) apply_filters('wphaven_connect_disk_path', $default);
    }

    /**
     * Call a disk_*_space function defensively (may be in disable_functions).
     *
     * @return float|null
     */
    private function size(string $fn, string $path)
    {
        if (!function_exists($fn)) {
            return null;
        }

        $value = @$fn($path);

        return $value === false ? null : (float) $value;
    }
}
