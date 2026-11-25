<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ContentTransfer\MediaUrl;

class AssetUrlServiceProvider
{
    public function register()
    {
        add_filter('wp_get_attachment_url', [$this, 'custom_asset_url']);
        add_filter('wp_calculate_image_srcset', [$this, 'custom_asset_srcset'], 10, 5);
        add_filter('post_thumbnail_html', [$this, 'custom_post_thumbnail_html'], 10, 5);
        add_action('delete_attachment', [$this, 'prevent_production_asset_deletion']);
    }

    public function custom_asset_url($url)
    {
        return is_string($url) ? $this->to_asset_url($url) : $url;
    }

    public function custom_asset_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (! is_array($sources)) {
            return $sources;
        }

        foreach ($sources as &$source) {
            if (isset($source['url']) && is_string($source['url'])) {
                $source['url'] = $this->to_asset_url($source['url']);
            }
        }

        return $sources;
    }

    public function custom_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr)
    {
        if (! $this->asset_baseurl() || ! is_string($html) || $html === '') {
            return $html;
        }

        $uploads_path = $this->uploads_path();
        if ($uploads_path === '') {
            return $html;
        }

        $pattern = '#(?:https?:)?//[^\s"\'/]+'.preg_quote($uploads_path, '#').'/[^\s"\',]+#i';

        return preg_replace_callback($pattern, function ($matches) {
            return $this->to_asset_url($matches[0]);
        }, $html);
    }

    /**
     * Point a local uploads URL at ASSET_URL when the file is missing here.
     * Matches the uploads base by path, so an http/https mismatch between
     * siteurl and the request can't leave the full URL behind ASSET_URL.
     */
    private function to_asset_url(string $url): string
    {
        $asset_baseurl = $this->asset_baseurl();
        if (! $asset_baseurl || strpos($url, $asset_baseurl.'/') === 0) {
            return $url;
        }

        $relative = MediaUrl::relativePath($url);
        if ($relative === null || $relative === '' || $this->file_exists_locally($relative)) {
            return $url;
        }

        return $asset_baseurl.$this->uploads_path().'/'.$relative;
    }

    private function asset_baseurl(): string
    {
        return defined('ASSET_URL') && ASSET_URL ? rtrim((string) ASSET_URL, '/') : '';
    }

    private function uploads_path(): string
    {
        $upload_dir = wp_upload_dir();
        $path = wp_parse_url($upload_dir['baseurl'], PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    private function file_exists_locally(string $relative): bool
    {
        $upload_dir = wp_upload_dir();

        return file_exists(trailingslashit($upload_dir['basedir']).$relative);
    }

    public function prevent_production_asset_deletion($post_id)
    {
        if (defined('ASSET_URL')) {
            $file_url = wp_get_attachment_url($post_id);

            if (strpos($file_url, ASSET_URL) !== false) {
                // Prevent deletion by stopping the process and throwing an error message
                wp_die(
                    'Deletion of this asset is not allowed because it resides on the production server.',
                    'Error',
                    [
                        'response' => 403,
                        'back_link' => true,
                    ]
                );
            }
        }
    }
}
