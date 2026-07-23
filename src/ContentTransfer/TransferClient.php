<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;

/**
 * Outbound HTTP client for talking to the paired environment's content-transfer
 * REST routes. Push sends an envelope to the remote's import route; pull fetches
 * an envelope from the remote's export route so it can be imported locally.
 *
 * The target is the single configured Production URL and the request carries the
 * shared transfer secret as a Bearer token.
 */
class TransferClient
{
    /**
     * REST routes are addressed via the query-string form
     * (`/index.php?rest_route=/wphaven-connect/v1/...`) rather than the pretty
     * `/wp-json/...` path. It behaves identically but sidesteps common server
     * hardening that blocks POST to the `/wp-json/` path (and works even without
     * pretty permalinks).
     */
    const REST_ROUTE_BASE = '/index.php?rest_route=/wphaven-connect/v1';

    const PRODUCTION_URL_OPTION = 'wphaven_production_url';

    const PRODUCTION_URL_CONSTANT = 'WPHAVEN_PRODUCTION_URL';

    /**
     * The configured Production URL (constant beats option), or null if unset.
     */
    public static function productionUrl(): ?string
    {
        if (defined(self::PRODUCTION_URL_CONSTANT) && constant(self::PRODUCTION_URL_CONSTANT)) {
            return untrailingslashit((string) constant(self::PRODUCTION_URL_CONSTANT));
        }

        $opts = get_option('wphaven_connect_options', []);
        $url  = is_array($opts) && ! empty($opts[self::PRODUCTION_URL_OPTION]) ? $opts[self::PRODUCTION_URL_OPTION] : '';

        return $url !== '' ? untrailingslashit($url) : null;
    }

    /**
     * Push an envelope to the remote import route.
     *
     * @param array<string, mixed> $envelope
     * @param array{publish?: bool, overwrite_conflict?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    public function push(array $envelope, array $args = [])
    {
        return $this->request('/content/import', [
            'envelope'           => $envelope,
            'publish'            => ! empty($args['publish']),
            'overwrite_conflict' => ! empty($args['overwrite_conflict']),
        ]);
    }

    /**
     * Ask the remote what a push would change, without writing.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|WP_Error
     */
    public function previewOnRemote(array $envelope)
    {
        return $this->request('/content/preview', ['envelope' => $envelope]);
    }

    /**
     * Fetch an envelope for a piece of content from the remote export route.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function fetchExport(string $content_id)
    {
        return $this->request('/content/export', ['content_id' => $content_id]);
    }

    /**
     * List the remote's transferable tables (base name, rows, size).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function dbTables()
    {
        return $this->request('/database/tables', []);
    }

    /**
     * Ask the remote to create a fresh stage table for a base table (push).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function dbBegin(string $base)
    {
        return $this->request('/database/begin', ['base' => $base]);
    }

    /**
     * Send a batch of rows to the remote's stage table (push).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|WP_Error
     */
    public function dbChunk(string $base, array $rows)
    {
        return $this->request('/database/chunk', ['base' => $base, 'rows' => $rows]);
    }

    /**
     * Ask the remote to URL-rewrite the stage table and swap it into place (push).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function dbFinalize(string $base, string $source_site_url)
    {
        return $this->request('/database/finalize', ['base' => $base, 'source_site_url' => $source_site_url]);
    }

    /**
     * Fetch a chunk of rows from the remote table (pull).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function dbExport(string $base, int $offset, int $limit)
    {
        return $this->request('/database/export', ['base' => $base, 'offset' => $offset, 'limit' => $limit]);
    }

    /**
     * List the remote's uploads files (path, size, mtime).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function uploadsManifest()
    {
        return $this->request('/uploads/manifest', []);
    }

    /**
     * Fetch a byte range of a remote uploads file (pull).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function uploadsFetch(string $path, int $offset, int $length)
    {
        return $this->request('/uploads/fetch', ['path' => $path, 'offset' => $offset, 'length' => $length]);
    }

    /**
     * Write a byte range of an uploads file on the remote (push).
     *
     * @return array<string, mixed>|WP_Error
     */
    public function uploadsReceive(string $path, int $offset, string $data_base64, int $size, int $mtime, bool $done)
    {
        return $this->request('/uploads/receive', [
            'path'   => $path,
            'offset' => $offset,
            'data'   => $data_base64,
            'size'   => $size,
            'mtime'  => $mtime,
            'done'   => $done,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $path, array $body)
    {
        $base = self::productionUrl();
        if ($base === null) {
            return new WP_Error('wphaven_no_production_url', __('No Production URL is configured.', 'wphaven-connect'), ['status' => 400]);
        }

        $secret = ConnectionSecret::get();
        if ($secret === null) {
            return new WP_Error('wphaven_no_secret', __('No environment connection secret is configured.', 'wphaven-connect'), ['status' => 400]);
        }

        $response = wp_remote_post($base . self::REST_ROUTE_BASE . $path, [
            'timeout'    => 60,
            'user-agent' => 'WPHavenConnect',
            'headers'    => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $secret,
            ],
            'body'       => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            if (is_array($data) && isset($data['message'])) {
                // A structured WordPress/plugin error.
                $message = $data['message'];
            } else {
                // Non-JSON body ⇒ the request was rejected before WordPress ran
                // (web-server rule, security layer, or CDN), not by the plugin.
                $message = sprintf(
                    /* translators: %d: HTTP status code */
                    __('The destination returned a non-WordPress %d response — it was blocked before reaching the plugin (a web-server/security rule on the destination, e.g. POST disabled for /wp-json/). Check the destination server logs.', 'wphaven-connect'),
                    $code
                );
            }
            return new WP_Error('wphaven_remote_error', $message, ['status' => $code, 'body' => $data]);
        }

        return is_array($data) ? $data : [];
    }
}
