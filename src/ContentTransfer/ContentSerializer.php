<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;
use WP_Post;
use WPHavenConnect\Utilities\Environment;

/**
 * Serialises a single post into the transfer envelope: post fields, filtered
 * meta, term assignments (by slug, never by id), the featured image and a media
 * manifest of every image the post references.
 *
 * The manifest embeds no binaries -- only URLs, classified by origin so the
 * destination can decide whether to sideload, link an existing file, or defer to
 * ASSET_URL. Extension points (`wphaven_content_export_*`) let ACF/Yoast/Woo
 * specifics be layered without touching this class.
 */
class ContentSerializer
{
    const ENVELOPE_VERSION = 1;

    /**
     * Meta keys never copied: WordPress internals and this plugin's own
     * bookkeeping. The featured image travels as its own envelope field, so
     * `_thumbnail_id` is excluded here and reconstructed on import.
     *
     * @var string[]
     */
    const META_DENYLIST = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_thumbnail_id',
        '_pingme',
        '_encloseme',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        ContentIdentity::META_KEY,
        MediaSideloader::SOURCE_URL_META,
        ContentImporter::BACKUP_META,
    ];

    /**
     * Build the envelope for a post.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function export(int $post_id)
    {
        $post = get_post($post_id);
        if (! $post instanceof WP_Post) {
            return new WP_Error('wphaven_export_missing', __('Post not found.', 'wphaven-connect'), ['status' => 404]);
        }

        $content_id = ContentIdentity::ensure($post_id);

        $payload = [
            'envelope_version'    => self::ENVELOPE_VERSION,
            'content_id'          => $content_id,
            'source_env'          => Environment::get_environment(),
            'source_site_url'     => site_url(),
            'generated_at_gmt'    => gmdate('Y-m-d\TH:i:s\Z'),
            'source_modified_gmt' => $post->post_modified_gmt,
            'post'                => $this->buildPostFields($post),
            'meta'                => $this->buildMeta($post_id),
            'terms'               => $this->buildTerms($post),
            'featured_image'      => $this->buildFeaturedImage($post_id),
            'media_manifest'      => $this->buildMediaManifest($post),
        ];

        $payload = apply_filters('wphaven_content_export_payload', $payload, $post);

        $payload['checksum'] = self::checksum($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPostFields(WP_Post $post): array
    {
        $author       = get_userdata((int) $post->post_author);
        $parent_uuid  = $post->post_parent ? ContentIdentity::get((int) $post->post_parent) : null;

        return [
            'post_title'             => $post->post_title,
            'post_content'           => $post->post_content,
            'post_excerpt'           => $post->post_excerpt,
            'post_status'            => $post->post_status,
            'post_type'              => $post->post_type,
            'post_name'              => $post->post_name,
            'post_date_gmt'          => $post->post_date_gmt,
            'menu_order'             => (int) $post->menu_order,
            'post_parent_content_id' => $parent_uuid,
            'author_email'           => $author ? $author->user_email : null,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function buildMeta(int $post_id): array
    {
        $all  = get_post_meta($post_id);
        $meta = [];

        foreach ($all as $key => $values) {
            if (in_array($key, self::META_DENYLIST, true)) {
                continue;
            }
            $meta[$key] = array_map('maybe_unserialize', (array) $values);
        }

        return apply_filters('wphaven_content_export_meta', $meta, $post_id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTerms(WP_Post $post): array
    {
        $terms = [];

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $assigned = wp_get_object_terms($post->ID, $taxonomy);
            if (is_wp_error($assigned)) {
                continue;
            }

            foreach ($assigned as $term) {
                $parent_slug = null;
                if ($term->parent) {
                    $parent = get_term($term->parent, $taxonomy);
                    $parent_slug = ($parent && ! is_wp_error($parent)) ? $parent->slug : null;
                }

                $terms[] = [
                    'taxonomy'    => $taxonomy,
                    'slug'        => $term->slug,
                    'name'        => $term->name,
                    'description' => $term->description,
                    'parent_slug' => $parent_slug,
                ];
            }
        }

        return $terms;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFeaturedImage(int $post_id): ?array
    {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if (! $thumb_id) {
            return null;
        }

        return $this->buildMediaItem($thumb_id);
    }

    /**
     * Collect every image the post references: featured image, top-level ACF
     * image/gallery/file fields, and images embedded in the content.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMediaManifest(WP_Post $post): array
    {
        $items = [];

        $thumb_id = (int) get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $this->addManifestItem($items, $this->buildMediaItem($thumb_id));
        }

        foreach ($this->collectAcfAttachmentIds($post->ID) as $att_id) {
            $this->addManifestItem($items, $this->buildMediaItem($att_id));
        }

        foreach ($this->collectContentImages($post->post_content) as $item) {
            $this->addManifestItem($items, $item);
        }

        $manifest = array_values(array_filter($items));

        return apply_filters('wphaven_content_media_manifest', $manifest, $post);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>|null        $item
     */
    private function addManifestItem(array &$items, ?array $item): void
    {
        if ($item === null || empty($item['source_url'])) {
            return;
        }
        $items[$item['source_url']] = $item;
    }

    /**
     * Build a manifest entry from a known attachment id.
     *
     * @return array<string, mixed>|null
     */
    private function buildMediaItem(int $attachment_id): ?array
    {
        $url = wp_get_attachment_url($attachment_id);
        if (! $url) {
            return null;
        }

        return [
            'source_attachment_id' => $attachment_id,
            'source_url'           => $url,
            'relative_path'        => MediaUrl::relativePath($url),
            'origin'               => MediaUrl::classifyOrigin($url),
            'filename'             => wp_basename(get_attached_file($attachment_id) ?: $url),
            'mime'                 => get_post_mime_type($attachment_id) ?: '',
            'alt'                  => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        ];
    }

    /**
     * Attachment ids referenced by top-level ACF image/gallery/file fields.
     * Nested (repeater/flexible/clone) fields are out of scope for v1.
     *
     * @return array<int, int>
     */
    private function collectAcfAttachmentIds(int $post_id): array
    {
        if (! function_exists('get_field_objects')) {
            return [];
        }

        $fields = get_field_objects($post_id, false);
        if (! is_array($fields)) {
            return [];
        }

        $ids = [];
        foreach ($fields as $field) {
            if (! isset($field['type'], $field['value'])) {
                continue;
            }
            if (! in_array($field['type'], ['image', 'file'], true) && $field['type'] !== 'gallery') {
                continue;
            }
            foreach ((array) $field['value'] as $value) {
                $id = $this->attachmentIdFromAcfValue($value);
                if ($id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * An ACF image/file value can be an id, a URL, or an array depending on the
     * field's return format. Resolve it to an attachment id where possible.
     *
     * @param mixed $value
     */
    private function attachmentIdFromAcfValue($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_array($value) && isset($value['ID'])) {
            return (int) $value['ID'];
        }
        if (is_array($value) && isset($value['id'])) {
            return (int) $value['id'];
        }
        if (is_string($value)) {
            return (int) attachment_url_to_postid($value);
        }

        return 0;
    }

    /**
     * Images embedded directly in post content. Attachment-backed images carry a
     * `wp-image-{id}` class; bare `<img>` tags contribute a URL-only entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectContentImages(string $content): array
    {
        $items = [];

        if (! preg_match_all('/<img\b[^>]*>/i', $content, $tags)) {
            return $items;
        }

        foreach ($tags[0] as $tag) {
            if (! preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $src_match)) {
                continue;
            }
            $url = html_entity_decode($src_match[1]);

            $attachment_id = 0;
            if (preg_match('/wp-image-(\d+)/', $tag, $id_match)) {
                $attachment_id = (int) $id_match[1];
            }

            if ($attachment_id) {
                $item = $this->buildMediaItem($attachment_id);
                if ($item) {
                    $items[] = $item;
                    continue;
                }
            }

            $items[] = [
                'source_attachment_id' => 0,
                'source_url'           => $url,
                'relative_path'        => MediaUrl::relativePath($url),
                'origin'               => MediaUrl::classifyOrigin($url),
                'filename'             => wp_basename(wp_parse_url($url, PHP_URL_PATH) ?: $url),
                'mime'                 => '',
                'alt'                  => '',
            ];
        }

        return $items;
    }

    /**
     * Integrity checksum over the payload (excluding the checksum field itself).
     * This is not authentication -- the shared secret authenticates the request.
     *
     * @param array<string, mixed> $payload
     */
    public static function checksum(array $payload): string
    {
        unset($payload['checksum']);
        $canonical = self::canonicalize($payload);

        return hash('sha256', (string) wp_json_encode($canonical));
    }

    /**
     * Recursively sort array keys so encoding is stable regardless of insertion
     * order.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }

        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
