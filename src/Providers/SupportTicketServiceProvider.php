<?php

namespace WPHavenConnect\Providers;

use WP_REST_Server;
use WP_REST_Response;

class SupportTicketServiceProvider extends ServiceProvider
{
    /** The most files one ticket may carry; WP Haven refuses more. */
    const MAX_ATTACHMENTS = 5;

    /** WP Haven's own per-file limit. */
    const MAX_ATTACHMENT_BYTES = 10 * MB_IN_BYTES;

    /** The file types WP Haven accepts on a ticket. */
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'log'];

    /** Subscribers and shop customers can reach the dashboard but should not open tickets. */
    const REQUIRED_CAPABILITY = 'edit_posts';

    private function getApiBase()
    {
        // Allow override via constant or plugin option: Constant > Plugin Option > Default
        if (defined('WPHAVEN_API_BASE')) {
            // remove trailing / if exists
            $base = rtrim(WPHAVEN_API_BASE, '/');
            return $base . '/api/v1/wphaven-connect';
        }

        // Check plugin option
        $opts = get_option('wphaven_connect_options', []);
        if (!empty($opts['wphaven_api_base'])) {
            $base = rtrim($opts['wphaven_api_base'], '/');
            return $base . '/api/v1/wphaven-connect';
        }

        // Default to wphaven.app
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
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            return;
        }

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
        if ($hook !== 'index.php' || !current_user_can(self::REQUIRED_CAPABILITY)) {
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
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/support-ticket.js'),
            true
        );

        wp_enqueue_style(
            'wphaven-support-ticket',
            $plugin_dir_url . 'src/assets/css/support-ticket.css',
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/css/support-ticket.css')
        );

        wp_localize_script('wphaven-support-ticket', 'wphavenSupport', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wphaven_support_nonce'),
            'maxFiles' => self::MAX_ATTACHMENTS,
            'maxBytes' => $this->maxAttachmentBytes(),
            'maxTotalBytes' => $this->maxRequestBytes(),
            'allowedExtensions' => self::ALLOWED_EXTENSIONS,
        ]);
    }

    /**
     * Files go to WP Haven only from a site that holds its connect secret,
     * since WP Haven takes them only with that secret.
     */
    private function attachmentsEnabled(): bool
    {
        return self::configuredSecret() !== null;
    }

    /**
     * The largest single file this server and WP Haven will both take.
     */
    private function maxAttachmentBytes(): int
    {
        return (int) min(self::MAX_ATTACHMENT_BYTES, wp_max_upload_size());
    }

    /**
     * The largest whole form this server will read (post_max_size), or 0 when unlimited.
     */
    private function maxRequestBytes(): int
    {
        return (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size'));
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
                    <?php if ($this->attachmentsEnabled()) : ?>
                    <tr>
                        <td><label for="wphaven-attachments">Attachments:</label></td>
                        <td>
                            <input type="file" id="wphaven-attachments" name="attachments[]" multiple
                                accept="<?php echo esc_attr('.' . implode(',.', self::ALLOWED_EXTENSIONS)); ?>" />
                            <p class="description" id="wphaven-attachments-hint">
                                <?php
                                echo esc_html(sprintf(
                                    'Optional. Images, PDF, .txt or .log files, up to %d files, %s each.',
                                    self::MAX_ATTACHMENTS,
                                    size_format($this->maxAttachmentBytes())
                                ));
                                ?>
                            </p>
                            <ul id="wphaven-attachment-list"></ul>
                        </td>
                    </tr>
                    <?php endif; ?>
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
        // PHP drops the whole form, nonce included, when it is over post_max_size.
        if (empty($_POST) && $this->requestTooLarge()) {
            wp_send_json_error([
                'message' => sprintf(
                    'The files are too large for this server to accept (%s in total). Remove some and try again.',
                    size_format($this->maxRequestBytes())
                ),
            ]);
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wphaven_support_nonce')) {
            wp_send_json_error(['message' => 'Your session has expired. Reload the page and try again.']);
            return;
        }

        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_send_json_error(['message' => 'You do not have permission to open support tickets.']);
            return;
        }

        // Get support status (should be cached)
        $support_status = get_transient('wphaven_support_status');
        if (!$support_status || !isset($support_status['site_id'])) {
            wp_send_json_error(['message' => 'Support is not available for this site']);
            return;
        }

        if (!$this->attachmentsEnabled()) {
            $files = [];
            if ($this->hasUploadedFiles()) {
                wp_send_json_error([
                    'message' => 'This site can no longer send attachments. Remove the files and send the ticket again.',
                ]);
                return;
            }
        } else {
            $files = $this->uploadedAttachments();
            if (is_wp_error($files)) {
                wp_send_json_error(['message' => $files->get_error_message()]);
                return;
            }
        }

        // admin-ajax.php does not raise memory the way admin.php does, and the
        // multipart body holds every file in memory.
        if ($files) {
            wp_raise_memory_limit('admin');
        }

        // Prepare data for API
        $ticket_data = [
            'site_id' => $support_status['site_id'],
            'wordpress_username' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'wordpress_email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'name' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        ];

        [$body, $boundary] = $this->buildMultipartBody($ticket_data, $files);

        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Accept' => 'application/json',
        ];

        $secret = self::configuredSecret();
        if ($secret !== null) {
            $headers['Authorization'] = 'Bearer ' . $secret;
        }

        // Submit to WPHaven API
        $response = wp_remote_post($this->getApiBase() . '/tickets', [
            'headers' => $headers,
            'body' => $body,
            'timeout' => $files ? 60 : 30,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to submit ticket. Please try again.']);
            return;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 200 && isset($data['success']) && $data['success']) {
            wp_send_json_success(['message' => 'Ticket created successfully']);
            return;
        }

        if ($status === 401 && $files) {
            $error_message = 'This site is not set up to send attachments yet. Remove the files and send the ticket again.';
        } elseif ($status === 413) {
            $error_message = 'The files are too large to send. Remove some and try again.';
        } elseif (isset($data['errors']) && is_array($data['errors'])) {
            // Handle validation errors
            $error_messages = [];
            foreach ($data['errors'] as $field => $messages) {
                $error_messages[] = implode(', ', (array) $messages);
            }
            $error_message = implode('. ', $error_messages);
        } else {
            $error_message = isset($data['message']) ? $data['message'] : 'Failed to create ticket';
        }

        wp_send_json_error(['message' => $error_message]);
    }

    /**
     * Whether the request body was larger than PHP would read.
     */
    private function requestTooLarge(): bool
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $limit = $this->maxRequestBytes();

        return $limit > 0 && $length > $limit;
    }

    private function hasUploadedFiles(): bool
    {
        $errors = (array) ($_FILES['attachments']['error'] ?? []);

        foreach ($errors as $error) {
            if ((int) $error !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The files sent with the form, checked against what WP Haven accepts.
     *
     * @return array<int, array{path: string, name: string}>|\WP_Error
     */
    private function uploadedAttachments()
    {
        if (empty($_FILES['attachments']['name']) || !is_array($_FILES['attachments']['name'])) {
            return [];
        }

        $raw = $_FILES['attachments'];
        $files = [];

        foreach (array_keys($raw['name']) as $index) {
            $error = (int) ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $name = sanitize_file_name(wp_unslash((string) $raw['name'][$index]));

            if ($error !== UPLOAD_ERR_OK) {
                return new \WP_Error('wphaven_upload', $this->uploadErrorMessage($error, $name));
            }

            $path = (string) $raw['tmp_name'][$index];
            if (!is_uploaded_file($path)) {
                return new \WP_Error('wphaven_upload', sprintf('%s could not be uploaded. Please try again.', $name));
            }

            if ((int) $raw['size'][$index] > $this->maxAttachmentBytes()) {
                return new \WP_Error('wphaven_upload', sprintf(
                    '%s is larger than %s.',
                    $name,
                    size_format($this->maxAttachmentBytes())
                ));
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                return new \WP_Error('wphaven_upload', sprintf(
                    '%s is not a file type that can be attached. Use an image, PDF, .txt or .log file.',
                    $name
                ));
            }

            $files[] = ['path' => $path, 'name' => $name];
        }

        if (count($files) > self::MAX_ATTACHMENTS) {
            return new \WP_Error('wphaven_upload', sprintf('Attach up to %d files.', self::MAX_ATTACHMENTS));
        }

        return $files;
    }

    private function uploadErrorMessage(int $error, string $name): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return sprintf(
                    '%s is larger than this server allows (%s).',
                    $name,
                    size_format(wp_max_upload_size())
                );
            case UPLOAD_ERR_PARTIAL:
                return sprintf('%s was only partly uploaded. Please try again.', $name);
            default:
                return sprintf('%s could not be uploaded because of a server problem.', $name);
        }
    }

    /**
     * A multipart/form-data body: the text fields, then each file as attachments[].
     * WP Haven reads multipart text fields just as it reads JSON.
     *
     * @param array<string, scalar> $fields
     * @param array<int, array{path: string, name: string}> $files
     * @return array{0: string, 1: string} the body and its boundary
     */
    private function buildMultipartBody(array $fields, array $files): array
    {
        $boundary = 'wphaven-' . wp_generate_password(24, false);
        $eol = "\r\n";
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol
                . $value . $eol;
        }

        foreach ($files as $file) {
            $filename = str_replace(['"', "\r", "\n"], '', $file['name']);

            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="attachments[]"; filename="' . $filename . '"' . $eol
                . 'Content-Type: ' . $this->mimeType($file['path']) . $eol . $eol
                . file_get_contents($file['path']) . $eol;
        }

        $body .= '--' . $boundary . '--' . $eol;

        return [$body, $boundary];
    }

    private function mimeType(string $path): string
    {
        if (class_exists('finfo')) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return 'application/octet-stream';
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
