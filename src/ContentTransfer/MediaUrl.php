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
        if (defined('ASSET_URL') && ASSET_URL && strpos($url, rtrim(ASSET_URL, '/')) === 0) {
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

        if (defined('ASSET_URL') && ASSET_URL) {
            $asset_baseurl = trailingslashit(rtrim(ASSET_URL, '/'));
            if (strpos($url, $asset_baseurl) === 0) {
                return ltrim(substr($url, strlen($asset_baseurl)), '/');
            }
        }

        return null;
    }
}
