<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class ServerInfoServiceProvider {

    public function register() {
        // Register REST API endpoint
        add_action('rest_api_init', [$this, 'registerServerInfoEndpoint']);

        // Register WP-CLI command if WP_CLI is defined
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('server-info', [$this, 'handleServerInfoCli']);
        }
    }

    // Register the /server-info endpoint
    public function registerServerInfoEndpoint() {
        register_rest_route('wphaven-connect/v1', '/server-info', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'getServerInfo'],
            'permission_callback' => [ServiceProvider::class, 'apiPermissionsCheck'],  // Centralized permissions
        ]);
    }

    // REST API callback function
    public function getServerInfo() {
        // Gather PHP and database information
        $serverInfo = $this->collectServerInfo();

        // Return as JSON in the response
        return new WP_REST_Response($serverInfo, 200);
    }

    // WP-CLI command handler
    public function handleServerInfoCli($args, $assoc_args) {
        // Gather PHP and database information
        $serverInfo = $this->collectServerInfo();

        // Check if a specific field is requested
        if (isset($assoc_args['field'])) {
            $field = $assoc_args['field'];
            if (isset($serverInfo[$field])) {
                \WP_CLI::line($serverInfo[$field]);
            } else {
                \WP_CLI::error("Field '$field' not found.");
            }
        } else {
            // Output the full server info as JSON
            \WP_CLI::line(json_encode($serverInfo));
        }
    }

    // Collect both PHP and database info for REST and CLI
    protected function collectServerInfo() {
        // Get PHP version including minor
        $phpVersion = phpversion();

        // 1 decimal point for PHP version so like 8.1, 8.0, 7.4, etc.
        $phpVersionMajor = substr($phpVersion, 0, 3);

        // Remove the decimal point and get the first 2 characters for php_version_basic
        $phpVersionBasic = substr(str_replace('.', '', $phpVersion), 0, 2);

        // Get database type and version
        $dbType = $this->getDbType();
        $dbVersion = $this->getDbVersion();

        // Return as an associative array
        return [
            'php_version_minor' => $phpVersion,
            'php_version_major' => $phpVersionMajor,
            'php_version_basic' => $phpVersionBasic,
            'db_type'           => $dbType,
            'db_version'        => $dbVersion,
        ];
    }

    // Helper function to get the DB type (MySQL or MariaDB)
    protected function getDbType() {
        global $wpdb;
        $dbType = $wpdb->use_mysqli ? 'mysql' : 'mariadb';

        // Check if it's explicitly MariaDB
        $mysqliInfo = mysqli_get_server_info($wpdb->dbh);
        if (stripos($mysqliInfo, 'mariadb') !== false) {
            $dbType = 'mariadb';
        }

        return $dbType;
    }

    // Helper function to get the DB version
    protected function getDbVersion() {
        global $wpdb;
        return $wpdb->db_version();
    }
}
