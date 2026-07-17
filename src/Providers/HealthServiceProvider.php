<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;
use WPHavenConnect\Health\BootableCollector;
use WPHavenConnect\Health\HealthCollector;
use WPHavenConnect\Health\HealthCollectorRegistry;

/**
 * Aggregates site-health signals for fleet monitoring.
 *
 * Exposes GET /wphaven-connect/v1/health -- one endpoint a monitor can poll and
 * assert on (top-level `ok`, plus per-signal detail under `signals`). Each signal
 * is a small HealthCollector; add more by registering them via the
 * `wphaven_connect_health_collectors` filter. The original single-signal
 * /cron-health route (and the `cron-health` CLI command) are preserved as
 * aliases so nothing built against the earlier endpoint breaks.
 */
class HealthServiceProvider
{
    /** @var HealthCollector[] */
    private $collectors = [];

    public function register()
    {
        $this->collectors = $this->makeCollectors();

        // Stateful collectors register their hooks now so they can record state
        // between polls (e.g. mail failures). Stateless ones do nothing here.
        foreach ($this->collectors as $collector) {
            if ($collector instanceof BootableCollector) {
                $collector->boot();
            }
        }

        add_action('rest_api_init', [$this, 'registerRoutes']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('wphaven health', [$this, 'handleHealthCli']);
            // Back-compat with the original single-signal command.
            \WP_CLI::add_command('cron-health', [$this, 'handleCronHealthCli']);
        }
    }

    /**
     * @return HealthCollector[]
     */
    private function makeCollectors(): array
    {
        return HealthCollectorRegistry::all();
    }

    public function registerRoutes(): void
    {
        register_rest_route('wphaven-connect/v1', '/health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getHealth'],
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],
        ]);

        // Back-compat alias for the original single-signal endpoint.
        register_rest_route('wphaven-connect/v1', '/cron-health', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getCronHealth'],
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],
        ]);
    }

    public function getHealth(): WP_REST_Response
    {
        return new WP_REST_Response($this->collectAll(), 200);
    }

    public function getCronHealth(): WP_REST_Response
    {
        // Preserve the original flat cron payload for existing consumers.
        return new WP_REST_Response($this->collectSignal('cron'), 200);
    }

    /**
     * Aggregate every collector into one payload with an overall `ok` flag a
     * JSON-query monitor can assert on.
     *
     * @return array<string, mixed>
     */
    public function collectAll(): array
    {
        $signals = [];
        $ok      = true;

        foreach ($this->collectors as $collector) {
            $metrics = $collector->collect();
            $healthy = $collector->isHealthy($metrics);
            $ok      = $ok && $healthy;

            // `ok` first, then the collector's own metrics.
            $signals[$collector->key()] = ['ok' => $healthy] + $metrics;
        }

        return [
            'ok'         => $ok,
            'checked_at' => gmdate('c'),
            'signals'    => $signals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSignal(string $key): array
    {
        foreach ($this->collectors as $collector) {
            if ($collector->key() === $key) {
                return $collector->collect();
            }
        }

        return [];
    }

    public function handleHealthCli($args, $assoc_args): void
    {
        \WP_CLI::line(wp_json_encode($this->collectAll()));
    }

    public function handleCronHealthCli($args, $assoc_args): void
    {
        $health = $this->collectSignal('cron');

        if (isset($assoc_args['field'])) {
            $field = $assoc_args['field'];
            if (array_key_exists($field, $health)) {
                $value = $health[$field];
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                \WP_CLI::line((string) $value);
            } else {
                \WP_CLI::error("Field '$field' not found.");
            }
        } else {
            \WP_CLI::line(wp_json_encode($health));
        }
    }
}
