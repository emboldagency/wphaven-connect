<?php

namespace WPHavenConnect\Utilities;

class ElevatedUsers
{
    public static function getAllowedEmails(): array
    {
        $defaults = [
            'info@embold.com',
            'info@wphaven.app',
        ];

        // Merge in site option values
        $opts = get_option('wphaven_connect_options', []);
        if (!empty($opts['elevated_emails']) && is_array($opts['elevated_emails'])) {
            $defaults = array_merge($defaults, $opts['elevated_emails']);
        }

        // Merge in constant if provided
        if (defined('ELEVATED_EMAILS') && is_array(ELEVATED_EMAILS)) {
            $defaults = array_merge($defaults, ELEVATED_EMAILS);
        }

        // Sanitize, lowercase, and deduplicate
        $defaults = array_map('sanitize_email', $defaults);
        $defaults = array_filter($defaults, 'is_email');
        $defaults = array_map('strtolower', $defaults);

        $allowed = array_values(array_unique($defaults));

        return $allowed;
    }

    /**
     * Returns true if a custom elevated email list is configured (via option or constant).
     * This is used to enforce that only those emails can access certain features.
     */
    public static function hasCustomList(): bool
    {
        $opts = get_option('wphaven_connect_options', []);
        $has_option_list = !empty($opts['elevated_emails']) && is_array($opts['elevated_emails']) && count($opts['elevated_emails']) > 0;
        $has_const_list = defined('ELEVATED_EMAILS') && is_array(ELEVATED_EMAILS) && count(ELEVATED_EMAILS) > 0;
        return $has_option_list || $has_const_list;
    }

    public static function currentIsElevated(): bool
    {
        $user = wp_get_current_user();
        if (empty($user) || empty($user->user_email)) {
            return false;
        }

        $email = strtolower($user->user_email);
        $allowed = self::getAllowedEmails();

        $is_elevated = in_array($email, $allowed, true);

        return $is_elevated;
    }
}