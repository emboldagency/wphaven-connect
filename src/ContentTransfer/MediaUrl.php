<?php

namespace WPHavenConnect\ContentTransfer;

/**
 * Stateless helpers for classifying an image/media URL relative to this site's
 * uploads directory and the ASSET_URL production host. Shared by the serializer
 * (source side) and the sideloader (destination side) so both agree on what a
 * URL means.
 *
 * @see \WPHavenConnect\Providers\AssetUrlServiceProvider
 */
class MediaUrl
{
    const ORIGIN_PRODUCTION = 'production';

    const ORIGIN_LOCAL = 'local';

    const ORIGIN_FOREIGN = 'foreign';

    /**
     * Where does this URL's binary physically live, from the source's point of
     * view?
     *
     * - production: served from the ASSET_URL host (already on production).
     * - local: under this site's own uploads directory.
     * - foreign: anything else (an external/hotlinked asset).
     */
    public static function classifyOrigin(string $url): string
    {
        if (self::isAssetUrl($url)) {
            return self::ORIGIN_PRODUCTION;
        }

        if (self::relativePath($url) !== null) {
            return self::ORIGIN_LOCAL;
        }

        return self::ORIGIN_FOREIGN;
    }

    /**
     * Extract the uploads-relative path (e.g. "2024/01/foo.jpg") from a URL that
     * points either at this site's uploads dir or at the ASSET_URL host. Returns
     * null when the URL belongs to neither.
     */
    public static function relativePath(string $url): ?string
    {
        $upload_dir      = wp_upload_dir();
        $uploads_baseurl = trailingslashit($upload_dir['baseurl']);

        if (strpos($url, $uploads_baseurl) === 0) {
            return ltrim(substr($url, strlen($uploads_baseurl)), '/');
        }

        // Also match the protocol-relative and scheme-swapped forms of the base.
        $host_relative = trailingslashit(preg_replace('#^https?:#', '', $uploads_baseurl));
        $url_host_relative = preg_replace('#^https?:#', '', $url);
        if (is_string($url_host_relative) && strpos($url_host_relative, $host_relative) === 0) {
            return ltrim(substr($url_host_relative, strlen($host_relative)), '/');
        }

        if (self::isAssetUrl($url)) {
            $path = (string) wp_parse_url(self::withScheme($url), PHP_URL_PATH);
            $path = substr($path, strlen(self::assetPath()));
            $uploads_path = rtrim((string) wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/').'/';

            if (strpos($path, $uploads_path) === 0) {
                return ltrim(substr($path, strlen($uploads_path)), '/');
            }
        }

        return null;
    }

    /**
     * ASSET_URL as a full base URL with no trailing slash, or '' when unset.
     * Accepts "https://host", "http://host", "//host" and a bare "host"; the
     * last two get https.
     */
    public static function assetBaseUrl(): string
    {
        if (! defined('ASSET_URL') || ! ASSET_URL) {
            return '';
        }

        $url = trim((string) ASSET_URL);
        if (strpos($url, '//') === 0) {
            $url = 'https:'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return rtrim($url, '/');
    }

    /**
     * The ASSET_URL host with and without "www.", for host allowlists.
     *
     * @return string[]
     */
    public static function assetHosts(): array
    {
        $host = self::hostKey(wp_parse_url(self::assetBaseUrl(), PHP_URL_HOST));

        return $host === '' ? [] : [$host, 'www.'.$host];
    }

    /**
     * Whether a URL points at the ASSET_URL host, ignoring scheme and "www.".
     */
    public static function isAssetUrl(string $url): bool
    {
        $asset_host = self::hostKey(wp_parse_url(self::assetBaseUrl(), PHP_URL_HOST));
        if ($asset_host === '') {
            return false;
        }

        $parts = wp_parse_url(self::withScheme($url));
        if (! is_array($parts) || self::hostKey($parts['host'] ?? null) !== $asset_host) {
            return false;
        }

        $asset_path = self::assetPath();

        return $asset_path === '' || strpos(($parts['path'] ?? '').'/', $asset_path.'/') === 0;
    }

    /**
     * Placeholder tokens for every scheme/www spelling of the ASSET_URL uploads
     * base, so a search-replace can shield production media (and only media)
     * and restore it after: strtr($text, $map) before, strtr($text,
     * array_flip($map)) after.
     *
     * @return array<string, string> spelling => token
     */
    public static function assetShieldMap(): array
    {
        $upload_dir = wp_upload_dir();
        $uploads_path = rtrim((string) wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/');

        $map = [];
        foreach (self::assetHosts() as $i => $host) {
            $map['//'.$host.self::assetPath().$uploads_path.'/'] = '%%WPHAVEN_ASSET_URL_'.$i.'%%';
        }

        return $map;
    }

    private static function assetPath(): string
    {
        return rtrim((string) wp_parse_url(self::assetBaseUrl(), PHP_URL_PATH), '/');
    }

    private static function hostKey($host): string
    {
        $host = strtolower((string) $host);

        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    private static function withScheme(string $url): string
    {
        return strpos($url, '//') === 0 ? 'https:'.$url : $url;
    }
}
