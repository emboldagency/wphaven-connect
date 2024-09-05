<?php

namespace WPHavenConnect\Providers;

class CommandLineServiceProvider {

    public function register() {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('user session create', [$this, 'create_user_session']);
        }

        // Hook into init to handle magic login
        add_action('init', [$this, 'handle_magic_login']);
    }

    public function create_user_session($args, $assoc_args) {
        $user_login = $args[0];
        $user = get_user_by('login', $user_login);

        // If the user does not exist, fetch the first created admin-level user
        if (!$user) {
            $user_query = new \WP_User_Query([
                'role' => 'administrator',
                'orderby' => 'registered',
                'order' => 'ASC',
                'number' => 1,
            ]);

            $users = $user_query->get_results();

            if (!empty($users)) {
                $user = $users[0];
                \WP_CLI::warning("User $user_login does not exist. Using the first created admin user: " . $user->user_login);
            } else {
                \WP_CLI::error("User $user_login does not exist, and no admin user could be found.");
            }
        }

        // Generate a unique token (could be a hash, timestamp, etc.)
        $token = bin2hex(random_bytes(16));
        update_user_meta($user->ID, '_magic_login_token', $token);

        // Create a custom login URL with the token
        $login_url = add_query_arg('magic_login', $token, admin_url());

        \WP_CLI::line(json_encode(['login_url' => $login_url]));
    }

    public function handle_magic_login() {
        if (isset($_GET['magic_login'])) {
            $token = sanitize_text_field($_GET['magic_login']);
            $user_query = new \WP_User_Query([
                'meta_key' => '_magic_login_token',
                'meta_value' => $token,
                'number' => 1,
            ]);

            $users = $user_query->get_results();

            if (!empty($users)) {
                $user = $users[0];

                // Log the user in
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                delete_user_meta($user->ID, '_magic_login_token'); // Token should be single-use

                // Redirect to admin dashboard
                wp_safe_redirect(admin_url());
                exit;
            } else {
                wp_die('Invalid or expired token.');
            }
        }
    }
}
