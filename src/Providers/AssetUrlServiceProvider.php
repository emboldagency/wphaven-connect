<?php

namespace WPHavenConnect\Providers;

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
        if (defined('ASSET_URL')) {
            // Check if the local file exists
            if (! $this->file_exists_locally($url)) {
                $upload_dir = wp_upload_dir();
                $uploads_baseurl = $upload_dir['baseurl'];

                // Determine the base directory relative to the site URL
                $relative_path = str_replace(site_url(), '', $uploads_baseurl);

                // Extract the relative path from the full URL
                $relative_path_from_url = str_replace($uploads_baseurl, $relative_path, $url);

                // Determine the base URL from ASSET_URL
                $asset_baseurl = rtrim(ASSET_URL, '/');

                // Reconstruct the URL with ASSET_URL and relative path
                $url = $asset_baseurl.$relative_path_from_url;
            }
        }

        return $url;
    }

    public function custom_asset_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (defined('ASSET_URL')) {
            $upload_dir = wp_upload_dir();
            $uploads_baseurl = $upload_dir['baseurl'];

            // Determine the base directory relative to the site URL
            $relative_path = str_replace(site_url(), '', $uploads_baseurl);

            foreach ($sources as &$source) {
                // Check if the local file exists
                if (! $this->file_exists_locally($source['url'])) {
                    // Extract the relative path from the full URL
                    $relative_path_from_url = str_replace($uploads_baseurl, $relative_path, $source['url']);

                    // Determine the base URL from ASSET_URL
                    $asset_baseurl = rtrim(ASSET_URL, '/');

                    // Reconstruct the URL with ASSET_URL and relative path
                    $source['url'] = $asset_baseurl.$relative_path_from_url;
                }
            }
        }

        return $sources;
    }

    public function custom_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr)
    {
        if (defined('ASSET_URL')) {
            // Check if the local file exists
            if (! $this->file_exists_locally($html)) {
                $upload_dir = wp_upload_dir();
                $uploads_baseurl = $upload_dir['baseurl'];

                // Determine the base directory relative to the site URL
                $relative_path = str_replace(site_url(), '', $uploads_baseurl);

                // Replace the URL in the src attribute
                $relative_path_from_url = str_replace($uploads_baseurl, $relative_path, $html);
                $asset_baseurl = rtrim(ASSET_URL, '/');
                $html = str_replace($uploads_baseurl, $asset_baseurl, $relative_path_from_url);
            }
        }

        return $html;
    }

    private function file_exists_locally($url)
    {
        $upload_dir = wp_upload_dir();
        $uploads_baseurl = $upload_dir['baseurl'];

        $uploads_directory = str_replace(site_url(), '', $upload_dir['basedir']);

        $file_path = str_replace($uploads_baseurl, '', $url);

        $full_file_path = $uploads_directory.$file_path;

        return file_exists($full_file_path);
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
