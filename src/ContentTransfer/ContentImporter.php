<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;
use WP_Post;
use WPHavenConnect\Utilities\Environment;

/**
 * Applies a transfer envelope to the local site: create-or-update the post,
 * remap and attach media, copy meta (remapping ACF attachment ids), assign
 * terms, resolve parent and author.
 *
 * Safety is layered because this overwrites potentially-live content:
 *  - new posts land as draft; updates keep their current status unless an
 *    explicit publish flag is passed;
 *  - the target's meta and terms are snapshotted before being overwritten;
 *  - a conflict (target modified more recently than the incoming version) aborts
 *    unless the caller confirms the overwrite (the editor previews first).
 */
class ContentImporter
{
    const BACKUP_META = '_wphaven_pre_transfer_backup';

    private ContentSerializer $serializer;

    public function __construct(?ContentSerializer $serializer = null)
    {
        $this->serializer = $serializer ?: new ContentSerializer();
    }

    /**
     * Dry-run: compute what an import would change without writing anything.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|WP_Error
     */
    public function preview(array $envelope)
    {
        $validation = $this->validate($envelope);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $target = ContentIdentity::findLocalPost($envelope['content_id']);
        if (is_wp_error($target)) {
            return $target;
        }

        $is_new  = $target === null;
        $post_in = $envelope['post'];

        $changed_meta = [];
        $existing = $is_new ? null : get_post($target);
        if (! $is_new) {
            foreach ((array) $envelope['meta'] as $key => $values) {
                $current = get_post_meta($target, $key);
                if (array_map('maybe_unserialize', $current) != $values) {
                    $changed_meta[] = $key;
                }
            }
        }

        return [
            'is_new'        => $is_new,
            'target_id'     => $is_new ? null : $target,
            'title'         => $post_in['post_title'] ?? '',
            'conflict'      => $this->detectConflict($envelope, $existing),
            'changed_meta'  => $changed_meta,
            'terms'         => array_map(static fn ($t) => $t['taxonomy'] . ':' . $t['slug'], (array) $envelope['terms']),
            'media_count'   => count((array) $envelope['media_manifest']),
            'slug_hint'     => $is_new
                ? ContentIdentity::suggestBySlug($post_in['post_name'] ?? '', $post_in['post_type'] ?? 'post')
                : null,
        ];
    }

