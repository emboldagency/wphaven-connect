<?php

namespace WPHavenConnect\Providers;

class WooCommerceServiceProvider {

    public function register() {
        add_action('init', [$this, 'disable_woocommerce_tracking']);
    }

    public function disable_woocommerce_tracking() {
        if (class_exists('WooCommerce')) {
            add_filter('woocommerce_tracker_data', function() {
                return [];
            });

            update_option('woocommerce_allow_tracking', 'no');
        }
    }
}
