<?php

namespace WPHavenConnect\Providers;

/**
 * Provides functionality to obscure and secure the WordPress admin login URL.
 */
class CustomAdminLoginProvider
{
    private $custom_login_slug;
    private $serve_custom_login = false;
    private $request_intercepted = false;

    public function register()
    {
        // Use 'init' with high priority (0) to ensure we run before WP rewrites/query parsing
        add_action('init', [$this, 'init_security'], 0);

        // Also hook early into muplugins_loaded for the specific admin block logic if possible
        add_action('muplugins_loaded', [$this, 'early_admin_block'], 0);
    }

    public function init_security()
    {
        // Prevent running twice if called manually or via multiple hooks
        if (did_action('wph_custom_login_init')) {
            return;
        }
        do_action('wph_custom_login_init');

        $slug = $this->get_custom_login_slug();

        // If no slug is defined, disable the feature
        if (empty($slug)) {
            return;
        }

        $this->custom_login_slug = trim($slug, '/');

        // Hook interception logic on init (safe timing)
        add_action('init', [$this, 'intercept_requests'], 1);

        // Add wp_loaded hook for actually serving the page (exit point)
        add_action('wp_loaded', [$this, 'wp_loaded_handler'], 0);

        // Remove the default admin location redirect
        remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);

        // Ensure redirects are removed in other contexts
        add_action('login_init', function () {
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
        });

        // Early admin block
        $this->early_admin_block();

