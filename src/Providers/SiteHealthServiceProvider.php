<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Health\HealthCollector;
use WPHavenConnect\Health\HealthCollectorRegistry;

/**
 * Surfaces the same health signals as the /health endpoint on the native
 * WordPress Site Health screen (Tools -> Site Health): one test per collector on
 * the Status tab (so a red signal rolls into the "critical issues" count), plus
 * the raw metrics under a section on the Info tab.
 *
 * Reuses the exact collector set behind /health via HealthCollectorRegistry, so
 * the admin view and the JSON a monitor polls never drift apart. Test
 * descriptions mirror core's style: a plain-English intro plus a Passed/Warning
 * checklist, rather than a raw metrics dump (that lives on the Info tab).
 */
class SiteHealthServiceProvider
{
    public function register()
    {
        add_filter('site_status_tests', [$this, 'registerTests']);
        add_filter('debug_information', [$this, 'addDebugInformation']);
    }

    /**
     * @param array $tests
     * @return array
     */
    public function registerTests($tests)
    {
        foreach (HealthCollectorRegistry::all() as $collector) {
            $tests['direct']['wphaven_connect_health_' . $collector->key()] = [
                'label' => $this->label($collector),
                'test'  => function () use ($collector) {
                    return $this->buildTest($collector);
                },
            ];
        }

        return $tests;
    }

    /**
     * @param array $info
     * @return array
     */
    public function addDebugInformation($info)
    {
        $fields = [];

        foreach (HealthCollectorRegistry::all() as $collector) {
            $label = $this->label($collector);
            // One atomic key/value row per metric, matching core's Info sections.
            foreach ($collector->collect() as $key => $value) {
                $fields[$collector->key() . '_' . $key] = [
                    'label' => $label . ': ' . $this->humanizeKey($key),
                    'value' => $this->scalar($value),
                ];
            }
        }

        $info['wphaven-connect-health'] = [
            'label'  => __('WP Haven', 'wphaven-connect'),
            'fields' => $fields,
        ];

        return $info;
    }

