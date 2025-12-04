<?php

namespace WPHavenConnect\Utilities;

/**
 * Manages MU-plugin creation and cleanup
 */
class MuPluginManager
{
    /**
     * Path to the MU-plugin file
     */
    const MU_PLUGIN_FILE = '00-suppress-textdomain-notices.php';

    /**
     * Get the full path to the MU-plugin
     */
    public static function getMuPluginPath(): string
    {
        return WPMU_PLUGIN_DIR . '/' . self::MU_PLUGIN_FILE;
    }

    /**
     * Check if MU-plugin file exists
     */
    public static function muPluginExists(): bool
    {
        return file_exists(self::getMuPluginPath());
    }

    /**
     * Delete the MU-plugin file
     */
    public static function deleteMuPlugin(): bool
    {
        $path = self::getMuPluginPath();
        
        if (!file_exists($path)) {
            return true; // Already doesn't exist
        }

        if (@unlink($path)) {
            error_log('[WPHavenConnect] MU-plugin cleaned up');
            return true;
        }

        error_log('[WPHavenConnect] Failed to delete MU-plugin: ' . $path);
        return false;
    }

    /**
     * Delete all WP Haven Connect settings and data
     */
    public static function deleteSettings(): void
    {
        delete_option('wphaven_connect_options');
        error_log('[WPHavenConnect] Deleted plugin settings from wp_options');
    }
}
