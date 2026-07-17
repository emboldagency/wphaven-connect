<?php

namespace WPHavenConnect\Health;

/**
 * Warns before the site's TLS certificate expires -- a recurring, entirely
 * silent failure until browsers start throwing warnings.
 *
 * Reads the certificate actually served for the site's own host (so it reflects
 * whatever a visitor sees, edge/CDN cert included). The TLS handshake is the one
 * comparatively expensive probe in the collector set, so the parsed expiry is
 * cached in a transient and days-to-expiry is recomputed from that timestamp on
 * every poll -- the handshake happens at most ~once per cache window, reads stay
 * current. An external monitor is arguably more authoritative here, so a site
 * that relies on one can drop this via the wphaven_connect_health_collectors
 * filter.
 *
 * When TLS can't be probed (openssl missing, non-https site, connection fails),
 * `available` is false and the signal stays healthy rather than false-alarming.
 */
class SslHealthCollector implements HealthCollector
{
    const TRANSIENT             = 'wphaven_connect_ssl_health';
    const DEFAULT_THRESHOLD_DAYS = 14;
    const CACHE_TTL             = 43200; // 12h on success
    const CACHE_TTL_ERROR       = 1800;  // 30m on failure (retry sooner)
    const CONNECT_TIMEOUT       = 5;
    const DAY                   = 86400;

    public function key(): string
    {
        return 'ssl';
    }

    public function label(): string
    {
        return 'SSL certificate';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $threshold = (int) apply_filters('wphaven_connect_ssl_expiry_threshold_days', self::DEFAULT_THRESHOLD_DAYS);
        $data      = $this->cachedCertData();

        $expiry_ts = $data['expiry_ts'];
        $days      = $expiry_ts === null ? null : (int) floor(($expiry_ts - time()) / self::DAY);

        return [
            'available'      => $data['available'],
            'host'           => $data['host'],
            'expires_at'     => $expiry_ts === null ? null : gmdate('c', $expiry_ts),
            'days_to_expiry' => $days,
            'threshold_days' => $threshold,
            'error'          => $data['error'],
            'checked_at'     => $data['checked_at'] === null ? null : gmdate('c', $data['checked_at']),
        ];
    }

    public function isHealthy(array $metrics): bool
    {
        // Not measurable / not https -> don't false-alarm (leave it to an
        // external monitor). Otherwise flag when expiry is within the window
        // (expired certs land here too, with negative days).
        if (empty($metrics['available']) || $metrics['days_to_expiry'] === null) {
            return true;
        }

        return $metrics['days_to_expiry'] > (int) $metrics['threshold_days'];
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedCertData(): array
    {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->probeCertificate();
        $ttl  = $data['available'] ? self::CACHE_TTL : self::CACHE_TTL_ERROR;
        set_transient(self::TRANSIENT, $data, $ttl);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function probeCertificate(): array
    {
        $host = $this->host();
        $data = [
            'available'  => false,
            'host'       => $host,
            'expiry_ts'  => null,
            'error'      => null,
            'checked_at' => time(),
        ];

        if ($host === null) {
            $data['error'] = 'site is not served over https';
            return $data;
        }

        if (!function_exists('openssl_x509_parse')) {
            $data['error'] = 'openssl unavailable';
            return $data;
        }

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            $data['error'] = $errstr !== '' ? $errstr : 'tls connection failed';
            return $data;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null;
        if ($cert === null) {
            $data['error'] = 'no peer certificate';
            return $data;
        }

        $parsed = openssl_x509_parse($cert);
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            $data['error'] = 'could not parse certificate';
            return $data;
        }

        $data['available'] = true;
        $data['expiry_ts'] = (int) $parsed['validTo_time_t'];

        return $data;
    }

    /**
     * The https host to probe, or null if the site isn't served over https.
     *
     * @return string|null
     */
    private function host()
    {
        $url = function_exists('home_url') ? home_url() : '';

        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host ? $host : null;
    }
}
