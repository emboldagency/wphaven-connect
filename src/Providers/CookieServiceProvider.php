<?php

namespace WPHavenConnect\Providers;

class CookieServiceProvider {

    public function register() {
        add_action('wp_login', [$this, 'set_haven_cookie'], 10, 2);
        add_action('wp_logout', [$this, 'clear_haven_cookie']);
    }

    public function set_haven_cookie($user_login, $user) {
        if (user_can($user, 'administrator') || user_can($user, 'editor')) {
            setcookie('haven_waf_allow', 'true', time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        }
    }

    public function clear_haven_cookie() {
        setcookie('haven_waf_allow', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
    }
}
