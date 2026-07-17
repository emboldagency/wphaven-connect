<?php

namespace WPHavenConnect\Health;

use WP_Error;

/**
 * Detects silently failing outbound email -- the most common "the client noticed
 * before we did" failure in our support history (order/form/notification mail
 * quietly not sending because an SMTP provider or plugin broke).
 *
 * We can't confirm inbox delivery, but wp_mail()/PHPMailer surfaces send-time
 * failures via the `wp_mail_failed` action, which this records. Intentional
 * blocks (the staging safety nets in wphaven-connect and embold-wordpress-tweaks)
 * ALSO fire `wp_mail_failed` -- so we ignore those specific WP_Error codes to
 * avoid false positives, and expose `blocked` so a monitor can tell mail is off
 * by design rather than broken.
 *
 * Stateful (BootableCollector): failure/success/block timestamps are recorded via
 * hooks and read back on each poll, stored in a single NON-autoloaded option so
 * this signal never contributes to autoload bloat (which we also want to watch).
 */
class EmailHealthCollector implements HealthCollector, BootableCollector
{
    const OPTION = 'wphaven_connect_mail_health';

    /** Seconds after which an unresolved failure is treated as resolved/stale. */
    const DEFAULT_STALE_THRESHOLD = 3600;

    /** Throttle: never rewrite the success timestamp more often than this. */
    const SUCCESS_WRITE_INTERVAL = 300;

    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Outbound email';
    }

    public function boot(): void
    {
        add_action('wp_mail_failed', [$this, 'recordFailure']);
        // wp_mail_succeeded exists in WP 5.9+; harmlessly never fires on older.
        add_action('wp_mail_succeeded', [$this, 'recordSuccess']);
    }

    /**
     * @param WP_Error|mixed $error
     */
    public function recordFailure($error): void
    {
        $code  = ($error instanceof WP_Error) ? $error->get_error_code() : '';
        $state = $this->state();

        // Intentional blocks (staging safety nets) also fire wp_mail_failed --
        // record them as "blocked", not as a delivery failure.
        if (in_array($code, $this->blockCodes(), true)) {
            $state['last_blocked_at'] = time();
            $this->save($state);
            return;
        }

        $message = ($error instanceof WP_Error) ? $error->get_error_message() : 'Unknown mail failure';

        $state['last_failure_at'] = time();
        $state['last_error']      = substr(sanitize_text_field($message), 0, 200);
        $state['failure_count']   = (int) $state['failure_count'] + 1;

        $this->save($state);
    }

    public function recordSuccess(): void
    {
        $state = $this->state();
        $now   = time();

        // Throttle writes: a high-volume site shouldn't write an option per email.
        if ($state['last_success_at'] !== null && ($now - $state['last_success_at']) < self::SUCCESS_WRITE_INTERVAL) {
            return;
        }

        $state['last_success_at'] = $now;
        $this->save($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now   = time();
        $state = $this->state();

        $failure_at = $state['last_failure_at'];
        $success_at = $state['last_success_at'];
        $blocked_at = $state['last_blocked_at'];

        $failure_age = $failure_at === null ? null : max(0, $now - $failure_at);
        $success_age = $success_at === null ? null : max(0, $now - $success_at);

        // "blocked" = the most recent observed mail attempt was an intentional block.
        $blocked = $blocked_at !== null
            && ($failure_at === null || $blocked_at >= $failure_at)
            && ($success_at === null || $blocked_at >= $success_at);

        $threshold = (int) apply_filters('wphaven_connect_mail_stale_threshold', self::DEFAULT_STALE_THRESHOLD);

        return [
            'blocked'                  => $blocked,
            'last_failure_at'          => $failure_at === null ? null : gmdate('c', $failure_at),
            'last_failure_age_seconds' => $failure_age,
            'last_error'               => $state['last_error'],
            'last_success_at'          => $success_at === null ? null : gmdate('c', $success_at),
            'last_success_age_seconds' => $success_age,
            'failure_count'            => (int) $state['failure_count'],
            'stale_threshold_seconds'  => $threshold,
        ];
    }

    public function isHealthy(array $metrics): bool
    {
        // Mail intentionally off (e.g. staging) is not a failure.
        if (!empty($metrics['blocked'])) {
            return true;
        }

        // Never observed a failure.
        if ($metrics['last_failure_at'] === null) {
            return true;
        }

        $failure_age = $metrics['last_failure_age_seconds'];
        $success_age = $metrics['last_success_age_seconds'];

        // A success at or after the last failure means mail recovered.
        if ($success_age !== null && $success_age <= $failure_age) {
            return true;
        }

        // An old failure with no activity since is treated as resolved.
        if ($failure_age > (int) $metrics['stale_threshold_seconds']) {
            return true;
        }

        return false;
    }

    /**
     * WP_Error codes used by intentional mail-blockers (our safety net +
     * embold-wordpress-tweaks). Filterable so other blockers can register.
     *
     * @return string[]
     */
    private function blockCodes(): array
    {
        return apply_filters('wphaven_connect_mail_block_codes', [
            'wphaven_mail_blocked',
            'embold_mail_blocked',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $defaults = [
            'last_failure_at' => null,
            'last_error'      => null,
            'last_success_at' => null,
            'last_blocked_at' => null,
            'failure_count'   => 0,
        ];

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        // Non-autoloaded on purpose.
        update_option(self::OPTION, $state, false);
    }
}
