<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ContentTransfer\Environments;
use WPHavenConnect\DatabaseTransfer\SearchReplace;

/**
 * "Live Domain Swapping on Saves": whenever a post, page, product or custom post
 * type is saved, rewrite any *other* environment's URL found in the content and
 * ACF fields to this site's URL.
 *
 * The motivating case: a dev copy/pastes a page between environments and the
 * block editor ends up with the source environment's URLs baked in. On save we
 * normalise them to the current site. ASSET_URL-hosted media is shielded (see
 * SearchReplace), so production-served images are never repointed.
 *
 * Controlled by the `live_domain_swap` option (on by default).
 */
class LiveDomainSwapProvider
{
    const OPTION_KEY = 'live_domain_swap';

    private ?SearchReplace $swapper = null;

    private bool $swapperBuilt = false;

    public function register()
    {
        if (! $this->enabled()) {
            return;
        }

        add_filter('wp_insert_post_data', [$this, 'filterPostData'], 20, 1);

        // ACF stores fields via its own pipeline; this catches product/CPT/page
        // custom fields (text, wysiwyg, url, repeaters, etc.) as they save.
        add_filter('acf/update_value', [$this, 'filterAcfValue'], 20, 1);
    }

    private function enabled(): bool
    {
        $opts = get_option('wphaven_connect_options', []);

        // Default on when the option has never been set.
        return ! is_array($opts) || ! array_key_exists(self::OPTION_KEY, $opts) || ! empty($opts[self::OPTION_KEY]);
    }

    /**
     * The swapper rewrites every configured environment URL to this site's URL
     * (the current site is skipped automatically). Null when there is nothing to
     * swap.
     */
    private function swapper(): ?SearchReplace
    {
        if (! $this->swapperBuilt) {
            $this->swapperBuilt = true;
            $froms = Environments::allUrls();
            if (! empty($froms)) {
                $candidate = new SearchReplace($froms, site_url());
                $this->swapper = $candidate->hasWork() ? $candidate : null;
            }
        }

        return $this->swapper;
    }

    /**
     * Rewrite URLs in the post content/excerpt/title before it is written.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterPostData(array $data): array
    {
        $swapper = $this->swapper();
        if ($swapper === null) {
            return $data;
        }
        if (! empty($data['post_type']) && $data['post_type'] === 'revision') {
            return $data;
        }

        foreach (['post_content', 'post_excerpt', 'post_title'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                // $data is slashed; domains carry no backslashes, so replacing in
                // place preserves the surrounding escaping.
                $data[$key] = $swapper->replace($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Rewrite URLs inside an ACF field value (scalar or nested array).
     *
     * @param mixed $value
     * @return mixed
     */
    public function filterAcfValue($value)
    {
        $swapper = $this->swapper();
        if ($swapper === null) {
            return $value;
        }

        return $swapper->replace($value);
    }
}
