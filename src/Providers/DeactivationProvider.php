<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Utilities\MuPluginManager;

/**
 * Handles plugin deactivation and cleanup
 */
class DeactivationProvider
{
    /**
     * Register deactivation hook
     * Called when plugin is deactivated via WordPress dashboard
     */
    public static function register(): void
    {
        register_deactivation_hook(
            dirname(__DIR__, 2) . '/wphaven.php',
            [self::class, 'onDeactivation']
        );
    }

    /**
     * Handle plugin deactivation
     * Cleans up the MU-plugin file when the plugin is deactivated
     */
    public static function onDeactivation(): void
    {
        // Delete the MU-plugin to prevent it from running after deactivation
        // Only log if we actually deleted something
        $existed = MuPluginManager::muPluginExists();
        if ($existed) {
            MuPluginManager::deleteMuPlugin();
        }
    }
}
