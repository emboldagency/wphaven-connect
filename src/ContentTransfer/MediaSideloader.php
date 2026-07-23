<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;

/**
 * Imports the media referenced by a transfer envelope and builds the remap that
 * the importer applies to the featured image, post content and ACF fields.
 *
 * Media binaries never travel inside the envelope -- only URLs do. Each manifest
 * item is classified on the source as `production` (already resident on the
 * production/ASSET_URL host) or `local` (a file on the source's own uploads
 * disk). The destination then decides whether to sideload the binary, link an
 * existing attachment by its relative upload path, or create a metadata-only
 * attachment row that WPHaven's AssetUrlServiceProvider will serve from
 * ASSET_URL.
 *
 * @see \WPHavenConnect\Providers\AssetUrlServiceProvider for the path math this
 *      mirrors and the deletion guard the metadata-only rows must respect.
 */
class MediaSideloader
{
    const SOURCE_URL_META = '_wphaven_source_url';

    /**
     * Hosts we are willing to fetch binaries from. Anything else is rejected to
     * avoid turning the import endpoint into an SSRF vector.
     *
     * @var string[]
     */
    private array $allowedHosts;

    private bool $destinationIsProduction;

    /** @var array<int, int> old source attachment id => new local attachment id */
    private array $idMap = [];

    /** @var array<string, string> source url => local url */
    private array $urlMap = [];

    /** @var array<int, string> non-fatal warnings accumulated during import */
    private array $warnings = [];

    /**
     * @param string[] $allowedHosts Hostnames the manifest URLs must belong to.
     */
    public function __construct(array $allowedHosts, bool $destinationIsProduction)
    {
        $this->allowedHosts = array_values(array_filter(array_map('strtolower', $allowedHosts)));
        $this->destinationIsProduction = $destinationIsProduction;
    }

    /**
     * Import every item in a media manifest, populating the id and url remaps.
     *
     * @param array<int, array<string, mixed>> $manifest
     */
    public function importManifest(array $manifest): void
    {
        foreach ($manifest as $item) {
            $this->importItem($item);
        }
    }

    /**
     * Import a single manifest item and record its remap entries.
     *
     * @param array<string, mixed> $item
     * @return int|null The resulting local attachment id, or null on failure.
     */
    public function importItem(array $item): ?int
    {
        $source_id    = isset($item['source_attachment_id']) ? (int) $item['source_attachment_id'] : 0;
        $source_url   = isset($item['source_url']) ? (string) $item['source_url'] : '';
        $relative     = isset($item['relative_path']) ? (string) $item['relative_path'] : '';
        $origin       = $item['origin'] ?? 'local';

        if ($source_url === '') {
            return null;
        }

        $attachment_id = $origin === 'production'
            ? $this->resolveProductionHosted($relative, $source_url, $item)
            : $this->sideload($source_url, $item);

        if ($attachment_id === null) {
            return null;
        }

        if ($source_id > 0) {
            $this->idMap[$source_id] = $attachment_id;
        }

        $local_url = wp_get_attachment_url($attachment_id);
        if (is_string($local_url) && $local_url !== '') {
            $this->urlMap[$source_url] = $local_url;
        }

        return $attachment_id;
    }

