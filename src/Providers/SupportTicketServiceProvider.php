<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class SupportTicketServiceProvider extends ServiceProvider
{
    private function getApiBase()
    {
        // Allow override via constant for different environments
        if (defined('WPHAVEN_API_BASE')) {
            // remove trailing / if exists
            $base = rtrim(WPHAVEN_API_BASE, '/');

            return $base . '/api/v1/wphaven-connect';
        }

        return 'https://wphaven.app/api/v1/wphaven-connect';
    }

    public function register()
    {
        // Add dashboard widget
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);

        // Enqueue scripts and styles for dashboard
        add_action('admin_enqueue_scripts', [$this, 'enqueueDashboardScripts']);

        // AJAX handlers
        add_action('wp_ajax_wphaven_support_ticket', [$this, 'handleSupportTicketSubmission']);
        add_action('wp_ajax_wphaven_check_support_status', [$this, 'checkSupportStatus']);
    }

    public function addDashboardWidget()
    {
        // Check if support is enabled for this site (cached)
        $support_status = get_transient('wphaven_support_status');

        if ($support_status === false) {
            $support_status = $this->fetchSupportStatus();
            set_transient('wphaven_support_status', $support_status, 12 * HOUR_IN_SECONDS);
        }

        if ($support_status && isset($support_status['enabled']) && $support_status['enabled']) {
            $widget_title = 'Contact ' . ($support_status['name_to_use'] ?? 'WP Haven') . ' Maintenance Support';

            wp_add_dashboard_widget(
                'wphaven_support_ticket',
                $widget_title,
                [$this, 'renderSupportWidget']
            );
        }
    }

    public function enqueueDashboardScripts($hook)
    {
        if ($hook !== 'index.php') {
            return;
        }

        // Only enqueue if support widget should be shown
        $support_status = get_transient('wphaven_support_status');
        if (!$support_status || !isset($support_status['enabled']) || !$support_status['enabled']) {
            return;
        }

        $plugin_dir_url = plugin_dir_url(__FILE__);
        $plugin_dir_url = str_replace('/src/Providers/', '/', $plugin_dir_url);
        
        wp_enqueue_script(
            'wphaven-support-ticket',
            $plugin_dir_url . 'src/assets/js/support-ticket.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_style(
            'wphaven-support-ticket',
            $plugin_dir_url . 'src/assets/css/support-ticket.css',
            [],
            '1.0.0'
        );

        wp_localize_script('wphaven-support-ticket', 'wphavenSupport', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wphaven_support_nonce')
        ]);
    }

    public function renderSupportWidget()
    {
        $current_user = wp_get_current_user();
        ?>
        <div id="wphaven-support-widget">
            <form id="wphaven-support-form">
                <div id="wphaven-support-messages"></div>

                <table class="form-table">
                    <tr>
                        <td><label for="wphaven-name">Name:</label></td>
                        <td><input style="width: 100%;" type="text" id="wphaven-name" name="name"
                                   value="<?php echo esc_attr($current_user->display_name); ?>" required /></td>
                    </tr>
                    <tr>
                        <td><label for="wphaven-email">Email:</label></td>
                        <td><input style="width: 100%;" type="email" id="wphaven-email" name="email"
                                   value="<?php echo esc_attr($current_user->user_email); ?>" required /></td>
                    </tr>
                    <tr>
                        <td><label for="wphaven-subject">Subject:</label></td>
                        <td><input style="width: 100%;" type="text" id="wphaven-subject" name="subject" required /></td>
                    </tr>
                    <tr>
                        <td><label for="wphaven-description">Description:</label></td>
                        <td><textarea style="width: 100%;" id="wphaven-description" name="description" rows="5"
                                      required></textarea></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="wphaven-submit-btn">
                        Create Ticket
                    </button>
                </p>
            </form>

            <div id="wphaven-success-message" style="display:none;">
                <div class="wphaven-success-notice">
                    <p><strong>Support ticket created successfully!</strong></p>
                    <p>We'll email you with a reply after your ticket has been reviewed.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function handleSupportTicketSubmission()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wphaven_support_nonce')) {
            wp_die('Security check failed');
        }

        // Get support status (should be cached)
        $support_status = get_transient('wphaven_support_status');
        if (!$support_status || !isset($support_status['site_id'])) {
            wp_send_json_error(['message' => 'Support is not available for this site']);
            return;
        }

        // Prepare data for API
        $ticket_data = [
            'site_id' => $support_status['site_id'],
            'wordpress_username' => sanitize_text_field($_POST['name']),
            'wordpress_email' => sanitize_email($_POST['email']),
            'name' => sanitize_text_field($_POST['subject']),
            'description' => sanitize_textarea_field($_POST['description'])
        ];

        // Submit to WPHaven API
        $response = wp_remote_post($this->getApiBase() . '/tickets', [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($ticket_data),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to submit ticket. Please try again.']);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) === 200 && isset($data['success']) && $data['success']) {
            wp_send_json_success(['message' => 'Ticket created successfully']);
        } else {
            // Handle validation errors
            if (isset($data['errors'])) {
                $error_messages = [];
                foreach ($data['errors'] as $field => $messages) {
                    $error_messages[] = implode(', ', $messages);
                }
                $error_message = implode('. ', $error_messages);
            } else {
                $error_message = isset($data['message']) ? $data['message'] : 'Failed to create ticket';
            }
            wp_send_json_error(['message' => $error_message]);
        }
    }

    public function checkSupportStatus()
    {
        $support_status = $this->fetchSupportStatus();
        set_transient('wphaven_support_status', $support_status, 12 * HOUR_IN_SECONDS);

        wp_send_json_success($support_status);
    }

    private function fetchSupportStatus()
    {
        $domain = $_SERVER['HTTP_HOST'];

        $response = wp_remote_get($this->getApiBase() . '/support-status?domain=' . urlencode($domain), [
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return ['enabled' => false];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data ?: ['enabled' => false];
    }
}
