<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Utilities\Environment;

/**
 * Handles suppression of specific _doing_it_wrong notices (like _load_textdomain_just_in_time).
 * * Previously, this generated an MU-plugin to ensure it ran before all other plugins.
 * We have moved to a standard implementation to reduce complexity and file system dependencies.
 * This class now handles cleaning up that legacy MU-plugin if it exists.
 */
class TextdomainNoticeSuppressionProvider
{
    public function register()
    {
        // Housekeeping: Remove the old MU plugin file if it exists
        $this->cleanupLegacyMuPlugin();

        // Check if suppression is enabled
        $should_suppress = $this->shouldSuppressNotices();

        if ($should_suppress) {
            $this->applySuppressionFilters();
        }

        // Dev Helper: Log message if in dev but suppression is off
        if (Environment::is_development() && !$should_suppress) {
            error_log('[WPHavenConnect] To suppress _load_textdomain_just_in_time notices in development, add this to wp-config.php: define(\'WPH_SUPPRESS_NOTICES\', true);');
        }
    }

    /**
     * Determines if notices should be suppressed based on constants, options, or environment.
     */
    private function shouldSuppressNotices(): bool
    {
        // Constant takes highest priority
        if (defined('WPH_SUPPRESS_NOTICES')) {
            return (bool) WPH_SUPPRESS_NOTICES;
        }

        // Check Options
        $opts = get_option('wphaven_connect_options', []);
        if (isset($opts['suppress_notices'])) {
            return (bool) $opts['suppress_notices'];
        }

        // Default to true in development environments if no option/constant set
        return Environment::is_development();
    }

    /**
     * Applies the actual filters to WordPress to suppress the notices.
     */
    private function applySuppressionFilters(): void
    {
        // Default strings to suppress
        $strings_to_check = [
            '_load_textdomain_just_in_time',
            'Translation loading',
        ];

        // Merge in custom strings from database settings
        $options = get_option('wphaven_connect_options', []);
        if (!empty($options['suppress_notice_extra_strings'])) {
            $custom_strings = preg_split('/[\r\n]+/', $options['suppress_notice_extra_strings']);
            if (is_array($custom_strings)) {
                // array_map('trim') cleans up whitespace from the split
                $strings_to_check = array_merge($strings_to_check, array_filter(array_map('trim', $custom_strings)));
            }
        }

        // Apply the filter
        add_filter('doing_it_wrong_trigger_error', function ($trigger, $function_name, $message, $version) use ($strings_to_check) {
            foreach ($strings_to_check as $s) {
                if (empty($s)) {
                    continue;
                }

                // Check for exact function name match OR partial message match
                if ($function_name === $s || strpos($message, $s) !== false) {
                    return false; // Return false to suppress the error trigger
                }
            }
            return $trigger;
        }, 10, 4);
    }

    /**
     * Detects and removes the legacy auto-generated MU plugin file.
     */
    private function cleanupLegacyMuPlugin(): void
    {
        $mu_plugin_path = WPMU_PLUGIN_DIR . '/suppress-textdomain-notices.php';

        if (file_exists($mu_plugin_path)) {
            // Security check: read file to ensure it's ours before deleting
            $content = file_get_contents($mu_plugin_path);
            if ($content && strpos($content, 'WPHaven Connect') !== false) {
                if (@unlink($mu_plugin_path)) {
                    error_log('[WPHavenConnect] Cleaned up legacy MU plugin: ' . $mu_plugin_path);
                } else {
                    error_log('[WPHavenConnect] Failed to delete legacy MU plugin (permissions?): ' . $mu_plugin_path);
                }
            }
        }
    }
}