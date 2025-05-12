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

        if (defined('ELEVATED_EMAILS') && is_array(ELEVATED_EMAILS)) {
            $defaults = array_merge($defaults, ELEVATED_EMAILS);
        }

        return $defaults;
    }

    public static function currentIsElevated(): bool
    {
        $user = wp_get_current_user();
        return in_array($user->user_email, self::getAllowedEmails(), true);
    }
}