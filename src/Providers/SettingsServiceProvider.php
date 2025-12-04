<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\Utilities\ElevatedUsers;

class SettingsServiceProvider
{
    const OPTION_NAME = 'wphaven_connect_options';

    public function register()
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        // Handle test email submissions from the settings page
        add_action('admin_post_wphaven_connect_send_test_email', [$this, 'handleSendTestEmail']);
        // Enqueue settings page assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueSettingsAssets']);
    }

    public function enqueueSettingsAssets($hook)
    {
        // Only load on our settings page
        if ($hook !== 'settings_page_wphaven-connect') {
            return;
        }

        wp_enqueue_script(
            'wphaven-connect-settings',
            plugins_url('../../src/assets/js/settings-page.js', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/js/settings-page.js'),
            true
        );

        wp_enqueue_style(
            'wphaven-connect-settings',
            plugins_url('../../src/assets/css/settings-page.css', __FILE__),
            [],
            filemtime(dirname(__DIR__, 2) . '/src/assets/css/settings-page.css')
        );
    }

    public function addSettingsPage()
    {
        // Restrict visibility of the menu item to elevated users only
        if (class_exists(ElevatedUsers::class) && !ElevatedUsers::currentIsElevated()) {
            return;
        }

        add_options_page(
            __('WP Haven Connect', 'wphaven-connect'),
            __('WP Haven Connect', 'wphaven-connect'),
            'manage_options',
            'wphaven-connect',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings()
    {
        register_setting(self::OPTION_NAME, self::OPTION_NAME, [$this, 'sanitize']);

        // --- SECTION 1: General Settings ---
        add_settings_section(
            'wphaven_connect_general',
            __('General Environment Overrides', 'wphaven-connect'),
            function () {
                echo '<p>' . esc_html__('General environment and site-related overrides.', 'wphaven-connect') . '</p>';
            },
            'wphaven-connect'
        );

        add_settings_field('elevated_emails', __('Elevated admin emails', 'wphaven-connect'), [$this, 'renderElevatedEmailsField'], 'wphaven-connect', 'wphaven_connect_general');
        add_settings_field('wphaven_api_base', __('WP Haven API Base', 'wphaven-connect'), [$this, 'renderApiBaseField'], 'wphaven-connect', 'wphaven_connect_general');
        add_settings_field('suppress_notices', __('Suppress debug notices', 'wphaven-connect'), [$this, 'renderSuppressNoticesField'], 'wphaven-connect', 'wphaven_connect_general');

        // Determine if custom strings row should be hidden on load
        $opts = $this->getOptions();
        $suppress_row_class = empty($opts['suppress_notices']) ? 'wph-suppress-notice-extra-strings-row hidden' : 'wph-suppress-notice-extra-strings-row';
        $suppress_row_args = ['class' => $suppress_row_class];

        add_settings_field('suppress_notice_extra_strings', __('Custom notice strings', 'wphaven-connect'), [$this, 'renderSuppressNoticeExtraStringsField'], 'wphaven-connect', 'wphaven_connect_general', $suppress_row_args);
        add_settings_field('admin_login_slug', __('Custom admin login slug', 'wphaven-connect'), [$this, 'renderAdminLoginSlugField'], 'wphaven-connect', 'wphaven_connect_general');

        // --- SECTION 2: Mail Configuration ---
        add_settings_section(
            'wphaven_connect_main',
            __('Development Mail Configuration', 'wphaven-connect'),
            function () {
                echo '<p>' . esc_html__('Configure how WordPress sends email in this environment.', 'wphaven-connect') . '</p>';
            },
            'wphaven-connect'
        );

        // Transport Mode
        add_settings_field(
            'mail_transport_mode',
            __('Mail Delivery Mode', 'wphaven-connect'),
            [$this, 'renderMailTransportModeField'],
            'wphaven-connect',
            'wphaven_connect_main'
        );

        // Determine if SMTP rows should be hidden on load
        $opts = $this->getOptions();
        $smtp_row_class = ($opts['mail_transport_mode'] === 'smtp_override') ? 'wph-smtp-row' : 'wph-smtp-row hidden';
        $smtp_row_args = ['class' => $smtp_row_class];

        // SMTP Fields
        add_settings_field(
            'smtp_host',
            __('SMTP Host', 'wphaven-connect'),
            [$this, 'renderSmtpHostField'],
            'wphaven-connect',
            'wphaven_connect_main',
            $smtp_row_args
        );

        add_settings_field(
            'smtp_port',
            __('SMTP Port', 'wphaven-connect'),
            [$this, 'renderSmtpPortField'],
            'wphaven-connect',
            'wphaven_connect_main',
            $smtp_row_args
        );

        add_settings_field(
            'smtp_from_email',
            __('From Address', 'wphaven-connect'),
            [$this, 'renderSmtpFromField'],
            'wphaven-connect',
            'wphaven_connect_main',
            $smtp_row_args
        );

        add_settings_field(
            'smtp_from_name',
            __('From Name', 'wphaven-connect'),
            [$this, 'renderSmtpFromNameField'],
            'wphaven-connect',
            'wphaven_connect_main',
            $smtp_row_args
        );
    }

    public function sanitize($input)
    {
        // Start with existing options so we don't wipe data if validation fails
        $output = get_option(self::OPTION_NAME, []);

        // --- Mail Mode ---
        $valid_modes = ['no_override', 'smtp_override', 'block_all'];
        if (isset($input['mail_transport_mode']) && in_array($input['mail_transport_mode'], $valid_modes)) {
            $output['mail_transport_mode'] = sanitize_text_field($input['mail_transport_mode']);
        }

        // --- SMTP Host & Port ---
        if (isset($input['smtp_host'])) {
            $output['smtp_host'] = sanitize_text_field($input['smtp_host']);
        }
        if (isset($input['smtp_port'])) {
            $output['smtp_port'] = (int) $input['smtp_port'];
        }

        // --- SMTP From (Validation Updated) ---
        if (isset($input['smtp_from_email'])) {
            // Allow empty string to pass through (resets to default logic)
            if (empty($input['smtp_from_email'])) {
                $output['smtp_from_email'] = '';
            } else {
                $clean_email = sanitize_email($input['smtp_from_email']);
                if (is_email($clean_email)) {
                    $output['smtp_from_email'] = $clean_email;
                } else {
                    // Add error and do NOT update the field (keep previous value)
                    add_settings_error(
                        self::OPTION_NAME,
                        'invalid_smtp_from_email',
                        __('Invalid "From Address" provided. Changes to this field were discarded.', 'wphaven-connect')
                    );
                }
            }
        }

        // --- SMTP From Name ---
        if (isset($input['smtp_from_name'])) {
            $output['smtp_from_name'] = sanitize_text_field($input['smtp_from_name']);
        }

        // --- General Settings ---
        $output['suppress_notices'] = isset($input['suppress_notices']) ? (bool) $input['suppress_notices'] : false;

        if (isset($input['suppress_notice_extra_strings'])) {
            $output['suppress_notice_extra_strings'] = sanitize_textarea_field($input['suppress_notice_extra_strings']);
        }

        if (isset($input['wphaven_api_base'])) {
            $output['wphaven_api_base'] = esc_url_raw($input['wphaven_api_base']);
        }

        if (isset($input['admin_login_slug'])) {
            $output['admin_login_slug'] = sanitize_text_field($input['admin_login_slug']);
        }

        // --- Elevated Emails ---
        $emails = [];
        if (isset($input['elevated_emails'])) {
            if (is_array($input['elevated_emails'])) {
                $emails = array_map('sanitize_email', $input['elevated_emails']);
            } else {
                $raw = is_string($input['elevated_emails']) ? $input['elevated_emails'] : '';
                $parts = preg_split('/[\r\n,;]+/', $raw);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if (!empty($p)) {
                        $emails[] = sanitize_email($p);
                    }
                }
            }
        }
        // Remove invalid emails
        $output['elevated_emails'] = array_values(array_filter($emails, 'is_email'));

        return $output;
    }

    private function getOptions()
    {
        return wp_parse_args(get_option(self::OPTION_NAME, []), [
            // Defaults
            'admin_login_slug' => 'em-login',
            'elevated_emails' => [],
            'mail_transport_mode' => 'no_override',
            'smtp_from_name' => 'Local Mailpit',
            'smtp_from_email' => 'admin@wordpress.local',
            'smtp_host' => 'mailpit',
            'smtp_port' => 1025,
            'suppress_notices' => true,
            'suppress_notice_extra_strings' => '',
            'wphaven_api_base' => '',
        ]);
    }

    /**
     * Generate a consistent HTML string for constant override messages
     *
     * @param string $constant_name The name of the constant (e.g., 'WPH_SMTP_HOST')
     * @param bool $is_inline Whether to use inline span or block p tag (default: false for block)
     * @return string HTML string with the constant override message
     */
    private function getConstantOverrideHtml($constant_name, $is_inline = false)
    {
        $message = wp_kses_post(
            sprintf(
                __('Locked by constant: <span class="constant-name">%s</span>', 'wphaven-connect'),
                $constant_name
            )
        );
        $class = 'description wph-const-override';

        if ($is_inline) {
            return '<span class="' . esc_attr($class) . '">' . $message . '</span>';
        }

        return '<p class="' . esc_attr($class) . '">' . $message . '</p>';
    }

    public function renderSettingsPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Security check for elevated users
        if (class_exists(ElevatedUsers::class) && !ElevatedUsers::currentIsElevated()) {
            wp_die(__('Unauthorized: You do not have permission to view this page.', 'wphaven-connect'));
        }

        $options = $this->getOptions();
        $admin_email = get_option('admin_email');
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html__('WP Haven Connect: Development Settings', 'wphaven-connect'); ?>
            </h1>

            <?php
            // Output standard settings errors (saved via add_settings_error)
            settings_errors(self::OPTION_NAME);

            // Show result of test email if present (Custom test action)
            if (isset($_GET['wphaven_connect_test'])):
                $code = sanitize_text_field(wp_unslash($_GET['wphaven_connect_test']));
                $msg = isset($_GET['wphaven_connect_message']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['wphaven_connect_message']))) : '';
                $message_text = esc_html($msg);

                if ($code === 'success'):
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php echo esc_html__('Test email sent successfully.', 'wphaven-connect'); ?>
                            <?php echo $message_text; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php echo esc_html__('Test email failed to send.', 'wphaven-connect'); ?>
                            <?php echo $message_text; ?>
                        </p>
                    </div>
                    <?php
                endif;
            endif;
            ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_NAME);
                do_settings_sections('wphaven-connect');
                submit_button();
                ?>
            </form>

            <hr>

            <?php // Test email form ?>
            <h2><?php echo esc_html__('Send test email', 'wphaven-connect'); ?></h2>
            <p class="description">
                <?php echo esc_html__('This sends a real email using the current configuration above.', 'wphaven-connect'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wphaven_connect_send_test_email', 'wphaven_connect_nonce'); ?>
                <input type="hidden" name="action" value="wphaven_connect_send_test_email">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wphaven_connect_test_email">
                                <?php echo esc_html__('Recipient Email', 'wphaven-connect'); ?>
                            </label>
                        </th>
                        <td>
                            <input id="wphaven_connect_test_email" name="wphaven_connect_test_email" type="email"
                                class="regular-text" value="<?php echo esc_attr($admin_email); ?>" required>
                            <?php submit_button(esc_html__('Send test email', 'wphaven-connect'), 'secondary', 'wphaven_connect_send_test_email', false); ?>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <?php
    }

    public function handleSendTestEmail()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'wphaven-connect'));
        }

        // Security check for elevated users
        if (class_exists(ElevatedUsers::class) && !ElevatedUsers::currentIsElevated()) {
            wp_die(__('Unauthorized: You do not have permission to perform this action.', 'wphaven-connect'));
        }

        $nonce = isset($_POST['wphaven_connect_nonce']) ? wp_unslash($_POST['wphaven_connect_nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce(sanitize_text_field($nonce), 'wphaven_connect_send_test_email')) {
            wp_die('Invalid Nonce');
        }

        $to = isset($_POST['wphaven_connect_test_email']) ? sanitize_email(wp_unslash($_POST['wphaven_connect_test_email'])) : '';

        $subject = 'WP Haven Connect: Test Email';
        $body = "This is a test email sent from the WP Haven Connect settings page to verify mail delivery.";

        // Get configured mail settings with proper defaults
        $opts = $this->getOptions();
        $from_address = !empty($opts['smtp_from_email']) ? $opts['smtp_from_email'] : 'admin@wordpress.local';
        $from_name = !empty($opts['smtp_from_name']) ? $opts['smtp_from_name'] : 'Local Mailpit';

        // Capture errors
        $mail_failed_msg = '';
        $failed_cb = function ($wp_error) use (&$mail_failed_msg) {
            if (is_wp_error($wp_error)) {
                $mail_failed_msg = $wp_error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $failed_cb);

        // Temporarily set from/from name for this test email
        $from_filter = function ($original_from) use ($from_address) {
            return $from_address;
        };
        $from_name_filter = function ($original_from_name) use ($from_name) {
            return $from_name;
        };
        add_filter('wp_mail_from', $from_filter);
        add_filter('wp_mail_from_name', $from_name_filter);

        $sent = wp_mail($to, $subject, $body);

        // Clean up temporary filters and actions
        remove_action('wp_mail_failed', $failed_cb);
        remove_filter('wp_mail_from', $from_filter);
        remove_filter('wp_mail_from_name', $from_name_filter);

        if ($sent) {
            $message = rawurlencode('Sent to ' . $to);
            $redirect = add_query_arg(['page' => 'wphaven-connect', 'wphaven_connect_test' => 'success', 'wphaven_connect_message' => $message], admin_url('options-general.php'));
        } else {
            $error_message = $mail_failed_msg ?: 'wp_mail returned false (Mail might be disabled)';
            $message = rawurlencode($error_message);
            $redirect = add_query_arg(['page' => 'wphaven-connect', 'wphaven_connect_test' => 'error', 'wphaven_connect_message' => $message], admin_url('options-general.php'));
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function renderMailTransportModeField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[mail_transport_mode]';
        $current_mode = $opts['mail_transport_mode'];

        // Check legacy constant first, then new namespaced constant
        $is_disabled_const = (defined('DISABLE_MAIL') && constant('DISABLE_MAIL')) ||
            (defined('WPH_DISABLE_MAIL') && constant('WPH_DISABLE_MAIL'));
        $is_smtp_const = defined('WPH_SMTP_OVERRIDE') && constant('WPH_SMTP_OVERRIDE');

        $readonly_attr = '';
        $override_message = '';

        if ($is_disabled_const) {
            $readonly_attr = 'disabled';
            $const_name = defined('WPH_DISABLE_MAIL') ? 'WPH_DISABLE_MAIL' : 'DISABLE_MAIL';
            $override_message = $this->getConstantOverrideHtml($const_name);
            $current_mode = 'block_all';
        } elseif ($is_smtp_const) {
            $readonly_attr = 'disabled';
            $override_message = $this->getConstantOverrideHtml('WPH_SMTP_OVERRIDE');
            $current_mode = 'smtp_override';
        }

        if (!$is_disabled_const && !$is_smtp_const && empty($opts['mail_transport_mode'])) {
            $env = wp_get_environment_type();
            if (in_array($env, ['development', 'local'])) {
                $override_message .= '<p class="description">' . esc_html__('Defaulting to Block All Mail due to non-production environment.', 'wphaven-connect') . '</p>';
            }
        }

        $modes = [
            'no_override' => __('No Mail Override (System Default)', 'wphaven-connect'),
            'smtp_override' => __('SMTP Override (Mailpit/Custom)', 'wphaven-connect'),
            'block_all' => __('Block All Mail', 'wphaven-connect'),
        ];

        echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__('Mail Delivery Mode', 'wphaven-connect') . '</span></legend>';
        foreach ($modes as $value => $label) {
            $checked = checked($value, $current_mode, false);
            ?>
            <label>
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"
                    class="wph-mode-selector" <?php echo $checked; ?>             <?php echo $readonly_attr; ?>>
                <?php echo esc_html($label); ?>
            </label><br>
            <?php
        }
        echo '</fieldset>';
        echo $override_message;
    }

    // SMTP Field Renders
    public function renderSmtpHostField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[smtp_host]';
        $is_const = defined('WPH_SMTP_HOST');
        $value = $is_const ? constant('WPH_SMTP_HOST') : $opts['smtp_host'];
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPH_SMTP_HOST') : '';

        echo sprintf(
            '<input type="text" name="%s" id="smtp_host" value="%s" placeholder="mailpit" class="regular-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
        echo '<p class="description">' . esc_html__('e.g., mailpit, mail.example.com', 'wphaven-connect') . '</p>';
    }

    public function renderSmtpPortField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[smtp_port]';
        $is_const = defined('WPH_SMTP_PORT');
        $value = $is_const ? constant('WPH_SMTP_PORT') : $opts['smtp_port'];
        // Ensure we display the default value if empty
        if (empty($value)) {
            $value = 1025;
        }
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPH_SMTP_PORT') : '';

        echo sprintf(
            '<input type="number" name="%s" id="smtp_port" value="%s" class="small-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
        echo '<p class="description">' . esc_html__('e.g., 1025 for Mailpit, 587 for TLS', 'wphaven-connect') . '</p>';
    }

    public function renderSmtpFromField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[smtp_from_email]';
        $is_const = defined('WPH_SMTP_FROM_EMAIL');
        $value = $is_const ? constant('WPH_SMTP_FROM_EMAIL') : $opts['smtp_from_email'];
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPH_SMTP_FROM_EMAIL') : '';

        echo sprintf(
            '<input type="email" name="%s" id="smtp_from_email" value="%s" placeholder="admin@wordpress.localhost" class="regular-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
    }

    public function renderSmtpFromNameField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[smtp_from_name]';
        $is_const = defined('WPH_SMTP_FROM_NAME');
        $value = $is_const ? constant('WPH_SMTP_FROM_NAME') : $opts['smtp_from_name'];
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPH_SMTP_FROM_NAME') : '';

        echo sprintf(
            '<input type="text" name="%s" id="smtp_from_name" value="%s" placeholder="Local Mailpit" class="regular-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
    }

    // General Fields
    public function renderSuppressNoticesField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[suppress_notices]';

        // Check legacy constant first, then new constant for backwards compatibility
        $is_legacy_const = defined('WPH_SUPPRESS_TEXTDOMAIN_NOTICES');
        $is_const = $is_legacy_const || defined('WPH_SUPPRESS_NOTICES');
        $const_name = $is_legacy_const ? 'WPH_SUPPRESS_TEXTDOMAIN_NOTICES' : 'WPH_SUPPRESS_NOTICES';

        // Use constant value if defined, otherwise use stored option
        $current_value = $is_const ? constant($const_name) : $opts['suppress_notices'];
        $checked = checked(1, $current_value, false);
        $readonly = $is_const ? 'disabled' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml($const_name) : '';

        // Add an ID to the checkbox so we can target it with JS
        $checkbox_id = 'wph_suppress_notices_checkbox';

        echo sprintf(
            '<label><input type="checkbox" id="%s" name="%s" value="1" %s %s></label>%s',
            esc_attr($checkbox_id),
            esc_attr($name),
            $checked,
            $readonly,
            $extra
        );
        echo '<p class="description">' . esc_html__('Suppress debug notices (including _load_textdomain_just_in_time warnings).', 'wphaven-connect') . '</p>';
    }

    public function renderSuppressNoticeExtraStringsField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[suppress_notice_extra_strings]';

        echo sprintf(
            '<textarea name="%s" id="suppress_notice_extra_strings" rows="3" class="large-text">%s</textarea>',
            esc_attr($name),
            esc_textarea($opts['suppress_notice_extra_strings'])
        );
        echo '<p class="description">' . esc_html__('Additional notices to suppress by matching strings (one per line, case-sensitive). Only applies when "Suppress debug notices" is enabled.', 'wphaven-connect') . '</p>';
    }

    public function renderApiBaseField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[wphaven_api_base]';
        $is_const = defined('WPHAVEN_API_BASE');
        $value = $is_const ? constant('WPHAVEN_API_BASE') : $opts['wphaven_api_base'];
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPHAVEN_API_BASE') : '';

        echo sprintf(
            '<input type="text" name="%s" value="%s" class="regular-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
    }

    public function renderAdminLoginSlugField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[admin_login_slug]';
        $is_const = defined('WPH_ADMIN_LOGIN_SLUG');
        $value = $is_const ? constant('WPH_ADMIN_LOGIN_SLUG') : $opts['admin_login_slug'];
        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' ' . $this->getConstantOverrideHtml('WPH_ADMIN_LOGIN_SLUG') : '';

        echo sprintf(
            '<input type="text" name="%s" value="%s" class="regular-text" %s>%s',
            esc_attr($name),
            esc_attr($value),
            $readonly,
            $extra
        );
        echo '<p class="description">' . esc_html__('Replaces the default login URL (/wp-login.php) with a custom slug to hide it from automated attacks.', 'wphaven-connect') . '</p>';
    }

    public function renderElevatedEmailsField()
    {
        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[elevated_emails]';
        $is_const = (defined('ELEVATED_EMAILS') && constant('ELEVATED_EMAILS')) || (defined('WP_ELEVATED_EMAILS') && constant('WP_ELEVATED_EMAILS'));

        if ($is_const) {
            // Check legacy first, then new
            $const_name = defined('ELEVATED_EMAILS') ? 'ELEVATED_EMAILS' : 'WP_ELEVATED_EMAILS';
            $const_val = constant($const_name);
            $value = is_array($const_val) ? implode("\n", $const_val) : (string) $const_val;
        } else {
            $const_name = ''; // Unused but initialized
            $value = is_array($opts['elevated_emails']) ? implode("\n", $opts['elevated_emails']) : '';
        }

        $readonly = $is_const ? 'readonly' : '';
        $extra = $is_const ? ' <br>' . $this->getConstantOverrideHtml($const_name, true) : '';

        echo sprintf(
            '<textarea name="%s" rows="4" class="large-text" %s>%s</textarea>',
            esc_attr($name),
            $readonly,
            esc_textarea($value)
        );
        echo '<p class="description">' . esc_html__('Enter one email per line.', 'wphaven-connect') . $extra . '</p>';
    }
}