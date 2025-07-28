<?php

namespace WPHavenConnect\Providers;

/**
 * Provides functionality to obscure and secure the WordPress admin login URL.
 */
class CustomAdminLoginProvider
{
    private $custom_login_slug;
    private $wp_login_blocked = false;
    private $request_intercepted = false;
    private $serve_custom_login = false;
    private $handling_direct_admin = false;

    public function register()
    {
        // Call init_security immediately, then also hook it to ensure it runs
        $this->init_security();
        
        // Also hook into init as a backup
        add_action('init', [$this, 'init_security'], 0);
    }

    public function init_security()
    {
        
        if (!defined('WPH_ADMIN_LOGIN_SLUG') || empty(WPH_ADMIN_LOGIN_SLUG)) {
            return;
        }

        $this->custom_login_slug = trim(WPH_ADMIN_LOGIN_SLUG, '/');
        
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'N/A';
        
        // Early admin bar disabling for wp-admin requests
        if (preg_match('#^/wp-admin(/|$|\?)#', $request_uri)) {
            add_filter('show_admin_bar', '__return_false', 1);
            // Also set the global that WordPress uses
            global $show_admin_bar;
            $show_admin_bar = false;
        }
        
        // Hook into plugins_loaded like wps-hide-login does
        add_action('plugins_loaded', [$this, 'intercept_requests'], 9999);
        
        // Add wp_loaded hook for final processing
        add_action('wp_loaded', [$this, 'wp_loaded_handler'], 0);
        
        // Remove the default admin location redirect like wps-hide-login does
        remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
        
        // Remove other WordPress redirects
        add_action('init', function() {
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
            remove_action('login_init', 'wp_redirect_admin_locations');
            remove_action('wp_login', 'wp_redirect_admin_locations');
        }, 0);
        
        // Hook for blocking admin access very early
        add_action('muplugins_loaded', [$this, 'early_admin_block'], 0);
        add_action('init', [$this, 'early_admin_block'], 0);
        
        // Filter URL generation
        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
        add_filter('site_url', [$this, 'filter_site_url'], 10, 4);
        add_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10, 2);
        
    }

    /**
     * Intercept requests to handle custom login slug and block default paths
     */
    public function intercept_requests()
    {
        // Prevent multiple executions
        if ($this->request_intercepted) {
            return;
        }
        
        if (!$this->custom_login_slug) {
            return;
        }

        global $pagenow;
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($request_uri);
        $path = $parsed_url['path'] ?? '';
        $path = untrailingslashit($path);


        // Check if accessing wp-login.php - redirect to /404
        if ($this->is_wp_login_request($path)) {
            if (!$this->is_login_allowed()) {
                $this->request_intercepted = true;
                wp_redirect(home_url('/404/'), 302);
                exit;
            }
        }

        // Check if accessing wp-admin - redirect to /404 if not logged in
        if (preg_match('#^/wp-admin(/|$|\?)#', $path)) {
            if (!is_user_logged_in()) {
                $this->request_intercepted = true;
                wp_redirect(home_url('/404/'), 302);
                exit;
            }
        }

        // Check if accessing custom login slug - serve wp-login.php
        if ($this->is_custom_login_request($path)) {
            // Don't serve immediately during plugins_loaded, defer to wp_loaded
            $this->serve_custom_login = true;
            return;
        }

        // Block wp-register.php access - redirect to /404
        if ($this->is_wp_register_request($path)) {
            $this->request_intercepted = true;
            wp_redirect(home_url('/404/'), 302);
            exit;
        }
    }

    /**
     * Handle wp_loaded hook - simplified for serving custom login only
     */
    public function wp_loaded_handler()
    {
        if (!$this->custom_login_slug) {
            return;
        }

        // If we need to serve the custom login page, do it now
        if ($this->serve_custom_login) {
            $this->serve_login_page();
            exit;
        }
    }

    /**
     * Early blocking on init to prevent redirects
     */
    public function early_admin_block()
    {
        if (!$this->custom_login_slug) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check if this is a wp-admin request
        if (preg_match('#^/wp-admin(/|$|\?)#', $request_uri)) {
            // Disable admin bar early for wp-admin 404 pages
            add_filter('show_admin_bar', '__return_false', 1);
            
            // Set flag that we're handling a direct admin request  
            $this->handling_direct_admin = true;
            
            // Remove all login-related filters to prevent redirects
            remove_filter('login_url', [$this, 'filter_login_url'], 10);
            remove_filter('site_url', [$this, 'filter_site_url'], 10);
            remove_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10);
            
            // Add a global redirect blocker for wp-admin
            add_filter('wp_redirect', function($location, $status) {
                $current_request = $_SERVER['REQUEST_URI'] ?? '';
                if (preg_match('#^/wp-admin(/|$|\?)#', $current_request)) {
                    // Check if user is authenticated
                    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                        // Allow redirect for authenticated users
                        return $location;
                    }
                    // Block all redirects from wp-admin for unauthenticated users
                    // Set 404 status and show proper WordPress 404 page
                    if (!headers_sent()) {
                        status_header(404);
                        header('Cache-Control: no-cache, must-revalidate, max-age=0');
                        if (function_exists('nocache_headers')) {
                            nocache_headers();
                        }
                    }
                    
                    // Properly set up WordPress environment for 404 page
                    global $wp_query, $wp_the_query, $wp, $wp_styles, $wp_scripts;
                    
                    // Make sure WordPress is fully loaded
                    if (function_exists('wp') && !did_action('wp')) {
                        wp();
                    }
                    
                    // Initialize query if needed
                    if (!is_object($wp_query)) {
                        if (class_exists('WP_Query')) {
                            $wp_query = new WP_Query();
                        }
                    }
                    
                    // Set 404 state properly
                    if (is_object($wp_query)) {
                        if (method_exists($wp_query, 'set_404')) {
                            $wp_query->set_404();
                        }
                        $wp_query->is_404 = true;
                        $wp_query->is_home = false;
                        $wp_query->is_admin = false;
                        $wp_query->is_front_page = false;
                    }
                    
                    // Ensure theme is loaded properly
                    if (!function_exists('get_template_directory') && defined('ABSPATH')) {
                        require_once(ABSPATH . WPINC . '/theme.php');
                    }
                    
                    // Load template functions if not available
                    if (!function_exists('get_404_template') && defined('ABSPATH')) {
                        require_once(ABSPATH . WPINC . '/template.php');
                    }
                    
                    // Load script and style functions for proper enqueuing
                    if (!class_exists('WP_Scripts') && defined('ABSPATH')) {
                        require_once(ABSPATH . WPINC . '/class.wp-scripts.php');
                        require_once(ABSPATH . WPINC . '/script-loader.php');
                    }
                    if (!class_exists('WP_Styles') && defined('ABSPATH')) {
                        require_once(ABSPATH . WPINC . '/class.wp-styles.php');
                    }
                    
                    // Initialize scripts and styles if not already done
                    if (!isset($wp_scripts) || !is_object($wp_scripts)) {
                        $wp_scripts = new WP_Scripts();
                    }
                    if (!isset($wp_styles) || !is_object($wp_styles)) {
                        $wp_styles = new WP_Styles();
                    }
                    
                    // Set up proper template environment
                    global $posts, $post;
                    $posts = array();
                    $post = null;
                    
                    // Fire necessary actions for theme setup
                    if (!did_action('wp_head')) {
                        do_action('init');
                        do_action('wp_loaded');
                        do_action('template_redirect');
                    }
                    
                    // Try to get 404 template with proper WordPress context
                    $template_404 = null;
                    if (function_exists('get_404_template')) {
                        $template_404 = get_404_template();
                    } 
                    
                    // Fallback to manual template detection
                    if (empty($template_404) && function_exists('get_template_directory')) {
                        $template_dir = get_template_directory();
                        if (file_exists($template_dir . '/404.php')) {
                            $template_404 = $template_dir . '/404.php';
                        } else if (file_exists($template_dir . '/index.php')) {
                            $template_404 = $template_dir . '/index.php';
                        }
                    }
                    
                    if (!empty($template_404) && file_exists($template_404)) {
                        // Load the template with full WordPress environment
                        include $template_404;
                    } else {
                        // Fallback to styled 404 if template loading fails
                        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'Website';
                        $escaped_site_name = function_exists('esc_html') ? esc_html($site_name) : htmlspecialchars($site_name, ENT_QUOTES);
                        echo '<!DOCTYPE html><html><head><title>Page not found - ' . $escaped_site_name . '</title></head>';
                        echo '<body><h1>Pardon our dust! We\'re working on something amazing — check back soon!</h1></body></html>';
                    }
                    exit;
                }
                return $location;
            }, 1, 2);
            
            // Allow certain AJAX and upload endpoints
            if (preg_match('#^/wp-admin/(admin-ajax|async-upload|admin-post)\.php#', $request_uri)) {
                return;
            }
            
            // Allow REST API requests
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return;
            }
            
            // If user is logged in, re-add filters and allow access
            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
                add_filter('site_url', [$this, 'filter_site_url'], 10, 4);
                add_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10, 2);
                return;
            }
            
            // For unauthenticated users, we'll handle this in template_redirect
        }
    }

    /**
     * Check if current request is for custom login slug
     */
    private function is_custom_login_request($path)
    {
        $custom_path = '/' . $this->custom_login_slug;
        return $path === $custom_path;
    }

    /**
     * Check if current request is for wp-login.php
     */
    private function is_wp_login_request($path)
    {
        return strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false || 
               $path === '/wp-login';
    }

    /**
     * Check if current request is for wp-register.php
     */
    private function is_wp_register_request($path)
    {
        return strpos($_SERVER['REQUEST_URI'], 'wp-register.php') !== false || 
               $path === '/wp-register';
    }

    /**
     * Check if login should be allowed for special cases
     */
    private function is_login_allowed()
    {
        return (
            (isset($_GET['magic_login']) && !empty($_GET['magic_login'])) ||
            (isset($_GET['action']) && $_GET['action'] === 'logout') ||
            (isset($_GET['interim-login']) && $_GET['interim-login'] == 1) ||
            is_user_logged_in()
        );
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
        
        // Preserve original query string
        $original_request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parsed_url = parse_url($original_request_uri);
        $query_string = $parsed_url['query'] ?? '';
        
        // Set up environment to serve wp-login.php like wps-hide-login does
        $_SERVER['SCRIPT_NAME'] = $this->custom_login_slug;
        $pagenow = 'wp-login.php';
        
        // Set REQUEST_URI to wp-login.php with preserved query string
        $_SERVER['REQUEST_URI'] = '/wp-login.php' . ($query_string ? '?' . $query_string : '');
        
        
        // Include and execute wp-login.php
        require_once ABSPATH . 'wp-login.php';
        exit;
    }

    /**
     * Filter login_url to use custom login path only for admin access
     */
    public function filter_login_url($login_url, $redirect, $force_reauth)
    {
        // Check if this is a direct wp-admin access (should 404 instead of redirect)
        $current_request = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#^/wp-admin(/|$)#', $current_request)) {
            // This is direct wp-admin access, disable all login URL filtering
            
            // Remove this filter temporarily to prevent any redirects
            remove_filter('login_url', [$this, 'filter_login_url'], 10);
            
            // Return original URL but the removal above prevents further processing
            return $login_url;
        }
        
        // Only use custom login slug if this is for admin access from other contexts (logout, etc)
        if (!empty($redirect) && (strpos($redirect, '/wp-admin') !== false || strpos($redirect, 'wp-admin') !== false)) {
            $url = home_url('/' . $this->custom_login_slug . '/');
            
            if (!empty($redirect)) {
                $url = add_query_arg('redirect_to', urlencode($redirect), $url);
            }
            
            if ($force_reauth) {
                $url = add_query_arg('reauth', '1', $url);
            }
            
            return $url;
        }
        
        // For regular user login, return the original URL unchanged
        return $login_url;
    }

    /**
     * Filter site_url to replace wp-login.php references only for admin access
     */
    public function filter_site_url($url, $path, $scheme, $blog_id)
    {
        if (strpos($url, 'wp-login.php') !== false) {
            global $pagenow;
            
            // If we're currently serving the login page through our custom slug,
            // ALL references to wp-login.php should be rewritten to our custom slug
            if ($pagenow === 'wp-login.php' && isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] === $this->custom_login_slug) {
                // We're in the custom login context, rewrite all wp-login.php URLs
                $parsed_url = parse_url($url);
                $query_string = $parsed_url['query'] ?? '';
                
                $new_url = home_url('/' . $this->custom_login_slug . '/');
                if ($query_string) {
                    $new_url .= '?' . $query_string;
                }
                
                return $new_url;
            }
            
            // Parse the original URL to check parameters
            $parsed_url = parse_url($url);
            $query_string = $parsed_url['query'] ?? '';
            
            // Only redirect to custom slug if this is admin-related
            $is_admin_login = false;
            
            // Check if redirect_to parameter contains wp-admin
            if (strpos($query_string, 'redirect_to') !== false && strpos($query_string, 'wp-admin') !== false) {
                $is_admin_login = true;
            }
            
            // Check if this is a logout from admin (contains action=logout)
            if (strpos($query_string, 'action=logout') !== false) {
                $is_admin_login = true;
            }
            
            // Check if this is a reauth request (typically admin-related)
            if (strpos($query_string, 'reauth=1') !== false) {
                $is_admin_login = true;
            }
            
            if ($is_admin_login) {
                // Build the new URL with our custom login slug
                $new_url = home_url('/' . $this->custom_login_slug . '/');
                
                // Preserve query parameters (important for logout, etc.)
                if ($query_string) {
                    $new_url .= '?' . $query_string;
                }
                
                return $new_url;
            }
            
        }
        
        return $url;
    }

    /**
     * Filter wp_redirect to replace wp-login.php references only for admin access
     */
    public function filter_wp_redirect($location, $status)
    {
        if (strpos($location, 'wp-login.php') !== false) {
            // Parse the original URL to check parameters
            $parsed_url = parse_url($location);
            $query_string = $parsed_url['query'] ?? '';
            
            // Only redirect to custom slug if this is admin-related
            $is_admin_login = false;
            
            // Check if redirect_to parameter contains wp-admin
            if (strpos($query_string, 'redirect_to') !== false && strpos($query_string, 'wp-admin') !== false) {
                $is_admin_login = true;
            }
            
            // Check if this is a logout from admin (contains action=logout)
            if (strpos($query_string, 'action=logout') !== false) {
                $is_admin_login = true;
            }
            
            // Check if this is a reauth request (typically admin-related)
            if (strpos($query_string, 'reauth=1') !== false) {
                $is_admin_login = true;
            }
            
            if ($is_admin_login) {
                // Build the new URL with our custom login slug
                $new_url = home_url('/' . $this->custom_login_slug . '/');
                
                // Preserve query parameters (important for logout, etc.)
                if ($query_string) {
                    $new_url .= '?' . $query_string;
                }
                
                return $new_url;
            }
            
        }
        
        return $location;
    }
}
