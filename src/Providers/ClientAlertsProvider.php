<?php

namespace WPHavenConnect\Providers;

class ClientAlertsProvider {

    protected $alert_check_endpoint = 'https://wphaven.app/api/v1/wordpress/check-alerts';
    protected $transient_key = 'wphaven_alert_check';

    public function register() {
        add_action('admin_notices', [$this, 'displayAlertNotice']);
    }

    public function displayAlertNotice() {
        // Check if the alert status has been checked in the last hour
        $alert_status = get_transient($this->transient_key);

        if ($alert_status === false) {
            $alert_status = $this->checkForAlerts();
            set_transient($this->transient_key, $alert_status, 60 * MINUTE_IN_SECONDS);
        }

        if ($alert_status === 1) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>There is an alert waiting for you in the <a href="https://wphaven.app/dashboard" target="_blank">WP Haven dashboard</a>.</p>';
            echo '</div>';
        }
    }

    private function checkForAlerts() {
        // Get the current domain
        $domain = home_url();

        // Append the domain as a query parameter
        $url = add_query_arg('domain', urlencode($domain), $this->alert_check_endpoint);

        $response = wp_remote_get($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            // Handle error if the request fails
            return 0;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data['has_alert'] ?? 0;
    }
}
