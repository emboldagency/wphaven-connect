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
    const REST_BASE = '/wp-json/wphaven-connect/v1';

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

        $response = wp_remote_post($base . self::REST_BASE . $path, [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $secret,
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) && isset($data['message'])
                ? $data['message']
                : sprintf(/* translators: %d: HTTP status code */ __('Remote responded with status %d.', 'wphaven-connect'), $code);
            return new WP_Error('wphaven_remote_error', $message, ['status' => $code, 'body' => $data]);
        }

        return is_array($data) ? $data : [];
    }
}
