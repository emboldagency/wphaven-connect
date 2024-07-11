<?php

if (!defined('ABSPATH')) {
    exit;
}

WP_CLI::add_command('user session create', function ($args, $assoc_args) {
    $user_login = $args[0];
    $user = get_user_by('login', $user_login);

    if (!$user) {
        WP_CLI::error("User $user_login does not exist.");
    }

    // Generate a login URL for the user
    $token = wp_generate_auth_cookie($user->ID, time() + 3600, 'admin');
    $login_url = add_query_arg('wp_admin_token', $token, admin_url());

    WP_CLI::line(json_encode(['login_url' => $login_url]));
});