    /**
     * A production-hosted file already lives on the production disk. Link the
     * existing attachment by its relative upload path when possible; otherwise
     * either sideload (if the destination *is* production and the row is
     * genuinely missing) or create a metadata-only row that ASSET_URL serves.
     *
     * @param array<string, mixed> $item
     */
    private function resolveProductionHosted(string $relative, string $source_url, array $item): ?int
    {
        if ($relative !== '') {
            $existing = $this->findAttachmentByRelativePath($relative);
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($this->destinationIsProduction) {
            // The file should be here but no attachment row matches -- fetch it.
            return $this->sideload($source_url, $item);
        }

        // Staging/dev: don't pull the binary, register a row so ids resolve and
        // AssetUrlServiceProvider rewrites requests to ASSET_URL.
        return $this->createRemoteAttachmentRow($relative, $source_url, $item);
    }

    /**
     * Download and store a binary, de-duplicating against a previous import.
     *
     * @param array<string, mixed> $item
     */
    private function sideload(string $source_url, array $item): ?int
    {
        $existing = $this->findAttachmentBySourceUrl($source_url);
        if ($existing !== null) {
            return $existing;
        }

        if (! $this->hostAllowed($source_url)) {
            $this->warnings[] = sprintf('Refused to fetch off-host media URL: %s', $source_url);
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($source_url);
        if (is_wp_error($tmp)) {
            $this->warnings[] = sprintf('Download failed for %s: %s', $source_url, $tmp->get_error_message());
            return null;
        }

        $filename = $item['filename'] ?? wp_basename(wp_parse_url($source_url, PHP_URL_PATH) ?? 'file');
        $filename = sanitize_file_name($filename);

        $check = wp_check_filetype_and_ext($tmp, $filename);
        if (empty($check['ext']) || empty($check['type'])) {
            wp_delete_file($tmp);
            $this->warnings[] = sprintf('Rejected media with disallowed type: %s', $source_url);
            return null;
        }

        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            $this->warnings[] = sprintf('Sideload failed for %s: %s', $source_url, $attachment_id->get_error_message());
            return null;
        }

        update_post_meta($attachment_id, self::SOURCE_URL_META, $source_url);
        $this->applyAltText($attachment_id, $item);

        return $attachment_id;
    }

    /**
     * Create an attachment row whose binary lives only on production. No file is
     * written locally; AssetUrlServiceProvider serves it from ASSET_URL.
     *
     * @param array<string, mixed> $item
     */
    private function createRemoteAttachmentRow(string $relative, string $source_url, array $item): ?int
    {
        if ($relative === '') {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $local_url  = trailingslashit($upload_dir['baseurl']) . ltrim($relative, '/');
        $mime       = $item['mime'] ?? '';
        if ($mime === '') {
            $mime = wp_check_filetype(wp_basename($relative))['type'] ?: 'application/octet-stream';
        }

        $attachment_id = wp_insert_attachment([
            'guid'           => $local_url,
            'post_mime_type' => $mime,
            'post_title'     => sanitize_text_field($item['filename'] ?? wp_basename($relative)),
            'post_status'    => 'inherit',
        ]);

        if (is_wp_error($attachment_id) || ! $attachment_id) {
            $this->warnings[] = sprintf('Could not register production-hosted media: %s', $source_url);
            return null;
        }

        update_post_meta($attachment_id, '_wp_attached_file', ltrim($relative, '/'));
        update_post_meta($attachment_id, self::SOURCE_URL_META, $source_url);
        $this->applyAltText($attachment_id, $item);

        return $attachment_id;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyAltText(int $attachment_id, array $item): void
    {
        if (! empty($item['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($item['alt']));
        }
    }

    /**
     * Find an attachment previously imported from the same source URL.
     */
    private function findAttachmentBySourceUrl(string $source_url): ?int
    {
        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_key'       => self::SOURCE_URL_META,
            'meta_value'     => $source_url,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return $matches[0] ?? null;
    }

    /**
     * Find an attachment by its uploads-relative path (e.g. "2024/01/foo.jpg").
     */
    private function findAttachmentByRelativePath(string $relative): ?int
    {
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            return null;
        }

        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'meta_key'       => '_wp_attached_file',
            'meta_value'     => $relative,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        return $matches[0] ?? null;
    }

    private function hostAllowed(string $url): bool
    {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        return in_array($host, $this->allowedHosts, true);
    }

    /**
     * @return array<int, int>
     */
    public function idMap(): array
    {
        return $this->idMap;
    }

    /**
     * @return array<string, string>
     */
    public function urlMap(): array
    {
        return $this->urlMap;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
