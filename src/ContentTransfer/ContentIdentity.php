<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;

/**
 * Resolves the cross-environment identity of a post.
 *
 * WordPress post IDs are not stable across sites, so a shared UUID stored in the
 * `_wphaven_content_id` postmeta is the canonical key. The UUID is minted the
 * first time a post is transferred (in either direction) and persisted on both
 * the source and destination so subsequent transfers re-link the same records.
 */
class ContentIdentity
{
    const META_KEY = '_wphaven_content_id';

    /**
     * Return the post's content id, minting and persisting one if absent.
     */
    public static function ensure(int $post_id): string
    {
        $existing = self::get($post_id);
        if ($existing !== null) {
            return $existing;
        }

        $uuid = wp_generate_uuid4();
        update_post_meta($post_id, self::META_KEY, $uuid);

        return $uuid;
    }

    /**
     * Return the post's content id, or null if it has never been transferred.
     */
    public static function get(int $post_id): ?string
    {
        $uuid = get_post_meta($post_id, self::META_KEY, true);

        return (is_string($uuid) && $uuid !== '') ? $uuid : null;
    }

    /**
     * Persist a content id onto a post (used when a pull lands a remote post
     * locally for the first time).
     */
    public static function assign(int $post_id, string $uuid): void
    {
        update_post_meta($post_id, self::META_KEY, $uuid);
    }

    /**
     * Locate the local post matching a content id.
     *
     * Returns the post ID on a single match, null when there is no match (the
     * caller should create a new post), or a WP_Error when more than one local
     * post carries the same id -- an ambiguous state we refuse to guess at
     * rather than overwrite the wrong record.
     *
     * @return int|WP_Error|null
     */
    public static function findLocalPost(string $uuid)
    {
        $matches = get_posts([
            'post_type'        => 'any',
            'post_status'      => 'any',
            'meta_key'         => self::META_KEY,
            'meta_value'       => $uuid,
            'posts_per_page'   => 2,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]);

        // 'any' post_status excludes auto-draft/trash inconsistently across
        // versions and never includes revisions, but be explicit about it.
        $matches = array_values(array_filter($matches, static function ($id) {
            $type = get_post_type($id);
            return $type !== 'revision';
        }));

        if (count($matches) > 1) {
            return new WP_Error(
                'wphaven_content_id_conflict',
                sprintf(
                    /* translators: %s: content id */
                    __('Multiple local posts share content id %s. Resolve the duplicate before transferring.', 'wphaven-connect'),
                    $uuid
                ),
                ['status' => 409, 'post_ids' => $matches]
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * Find an existing, not-yet-linked local post that is clearly "the same" as
     * the incoming one, so a first transfer can adopt it instead of creating a
     * duplicate (the fresh-install-of-clones case).
     *
     * Guarded to stay safe: the candidate must be the SAME post type (so a page
     * can never adopt a product), must not already carry a content id (so we
     * never steal a post linked elsewhere), and — for the slug fallback — must
     * be the only such candidate. Prefers an exact post-ID match since cloned
     * environments share auto-increment IDs.
     */
    public static function findAdoptable(string $post_type, string $slug, int $source_post_id): ?int
    {
        if ($post_type === '') {
            return null;
        }

        // 1. Strongest signal: same numeric ID + same type + unlinked.
        if ($source_post_id > 0) {
            $post = get_post($source_post_id);
            if ($post && $post->post_type === $post_type && self::get($source_post_id) === null) {
                return (int) $post->ID;
            }
        }

        // 2. Fallback: exactly one unlinked post of this type with this slug.
        if ($slug !== '') {
            $matches = get_posts([
                'name'             => $slug,
                'post_type'        => $post_type,
                'post_status'      => ['publish', 'future', 'draft', 'pending', 'private'],
                'posts_per_page'   => 2,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
                'meta_query'       => [[
                    'key'     => self::META_KEY,
                    'compare' => 'NOT EXISTS',
                ]],
            ]);
            if (count($matches) === 1) {
                return (int) $matches[0];
            }
        }

        return null;
    }

    /**
     * A read-only "possible match" hint for first-time linking, surfaced only in
     * the preview UI. Never used to pick an automatic write target because slugs
     * mutate and are not unique across post types.
     */
    public static function suggestBySlug(string $slug, string $post_type): ?int
    {
        if ($slug === '') {
            return null;
        }

        $matches = get_posts([
            'name'             => $slug,
            'post_type'        => $post_type,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]);

        return $matches[0] ?? null;
    }
}
