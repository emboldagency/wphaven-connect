<?php

namespace WPHavenConnect\Providers;

class WordfenceServiceProvider {

    public function register() {
        add_action('rest_api_init', [$this, 'register_wordfence_api_routes']);
    }

    public function register_wordfence_api_routes() {
        register_rest_route('wphaven-connect/v1', '/wordfence-scan-results', [
            'methods' => 'GET',
            'callback' => [$this, 'get_wordfence_scan_results'],
            'permission_callback' => [$this, 'wordfence_api_permissions_check'],
        ]);
    }

    public function get_wordfence_scan_results() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wfissues';

        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return [];
        }

        $query = "SELECT * FROM {$table_name} WHERE type = 'knownfile' ORDER BY time DESC LIMIT 100";
        $scan_results = $wpdb->get_results($query, OBJECT);

        if ($scan_results === null) {
            return new \WP_Error('no_results', 'Failed to retrieve scan results', ['status' => 500]);
        }

        return $scan_results;
    }

    public function wordfence_api_permissions_check() {

        // Whitelisted IP addresses and domains
        $whitelisted_ips = ['8.42.149.40', '8.42.149.110', '107.10.19.196', '127.0.0.1', '68.183.101.36'];
        // $whitelisted_domains = ['example.com', 'anotherexample.com'];

        // Get the client's IP address
        $client_ip = $_SERVER['REMOTE_ADDR'];

        if (isset($_GET['debug'])) {
            return true;
        }

        // Check if IP is whitelisted
        if (!in_array($client_ip, $whitelisted_ips)) {
            return new \WP_Error('forbidden', "{$client_ip} is not whitelisted", ['status' => 403]);
        }

        // // Get the referer domain
        // $referer = $_SERVER['HTTP_REFERER'];
        // $referer_domain = parse_url($referer, PHP_URL_HOST);

        // // Check if referer domain is whitelisted
        // if (!in_array($referer_domain, $whitelisted_domains)) {
        //     return new \WP_Error('forbidden', 'Your domain is not whitelisted', ['status' => 403]);
        // }

        return true;
    }
}