    /**
     * Apply an envelope.
     *
     * @param array<string, mixed> $envelope
     * @param array{publish?: bool, overwrite_conflict?: bool} $args
     * @return array<string, mixed>|WP_Error
     */
    public function import(array $envelope, array $args = [])
    {
        $validation = $this->validate($envelope);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $publish            = ! empty($args['publish']);
        $overwrite_conflict = ! empty($args['overwrite_conflict']);

        $target = ContentIdentity::findLocalPost($envelope['content_id']);
        if (is_wp_error($target)) {
            return $target;
        }

        $is_new   = $target === null;
        $existing = $is_new ? null : get_post($target);

        if (! $is_new && ! $overwrite_conflict && $this->detectConflict($envelope, $existing)) {
            return new WP_Error(
                'wphaven_transfer_conflict',
                __('The target was modified more recently than this version. Confirm to overwrite.', 'wphaven-connect'),
                ['status' => 409]
            );
        }

        $media = new MediaSideloader($this->allowedHosts($envelope), Environment::is_production());
        $media->importManifest((array) $envelope['media_manifest']);

        $post_in = $envelope['post'];
        $status  = $this->resolveStatus($post_in['post_status'] ?? 'draft', $is_new, $publish, $existing);
        $content = $this->remapContent(
            (string) ($post_in['post_content'] ?? ''),
            $media->idMap(),
            $media->urlMap(),
            (string) ($envelope['source_site_url'] ?? '')
        );

        $postarr = [
            'post_title'   => $post_in['post_title'] ?? '',
            'post_content' => $content,
            'post_excerpt' => $post_in['post_excerpt'] ?? '',
            'post_status'  => $status,
            'post_type'    => $post_in['post_type'] ?? 'post',
            'post_name'    => $post_in['post_name'] ?? '',
            'menu_order'   => (int) ($post_in['menu_order'] ?? 0),
            'post_parent'  => $this->resolveParent($post_in['post_parent_content_id'] ?? null),
            'post_author'  => $this->resolveAuthor($post_in['author_email'] ?? null, $existing),
        ];

        if (! $is_new) {
            $postarr['ID'] = $target;
            $this->backup($target);
        }

        $post_id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        ContentIdentity::assign($post_id, $envelope['content_id']);
        $this->applyMeta($post_id, (array) $envelope['meta'], $media->idMap());
        $this->applyFeaturedImage($post_id, $envelope['featured_image'] ?? null, $media->idMap());
        $this->applyTerms($post_id, (array) $envelope['terms']);

        do_action('wphaven_content_import_after', $post_id, $envelope, $media);

        return [
            'post_id'  => $post_id,
            'is_new'   => $is_new,
            'status'   => $status,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'warnings' => $media->warnings(),
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return true|WP_Error
     */
    private function validate(array $envelope)
    {
        foreach (['content_id', 'post', 'meta', 'terms', 'media_manifest', 'checksum'] as $key) {
            if (! array_key_exists($key, $envelope)) {
                return new WP_Error('wphaven_envelope_invalid', __('Malformed transfer envelope.', 'wphaven-connect'), ['status' => 400]);
            }
        }

        if (! hash_equals((string) $envelope['checksum'], ContentSerializer::checksum($envelope))) {
            return new WP_Error('wphaven_checksum_mismatch', __('Transfer payload failed its integrity check.', 'wphaven-connect'), ['status' => 400]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function detectConflict(array $envelope, ?WP_Post $existing): bool
    {
        if (! $existing instanceof WP_Post) {
            return false;
        }

        $source_modified = strtotime((string) ($envelope['source_modified_gmt'] ?? '')) ?: 0;
        $target_modified = strtotime((string) $existing->post_modified_gmt) ?: 0;

        return $target_modified > $source_modified;
    }

    private function resolveStatus(string $source_status, bool $is_new, bool $publish, ?WP_Post $existing): string
    {
        if ($publish) {
            return $source_status;
        }
        if ($is_new) {
            return 'draft';
        }

        return $existing instanceof WP_Post ? $existing->post_status : 'draft';
    }

    private function resolveParent(?string $parent_content_id): int
    {
        if (! $parent_content_id) {
            return 0;
        }

        $parent = ContentIdentity::findLocalPost($parent_content_id);

        return (is_int($parent)) ? $parent : 0;
    }

    private function resolveAuthor(?string $email, ?WP_Post $existing): int
    {
        if ($email) {
            $user = get_user_by('email', $email);
            if ($user) {
                return (int) $user->ID;
            }
        }
        if ($existing instanceof WP_Post) {
            return (int) $existing->post_author;
        }

        return get_current_user_id();
    }

    /**
     * Snapshot the target's meta and terms so a transfer can be reverted. Only
     * the most recent snapshot is kept to avoid unbounded meta growth.
     */
    private function backup(int $post_id): void
    {
        $post = get_post($post_id);
        if (! $post instanceof WP_Post) {
            return;
        }

        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $slugs = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
            if (! is_wp_error($slugs)) {
                $terms[$taxonomy] = $slugs;
            }
        }

        update_post_meta($post_id, self::BACKUP_META, [
            'saved_at_gmt' => gmdate('Y-m-d\TH:i:s\Z'),
            'meta'         => get_post_meta($post_id),
            'terms'        => $terms,
        ]);
    }

    /**
     * @param array<string, array<int, mixed>> $meta
     * @param array<int, int>                   $id_map
     */
    private function applyMeta(int $post_id, array $meta, array $id_map): void
    {
        foreach (array_keys(get_post_meta($post_id)) as $key) {
            if (in_array($key, ContentSerializer::META_DENYLIST, true)) {
                continue;
            }
            if (array_key_exists($key, $meta)) {
                delete_post_meta($post_id, $key);
            }
        }

        foreach ($meta as $key => $values) {
            if (in_array($key, ContentSerializer::META_DENYLIST, true)) {
                continue;
            }
            foreach ((array) $values as $value) {
                add_post_meta($post_id, $key, wp_slash($this->remapMetaValue($value, $id_map)));
            }
        }
    }

    /**
     * Remap any meta value that is (or contains) a transferred attachment id.
     * Covers ACF image ids and gallery id arrays best-effort.
     *
     * @param mixed           $value
     * @param array<int, int> $id_map
     * @return mixed
     */
    private function remapMetaValue($value, array $id_map)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->remapMetaValue($v, $id_map);
            }
            return $out;
        }

        if (is_numeric($value) && isset($id_map[(int) $value])) {
            return $id_map[(int) $value];
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $featured
     * @param array<int, int>           $id_map
     */
    private function applyFeaturedImage(int $post_id, ?array $featured, array $id_map): void
    {
        if (! $featured) {
            return;
        }

        $source_id = (int) ($featured['source_attachment_id'] ?? 0);
        if ($source_id && isset($id_map[$source_id])) {
            set_post_thumbnail($post_id, $id_map[$source_id]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     */
    private function applyTerms(int $post_id, array $terms): void
    {
        $by_taxonomy = [];

        // Ensure parents exist before children, then collect slugs per taxonomy.
        usort($terms, static fn ($a, $b) => (empty($a['parent_slug']) ? 0 : 1) <=> (empty($b['parent_slug']) ? 0 : 1));

        foreach ($terms as $term) {
            $taxonomy = $term['taxonomy'] ?? '';
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            if (! term_exists($term['slug'], $taxonomy)) {
                $parent_id = 0;
                if (! empty($term['parent_slug'])) {
                    $parent = get_term_by('slug', $term['parent_slug'], $taxonomy);
                    $parent_id = $parent ? (int) $parent->term_id : 0;
                }
                wp_insert_term($term['name'], $taxonomy, [
                    'slug'        => $term['slug'],
                    'description' => $term['description'] ?? '',
                    'parent'      => $parent_id,
                ]);
            }

            $by_taxonomy[$taxonomy][] = $term['slug'];
        }

        foreach ($by_taxonomy as $taxonomy => $slugs) {
            wp_set_object_terms($post_id, $slugs, $taxonomy, false);
        }
    }

    /**
     * Rewrite media references in content to their local equivalents.
     *
     * @param array<int, int>      $id_map
     * @param array<string, string> $url_map
     */
    private function remapContent(string $content, array $id_map, array $url_map, string $source_site_url): string
    {
        foreach ($id_map as $old => $new) {
            $content = preg_replace('/wp-image-' . $old . '\b/', 'wp-image-' . $new, $content);
            $content = str_replace('"id":' . $old, '"id":' . $new, $content);
        }

        foreach ($url_map as $old_url => $new_url) {
            $content = str_replace($old_url, $new_url, $content);
        }

        // Domain rewrite for internal links and image subsizes, protecting
        // ASSET_URL-hosted media so production assets are not repointed.
        $token = '%%WPHAVEN_ASSET_URL%%';
        $asset = (defined('ASSET_URL') && ASSET_URL) ? rtrim(ASSET_URL, '/') : '';
        if ($asset) {
            $content = str_replace($asset, $token, $content);
        }

        $target = site_url();
        if ($source_site_url && untrailingslashit($source_site_url) !== untrailingslashit($target)) {
            $content = str_replace(untrailingslashit($source_site_url), untrailingslashit($target), $content);
            $content = str_replace(
                preg_replace('#^https?:#', '', untrailingslashit($source_site_url)),
                preg_replace('#^https?:#', '', untrailingslashit($target)),
                $content
            );
        }

        if ($asset) {
            $content = str_replace($token, $asset, $content);
        }

        return $content;
    }

    /**
     * Hosts the sideloader may fetch binaries from for this envelope.
     *
     * @param array<string, mixed> $envelope
     * @return string[]
     */
    private function allowedHosts(array $envelope): array
    {
        $hosts = [];

        $source = wp_parse_url((string) ($envelope['source_site_url'] ?? ''), PHP_URL_HOST);
        if ($source) {
            $hosts[] = $source;
        }

        if (defined('ASSET_URL') && ASSET_URL) {
            $asset = wp_parse_url(ASSET_URL, PHP_URL_HOST);
            if ($asset) {
                $hosts[] = $asset;
            }
        }

        $production = wp_parse_url(TransferClient::productionUrl() ?? '', PHP_URL_HOST);
        if ($production) {
            $hosts[] = $production;
        }

        return array_values(array_unique($hosts));
    }
}
