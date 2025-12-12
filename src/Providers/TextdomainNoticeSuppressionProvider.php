<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Utilities\MuPluginManager;

/**
 * Handles cleanup of the legacy Notice Suppression MU-plugin.
 * Feature has been migrated to Embold WordPress Tweaks v1.6.0+.
 */
class TextdomainNoticeSuppressionProvider
{
    public function register()
    {
        // We no longer suppress notices in this plugin.
        // We run this check to ensure the legacy MU-plugin is removed 
        // so it doesn't conflict or persist unnecessarily.
        if (MuPluginManager::muPluginExists()) {
            MuPluginManager::deleteMuPlugin();
        }
    }
}