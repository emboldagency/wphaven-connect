<?php

namespace WPHavenConnect\Health;

/**
 * A single site-health signal that contributes to the /health endpoint.
 *
 * Collectors are intentionally small: collect() runs on every poll and must stay
 * cheap and side-effect-free. Signals that need to observe events over time
 * (e.g. recording mail failures between polls) additionally implement
 * BootableCollector to register their long-lived hooks once at load.
 */
interface HealthCollector
{
    /**
     * Stable machine key for this signal in the /health payload (e.g. "cron").
     */
    public function key(): string;

    /**
     * Collect this signal's metrics. The returned array is emitted as-is in the
     * payload, so its keys are part of the public contract. Must be cheap.
     *
     * @return array<string, mixed>
     */
    public function collect(): array;

    /**
     * Whether the collected metrics represent a healthy state. Pure function of
     * the array returned by collect(); used to compute the aggregate `ok` flag.
     *
     * @param array<string, mixed> $metrics
     */
    public function isHealthy(array $metrics): bool;
}
