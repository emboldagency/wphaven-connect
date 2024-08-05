<?php

namespace WPHavenConnect\Providers;

class CommandLineServiceProvider {

    public function register() {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('user session create', [$this, 'create_user_session']);
        }
    }

    public function create_user_session($args, $assoc_args) {
        $user_login = $args[0];
        $user = get_user_by('login', $user_login);

        if (!$user) {
            \WP_CLI::error("User $user_login does not exist.");
        }

        // Generate a login URL for the user
        $token = wp_generate_auth_cookie($user->ID, time() + 3600, 'admin');
        $login_url = add_query_arg('wp_admin_token', $token, admin_url());

        \WP_CLI::line(json_encode(['login_url' => $login_url]));
    }
}
