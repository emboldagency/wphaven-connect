<?php

namespace WPHavenConnect\Providers;

use WP_CLI;

class CommandLineServiceProvider
{

    public function register()
    {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('user session create', [$this, 'create_user_session']);
            WP_CLI::add_command('homepage edit', [$this, 'edit_homepage']);
        }

        // Hook into init to handle magic login
        add_action('init', [$this, 'handle_magic_login']);
    }

    public function create_user_session($args, $assoc_args)
    {
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
                WP_CLI::warning("User $user_login does not exist. Using the first created admin user: " . $user->user_login);
            } else {
                WP_CLI::error("User $user_login does not exist, and no admin user could be found.");
            }
        }

        // Generate a unique token (could be a hash, timestamp, etc.)
        $token = bin2hex(random_bytes(16));
        update_user_meta($user->ID, '_magic_login_token', $token);

        // Create a custom login URL with the token
        $login_url = add_query_arg('magic_login', $token, admin_url());

        WP_CLI::line(json_encode(['login_url' => $login_url]));
    }

    public function handle_magic_login()
    {
        if (isset($_GET['magic_login'])) {
            $token = sanitize_text_field($_GET['magic_login']);

            // If user is already logged in, just redirect to admin
            if (is_user_logged_in()) {
                wp_safe_redirect(admin_url());
                exit;
            }

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

    public function edit_homepage()
    {
        $home_page_id = get_option('page_on_front');

        if ($home_page_id) {
            $post = get_post($home_page_id);

            if (!$post) {
                WP_CLI::error("No post found with ID $home_page_id.");
            }

            if ($post->post_status === 'trash') {
                WP_CLI::error("Home page with ID $home_page_id is in Trash.");
            }

            if ($post->post_type !== 'page') {
                WP_CLI::error("Home page ID $home_page_id is not a page (it's a {$post->post_type}).");
            }

            // Build the edit URL manually instead of using get_edit_post_link()
            $edit_url = admin_url('post.php?post=' . $home_page_id . '&action=edit');

            WP_CLI::line(json_encode(['edit_url' => $edit_url]));
        } else {
            WP_CLI::error("Home page is not set.");
        }
    }
}