    private function humanizeKey(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private function buildTest(HealthCollector $collector): array
    {
        $metrics = $collector->collect();
        $healthy = $collector->isHealthy($metrics);
        $label   = $this->label($collector);

        return [
            'label'       => $healthy
                ? sprintf(__('%s is healthy', 'wphaven-connect'), $label)
                : sprintf(__('%s needs attention', 'wphaven-connect'), $label),
            'status'      => $healthy ? 'good' : 'critical',
            'badge'       => [
                'label' => __('WP Haven', 'wphaven-connect'),
                'color' => 'blue',
            ],
            'description' => $this->describe($collector, $metrics, $healthy),
            'test'        => 'wphaven_connect_health_' . $collector->key(),
        ];
    }

    /**
     * @param array<string, mixed> $m
     */
    private function describe(HealthCollector $collector, array $m, bool $healthy): string
    {
        switch ($collector->key()) {
            case 'cron':
                return $this->describeCron($m);
            case 'email':
                return $this->describeEmail($m, $healthy);
            case 'disk':
                return $this->describeDisk($m, $healthy);
            case 'fatals':
                return $this->describeFatals($m);
            case 'scheduled_posts':
                return $this->describeScheduled($m, $healthy);
            case 'ssl':
                return $this->describeSsl($m, $healthy);
            default:
                return $this->describeGeneric($m);
        }
    }

    private function describeCron(array $m): string
    {
        if (!empty($m['stale'])) {
            $items = [$this->item('warning', sprintf(
                'Cron looks stalled — the oldest due task has been waiting %s, but a system cron is expected to be running it.',
                $this->humanSeconds($m['oldest_ready_lag_seconds'])
            ))];
        } else {
            $items = [$this->item('good', sprintf(
                'Due tasks are being processed (oldest ready event %s old).',
                $this->humanSeconds($m['oldest_ready_lag_seconds'])
            ))];
        }

        $items[] = $this->item('info', sprintf(
            '%d scheduled events; %s.',
            $m['total_events'],
            $m['next_event_seconds'] === null ? 'none upcoming' : 'next runs in ' . $this->humanSeconds($m['next_event_seconds'])
        ));

        return $this->render(
            'WordPress runs scheduled tasks — publishing posts, sending email, clearing caches — through WP-Cron. This checks that due tasks are actually being processed.',
            $items
        );
    }

    private function describeEmail(array $m, bool $healthy): string
    {
        if (!empty($m['blocked'])) {
            $items = [$this->item('info', 'Mail is intentionally disabled on this environment.')];
        } elseif ($m['last_failure_at'] === null) {
            $items = [$this->item('good', 'No outbound mail failures have been recorded.')];
        } else {
            $items = [$this->item($healthy ? 'good' : 'warning', sprintf(
                'Last send failure %s ago: %s',
                $this->humanSeconds($m['last_failure_age_seconds']),
                $m['last_error'] ? $m['last_error'] : 'unknown error'
            ))];
            if ($m['last_success_at'] !== null) {
                $items[] = $this->item('info', sprintf('Last successful send %s ago.', $this->humanSeconds($m['last_success_age_seconds'])));
            }
        }

        return $this->render(
            'Transactional email — order receipts, password resets, form notifications — is sent through wp_mail(). This watches for send failures reported by your mail setup.',
            $items
        );
    }

    private function describeDisk(array $m, bool $healthy): string
    {
        if (empty($m['available'])) {
            $items = [$this->item('info', 'Disk usage could not be read on this host.')];
        } else {
            $items = [$this->item($healthy ? 'good' : 'warning', sprintf(
                '%s%% of %s used — %s free (warns at %s%%).',
                $m['percent_used'],
                $this->formatBytes($m['total_bytes']),
                $this->formatBytes($m['free_bytes']),
                $m['threshold_percent']
            ))];
        }

        return $this->render(
            'Free space on the volume this site runs from. A full disk makes uploads, backups and updates fail — often silently.',
            $items
        );
    }

    private function describeFatals(array $m): string
    {
        $paused = array_merge((array) $m['paused_plugins'], (array) $m['paused_themes']);

        if ((int) $m['paused_count'] === 0) {
            $items = [$this->item('good', 'No plugins or themes are paused.')];
        } else {
            $items = [$this->item('warning', sprintf(
                '%d disabled after a fatal error: %s',
                $m['paused_count'],
                implode(', ', $paused)
            ))];
        }

        return $this->render(
            'When a plugin or theme triggers a fatal error, WordPress disables just that extension to keep the site up — which can hide breakage until someone checks.',
            $items
        );
    }

    private function describeScheduled(array $m, bool $healthy): string
    {
        if ((int) $m['missed_count'] === 0) {
            $items = [$this->item('good', 'No posts are past their scheduled publish time.')];
        } elseif ($healthy) {
            $items = [$this->item('good', sprintf('%d scheduled post(s) just came due and should publish shortly.', $m['missed_count']))];
        } else {
            $items = [$this->item('warning', sprintf(
                '%d post(s) stuck past their scheduled time — oldest %s ago. Usually a sign cron has stalled.',
                $m['missed_count'],
                $this->humanSeconds($m['oldest_missed_age_seconds'])
            ))];
        }

        return $this->render(
            'Future-dated posts are published by WP-Cron. If cron stalls they get stuck on "Missed schedule."',
            $items
        );
    }

    private function describeSsl(array $m, bool $healthy): string
    {
        if (empty($m['available'])) {
            $items = [$this->item('info', sprintf('Certificate could not be read%s.', $m['error'] ? ': ' . $m['error'] : ''))];
        } else {
            $days  = $m['days_to_expiry'];
            $when  = $m['expires_at'] ? substr($m['expires_at'], 0, 10) : 'unknown';
            $state = ($days !== null && $days < 0) ? 'already expired' : sprintf('%d day(s) away', (int) $days);
            $items = [$this->item($healthy ? 'good' : 'warning', sprintf(
                'Certificate expires %s (%s; warns under %d days).',
                $when,
                $state,
                $m['threshold_days']
            ))];
        }

        return $this->render(
            'The TLS certificate served for this site. An expired certificate shows a security warning to every visitor.',
            $items
        );
    }

    /**
     * Fallback for collectors added via filter that we have no bespoke copy for.
     *
     * @param array<string, mixed> $m
     */
    private function describeGeneric(array $m): string
    {
        $items = [];
        foreach ($m as $key => $value) {
            $items[] = $this->item('info', sprintf('%s: %s', $key, $this->scalar($value)));
        }

        return $this->render('Health signal reported by WP Haven Connect.', $items);
    }

    /**
     * @param string[] $items Pre-rendered <li> strings.
     */
    private function render(string $intro, array $items): string
    {
        $out = '<p>' . esc_html($intro) . '</p>';
        if ($items) {
            $out .= '<ul>' . implode('', $items) . '</ul>';
        }

        return $out;
    }

    private function item(string $severity, string $text): string
    {
        $map = [
            'good'    => ['dashicons-yes-alt', '#00a32a', __('Passed', 'wphaven-connect')],
            'warning' => ['dashicons-warning', '#dba617', __('Warning', 'wphaven-connect')],
            'info'    => ['dashicons-info-outline', '#72aee6', __('Info', 'wphaven-connect')],
        ];
        $m = isset($map[$severity]) ? $map[$severity] : $map['info'];

        return sprintf(
            '<li><span class="dashicons %s" style="color:%s" aria-hidden="true"></span> <strong>%s</strong> %s</li>',
            esc_attr($m[0]),
            esc_attr($m[1]),
            esc_html($m[2]),
            esc_html($text)
        );
    }

    /**
     * @param int|null $seconds
     */
    private function humanSeconds($seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $s = (int) $seconds;
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return round($s / 60) . ' min';
        }
        if ($s < 86400) {
            return round($s / 3600, 1) . ' h';
        }
        return round($s / 86400, 1) . ' days';
    }

    /**
     * @param float|int|null $bytes
     */
    private function formatBytes($bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $b     = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = 0;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return round($b, 1) . ' ' . $units[$i];
    }

    /**
     * @param mixed $value
     */
    private function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null) {
            return '—';
        }
        if (is_array($value)) {
            return $value === [] ? '(none)' : implode(', ', array_map('strval', $value));
        }
        return (string) $value;
    }

    private function label(HealthCollector $collector): string
    {
        if (method_exists($collector, 'label')) {
            return $collector->label();
        }

        return ucwords(str_replace('_', ' ', $collector->key()));
    }
}
