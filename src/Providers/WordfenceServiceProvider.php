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
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],  // Use centralized permissions
        ]);
    }

    public function get_wordfence_scan_results() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wfissues';

        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return [];
        }

        $query = "SELECT * FROM {$table_name} WHERE type = 'knownfile' AND status != 'ignoreP' ORDER BY time DESC LIMIT 100";
        $scan_results = $wpdb->get_results($query, OBJECT);

        if ($scan_results === null) {
            return new \WP_Error('no_results', 'Failed to retrieve scan results', ['status' => 500]);
        }

        return $scan_results;
    }
}