        // Filter URL generation
        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
        add_filter('site_url', [$this, 'filter_site_url'], 10, 4);
        add_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10, 2);

        // Hide Admin Bar for non-logged in users attempting to access admin
        if (!is_user_logged_in() && is_admin()) {
            add_filter('show_admin_bar', '__return_false');
        }
    }

    /**
     * Intercept requests to handle custom login slug and block default paths
     */
    public function intercept_requests()
    {
        if ($this->request_intercepted || !$this->custom_login_slug) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($request_uri);
        $path = $parsed_url['path'] ?? '';
        $path = untrailingslashit($path);

        // Check if accessing custom login slug - serve wp-login.php
        if ($this->is_custom_login_request($path)) {
            $this->serve_custom_login = true;
            $this->request_intercepted = true;
            return;
        }

        // Check if accessing wp-login.php direct - redirect to 404
        if ($this->is_wp_login_request($path)) {
            // Allow ALL reset actions to pass through - both initial form and key verification
            $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
            $is_reset_flow = in_array($action, ['rp', 'resetpass', 'lostpassword', 'retrievepassword'], true);

            // CRITICAL: If we are in a valid reset flow, allow it unconditionally.
            // This includes:
            // - Initial reset form request (action=rp or resetpass without key/login)
            // - Reset link verification (action=resetpass with key and login)
            // - Lost password form (action=lostpassword)
            if ($is_reset_flow) {
                return;
            }

            if (!$this->is_login_allowed()) {
                $this->request_intercepted = true;
                $this->force_404_redirect();
                exit;
            }
        }

        // Check if accessing wp-admin - redirect to 404 if not logged in
        if (preg_match('#^/wp-admin(/|$|\?)#', $path)) {
            // ALLOW ASSETS: CSS, JS, Images. 
            if (preg_match('/\.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
                return;
            }

            // Allow AJAX
            if (preg_match('#^/wp-admin/(admin-ajax|async-upload|admin-post)\.php#', $path)) {
                return;
            }

            if (!is_user_logged_in()) {
                $this->request_intercepted = true;
                $this->force_404_redirect();
                exit;
            }
        }

        // Block wp-register.php access - redirect to 404
        if ($this->is_wp_register_request($path)) {
            $this->request_intercepted = true;
            $this->force_404_redirect();
            exit;
        }
    }

    /**
     * Helper to force a Consistent 404 via Redirect
     * This is the safest way to ensure the theme's 404 template loads correctly.
     */
    private function force_404_redirect()
    {
        if (!headers_sent()) {
            $opts = get_option('wphaven_connect_options', []);
            $redirect_path = !empty($opts['wphaven_404_redirect']) ? $opts['wphaven_404_redirect'] : '/404';
            
            wp_safe_redirect(home_url($redirect_path));
            exit;
        }
    }

    /**
     * Handle wp_loaded hook - serves the custom login page
     */
    public function wp_loaded_handler()
    {
        if ($this->serve_custom_login) {
            $this->serve_login_page();
            exit;
        }
    }

    /**
     * Early blocking to prevent redirects and direct access
     */
    public function early_admin_block()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH);

        // Check if this is a wp-admin request
        if (preg_match('#^/wp-admin(/|$|\?)#', $request_uri)) {

            // Allow assets even in early block
            if (preg_match('/\.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
                return;
            }

            // Allow AJAX
            if (preg_match('#^/wp-admin/(admin-ajax|async-upload|admin-post)\.php#', $request_uri)) {
                return;
            }

            // Disable admin bar early
            add_filter('show_admin_bar', '__return_false', 99);

            // Remove login filters to prevent canonical redirects revealing the login URL
            remove_filter('login_url', [$this, 'filter_login_url'], 10);
            remove_filter('site_url', [$this, 'filter_site_url'], 10);
            remove_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10);

            // Add a global redirect blocker for wp-admin for unauth users
            add_filter('wp_redirect', function ($location, $status) {
                $current_request = $_SERVER['REQUEST_URI'] ?? '';

                // Only intercept wp-admin redirects
                if (preg_match('#^/wp-admin(/|$|\?)#', $current_request)) {
                    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                        return $location;
                    }

                    // Force redirect to a known non-existent page to trigger theme 404
                    $opts = get_option('wphaven_connect_options', []);
                    $redirect_path = !empty($opts['wphaven_404_redirect']) ? $opts['wphaven_404_redirect'] : '/404';
                    return home_url($redirect_path);
                }
                return $location;
            }, 1, 2);
        }
    }

    private function get_custom_login_slug()
    {
        // Check Constant (Highest priority)
        if (defined('WPH_ADMIN_LOGIN_SLUG')) {
            return constant('WPH_ADMIN_LOGIN_SLUG');
        }

        // Check Database Options
        $opts = get_option('wphaven_connect_options', []);
        if (!empty($opts['admin_login_slug'])) {
            return $opts['admin_login_slug'];
        }

        // Fallback default
        return '';
    }

    /**
     * Check if current request is for custom login slug
     */
    private function is_custom_login_request($path)
    {
        $slug = preg_quote($this->custom_login_slug, '#');
        // Match exact slug or slug/
        return (bool) preg_match('#^/' . $slug . '(/|$)#', $path) ||
            // Also match if WP is in subdirectory e.g. /wp/slug
            (bool) preg_match('#/' . $slug . '(/|$)#', $path);
    }

    private function is_wp_login_request($path)
    {
        return strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false ||
            $path === '/wp-login';
    }

    private function is_wp_register_request($path)
    {
        return strpos($_SERVER['REQUEST_URI'], 'wp-register.php') !== false ||
            $path === '/wp-register';
    }

    private function is_login_allowed()
    {
        // Allow magic login, logout, interim login, already logged-in
        if (
            (isset($_GET['magic_login']) && !empty($_GET['magic_login'])) ||
            (isset($_GET['action']) && $_GET['action'] === 'logout') ||
            (isset($_GET['interim-login']) && $_GET['interim-login'] == 1) ||
            is_user_logged_in()
        ) {
            return true;
        }

        // Allow password reset flows explicitly
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        if (in_array($action, ['lostpassword', 'retrievepassword', 'rp', 'resetpass'], true)) {
            // For 'rp' and 'resetpass' ensure key+login are passed through
            if (in_array($action, ['rp', 'resetpass'], true)) {
                if (!empty($_GET['key']) && !empty($_GET['login'])) {
                    return true;
                }
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Serve the WordPress login page for custom slug
     */
    private function serve_login_page()
    {
        global $pagenow, $error, $interim_login, $action, $user_login;

        // Initialize variables that wp-login.php expects
        $error = '';
        $interim_login = false;
        $action = $_REQUEST['action'] ?? 'login';
        $user_login = '';
        $user = null;
        $errors = new \WP_Error();

        // Export variables to GLOBAL scope for wp-login.php
        $GLOBALS['error'] = $error;
        $GLOBALS['interim_login'] = $interim_login;
        $GLOBALS['action'] = $action;
        $GLOBALS['user_login'] = $user_login;
        $GLOBALS['user'] = $user;
        $GLOBALS['errors'] = $errors;

        // Preserve original query string
        $original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($original_request_uri);
        $query_string = $parsed_url['query'] ?? '';

        // Trick WordPress into thinking we are at wp-login.php
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_SERVER['REQUEST_URI'] = '/wp-login.php' . ($query_string ? '?' . $query_string : '');

        // Define this constant to help compatibility with some plugins
        if (!defined('WPH_CUSTOM_LOGIN_ACTIVE')) {
            define('WPH_CUSTOM_LOGIN_ACTIVE', true);
        }

        // Include and execute wp-login.php
        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    // URL Filters -----------------------------------------------------------

    public function filter_login_url($login_url, $redirect, $force_reauth)
    {
        $current_request = $_SERVER['REQUEST_URI'] ?? '';

        // Don't filter if we are actually ON the login page (prevents loops)
        if ($this->is_custom_login_request(parse_url($current_request, PHP_URL_PATH))) {
            return $login_url;
        }

        // Use custom slug
        $url = home_url('/' . $this->custom_login_slug . '/');

        if (!empty($redirect)) {
            $url = add_query_arg('redirect_to', urlencode($redirect), $url);
        }

        if ($force_reauth) {
            $url = add_query_arg('reauth', '1', $url);
        }

        return $url;
    }

    public function filter_site_url($url, $path, $scheme, $blog_id)
    {
        if (strpos($url, 'wp-login.php') !== false) {
            global $pagenow;

            // If the URL is for password reset, return it as-is (allow raw wp-login.php)
            // This prevents loops where we rewrite reset links back to the custom slug, 
            // which then redirects back to wp-login.php for processing, ad infinitum.
            if (strpos($url, 'action=rp') !== false || strpos($url, 'action=resetpass') !== false || strpos($url, 'action=lostpassword') !== false || strpos($url, 'action=retrievepassword') !== false) {
                return $url;
            }

            if ($pagenow === 'wp-login.php' || (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] === '/wp-login.php')) {
                $parsed_url = parse_url($url);
                $query_string = $parsed_url['query'] ?? '';
                $new_url = home_url('/' . $this->custom_login_slug . '/');
                if ($query_string) {
                    $new_url .= '?' . $query_string;
                }
                return $new_url;
            }

            // Rewrite general links to wp-login.php
            return str_replace('wp-login.php', $this->custom_login_slug . '/', $url);
        }

        return $url;
    }

    public function filter_wp_redirect($location, $status)
    {
        if (strpos($location, 'wp-login.php') !== false) {

            // Check for password reset actions in the CURRENT REQUEST
            // If we are currently processing a reset flow, DO NOT rewrite redirects.
            // Let WordPress behave natively to avoid loops and parameter loss.
            $current_action = $_GET['action'] ?? '';
            if (in_array($current_action, ['rp', 'resetpass', 'lostpassword', 'retrievepassword'])) {
                return $location;
            }

            return str_replace('wp-login.php', $this->custom_login_slug . '/', $location);
        }
        return $location;
    }
}
