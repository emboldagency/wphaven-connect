<?php

namespace WPHavenConnect\Providers;

use WP_REST_Response;
use WP_REST_Server;
use WPHavenConnect\ContentTransfer\AppName;

/**
 * Exposes GET /wphaven-connect/v1/app-name so the WP Haven browser extension can
 * identify a site whose hostname doesn't name it, like a dev copy in a workspace
 * named differently from the site.
 */
class AppNameServiceProvider
{
    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('wphaven-connect/v1', '/app-name', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getAppName'],
            // Public: the extension calls it from staff browsers on any network, and the
            // slug only names the site in WP Haven; it grants nothing.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * The configured app name only. A hostname-detected one adds nothing the
     * extension can't already work out from the URL.
     */
    public function getAppName(): WP_REST_Response
    {
        $appName = AppName::get();

        return new WP_REST_Response(['app_name' => $appName !== '' ? $appName : null], 200);
    }
}
