<?php

namespace WPHavenConnect\Providers;

use Throwable;

/**
 * Page builders (Breakdance, Elementor) cache generated CSS as files in
 * uploads and record those files in post meta and options. A database or
 * content transfer copies the records but not the files, so pages load
 * unstyled until someone regenerates the CSS by hand. Clearing the records
 * after a transfer makes each builder rebuild its CSS on the next page view.
 */
class BuilderCssCacheProvider
{
    /** Tables whose arrival brings in another environment's builder CSS records. */
    const TABLES = ['options', 'postmeta'];

    /** Per-post CSS records; each builder regenerates them when missing. */
    const POST_META_KEYS = [
        '_breakdance_css_file_paths_cache',
        '_breakdance_dependency_cache',
        '_elementor_css',
    ];

    public function register()
    {
        add_action('wphaven_db_table_swapped', [$this, 'afterTableSwap']);
        add_action('wphaven_content_import_after', [$this, 'afterContentImport']);
    }

    public function afterTableSwap($base): void
    {
        if (in_array($base, self::TABLES, true)) {
            $this->clearAll();
        }
    }

    public function afterContentImport($post_id): void
    {
        foreach (self::POST_META_KEYS as $key) {
            delete_post_meta((int) $post_id, $key);
        }
    }

    public function clearAll(): void
    {
        try {
            if (function_exists('\Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles')) {
                \Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles();
            }

            if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        } catch (Throwable $e) {
            // A stale CSS cache is fixable by hand; a failed transfer isn't.
            error_log('WPHaven Connect: could not clear page builder CSS cache: '.$e->getMessage());
        }
    }
}
