<?php

namespace WPHavenConnect\ContentTransfer;

use WP_Error;

/**
 * Shared permission callback for the cross-environment transfer REST routes
 * (both content and database). Authenticates purely against the environment
 * connection secret — deliberately NOT the plugin's general
 * ServiceProvider::apiPermissionsCheck, whose ?debug bypass and IP allowlist are
 * unacceptable on routes that overwrite another environment's data.
 */
class TransferAuth
{
    /**
     * REST permission callback: require a Bearer token matching the connection
     * secret.
     *
     * @return true|WP_Error
     */
    public static function permissionCheck()
    {
        if (ConnectionSecret::get() === null) {
            return new WP_Error('wphaven_transfer_disabled', __('Transfer is not configured on this site.', 'wphaven-connect'), ['status' => 403]);
        }

        if (ConnectionSecret::matches(self::bearerToken())) {
            return true;
        }

        return new WP_Error('wphaven_transfer_forbidden', __('Invalid environment connection secret.', 'wphaven-connect'), ['status' => 401]);
    }

    /**
     * Extract the Bearer token, accounting for servers that relocate or strip the
     * Authorization header.
     */
    public static function bearerToken(): ?string
    {
        $header = '';

        if (! empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }

        return null;
    }
}
