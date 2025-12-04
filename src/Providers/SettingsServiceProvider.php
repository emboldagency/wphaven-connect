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
        add_action('admin_init', [$this, 'handleReset']);

        // Handle test email submissions from the settings page
        add_action('admin_post_wphaven_connect_send_test_email', [$this, 'handleSendTestEmail']);
        // Enqueue settings page assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueSettingsAssets']);
    }

    public function handleReset()
    {
        if (isset($_POST['wphaven_reset_settings'])) {
            if (!current_user_can('manage_options')) {
                return;
            }

            if (!check_admin_referer('wphaven_reset_settings_action', 'wphaven_reset_nonce')) {
                return;
            }

            delete_option(self::OPTION_NAME);

            add_settings_error(
                self::OPTION_NAME,
                'wphaven_reset',
                __('Settings reset to defaults.', 'wphaven-connect'),
                'updated'
            );
        }
    }

    public function enqueueSettingsAssets($hook)
    {
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

        // CSS removed: Styles are now inline
    }

    public function addSettingsPage()
    {
        $is_elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();
        $is_admin = current_user_can('manage_options');

        // Require administrators who are also elevated users
        if (!$is_admin || !$is_elevated) {
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

        // --- SECTION: General Settings ---
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
        add_settings_field('admin_login_slug', __('Custom admin login slug', 'wphaven-connect'), [$this, 'renderAdminLoginSlugField'], 'wphaven-connect', 'wphaven_connect_general');
        add_settings_field('show_environment_indicator', __('Show environment badge', 'wphaven-connect'), [$this, 'renderCheckboxField'], 'wphaven-connect', 'wphaven_connect_general', ['key' => 'show_environment_indicator', 'desc' => __('Display the current environment (Development, Staging, Production) as a badge in the admin bar.', 'wphaven-connect'), 'const' => 'WPH_SHOW_ENVIRONMENT_INDICATOR']);

        // --- SECTION: Mail Configuration ---
        add_settings_section(
            'wphaven_connect_mail',
            __('Environment Mail Safeguards', 'wphaven-connect'),
            function () {
                echo '<p>' . esc_html__('Configure mail blocking behavior for non-production environments.', 'wphaven-connect') . '</p>';
            },
            'wphaven-connect'
        );

        // Mail Mode
        add_settings_field(
            'mail_mode',
            __('Mail Blocking Mode', 'wphaven-connect'),
            [$this, 'renderMailModeField'],
            'wphaven-connect',
            'wphaven_connect_mail'
        );
    }

    public function sanitize($input)
    {
        // Start with existing options so we don't wipe data if validation fails
        $output = get_option(self::OPTION_NAME, []);

        // --- General Settings ---
        if (isset($input['wphaven_api_base'])) {
            $output['wphaven_api_base'] = esc_url_raw($input['wphaven_api_base']);
        }

        if (isset($input['admin_login_slug'])) {
            $output['admin_login_slug'] = sanitize_text_field($input['admin_login_slug']);
        }

        // --- Environment Indicator ---
        if (!defined('WPH_SHOW_ENVIRONMENT_INDICATOR')) {
            $output['show_environment_indicator'] = isset($input['show_environment_indicator']);
        }

        // --- Mail Mode ---
        $valid_modes = ['auto', 'block_all', 'allow_all'];
        if (isset($input['mail_mode']) && in_array($input['mail_mode'], $valid_modes)) {
            $output['mail_mode'] = sanitize_text_field($input['mail_mode']);
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
            'admin_login_slug' => '',
            'elevated_emails' => [],
            'wphaven_api_base' => '',
            'show_environment_indicator' => true,
            'mail_mode' => 'auto', // Default to Auto (Safety Net active)
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
                __('Locked by constant: <code>%s</code>', 'wphaven-connect'),
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
        $is_elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();
        $is_admin = current_user_can('manage_options');

        if (!$is_admin || !$is_elevated) {
            wp_die(__('Unauthorized: You do not have permission to view this page.', 'wphaven-connect'));
        }

        $admin_email = get_option('admin_email');
        $permalink_structure = get_option('permalink_structure');

        // Prefer the current user's email for sending test messages; fall back to site admin email
        $current_user = wp_get_current_user();
        $current_user_email = isset($current_user->user_email) ? $current_user->user_email : '';
        $recipient_default = !empty($current_user_email) ? $current_user_email : $admin_email;
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html__('WP Haven Connect: Development Settings', 'wphaven-connect'); ?>
            </h1>

            <style>
                .wph-const-override {
                    color: var(--wp-admin-theme-color, #0073aa);
                    font-weight: 600;
                    margin-top: 4px;
                }

                .wph-const-override code {
                    font-weight: normal;
                }

                .embold-const-override {
                    color: var(--wp-admin-theme-color, #0073aa);
                    font-weight: 600;
                    margin-top: 4px;
                }
            </style>

            <?php
            // Check Permalinks
            if (empty($permalink_structure)) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php echo esc_html__('Warning:', 'wphaven-connect'); ?></strong>
                        <?php echo esc_html__('Permalinks are set to "Plain". The "Custom admin login slug" feature requires pretty permalinks (e.g., "Post name") to work correctly.', 'wphaven-connect'); ?>
                        <a
                            href="<?php echo esc_url(admin_url('options-permalink.php')); ?>"><?php echo esc_html__('Update Permalinks', 'wphaven-connect'); ?></a>
                    </p>
                </div>
                <?php
            }

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
                <?php echo esc_html__('This sends a real email to verify connectivity.', 'wphaven-connect'); ?>
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
                                class="regular-text" value="<?php echo esc_attr($recipient_default); ?>" required>
                            <?php submit_button(esc_html__('Send test email', 'wphaven-connect'), 'secondary', 'wphaven_connect_send_test_email', false); ?>
                        </td>
                    </tr>
                </table>
            </form>

            <hr style="margin-top: 40px; margin-bottom: 20px; border-color: #dcdcde;">
            <h2><?php echo esc_html__('Reset Settings', 'wphaven-connect'); ?></h2>
            <p><?php echo esc_html__('This will delete plugin options, reverting settings to defaults.', 'wphaven-connect'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('wphaven_reset_settings_action', 'wphaven_reset_nonce'); ?>
                <input type="hidden" name="wphaven_reset_settings" value="1">
                <?php
                submit_button(
                    __('Reset to Defaults', 'wphaven-connect'),
                    'delete',
                    'submit',
                    true,
                    ['onclick' => "return confirm('" . esc_js(__('Are you sure you want to reset all WP Haven Connect settings?', 'wphaven-connect')) . "');"]
                );
                ?>
            </form>
        </div>
        <?php
    }

    public function handleSendTestEmail()
    {
        $is_elevated = class_exists(ElevatedUsers::class) && ElevatedUsers::currentIsElevated();
        $is_admin = current_user_can('manage_options');

        if (!$is_admin || !$is_elevated) {
            wp_die(__('Unauthorized: You do not have permission to perform this action.', 'wphaven-connect'));
        }

        $nonce = isset($_POST['wphaven_connect_nonce']) ? wp_unslash($_POST['wphaven_connect_nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce(sanitize_text_field($nonce), 'wphaven_connect_send_test_email')) {
            wp_die('Invalid Nonce');
        }

        $to = isset($_POST['wphaven_connect_test_email']) ? sanitize_email(wp_unslash($_POST['wphaven_connect_test_email'])) : '';

        $subject = 'WP Haven Connect: Test Email';
        $body = "This is a test email sent from the WP Haven Connect settings page to verify mail delivery.";

        // Capture errors
        $mail_failed_msg = '';
        $failed_cb = function ($wp_error) use (&$mail_failed_msg) {
            if (is_wp_error($wp_error)) {
                $mail_failed_msg = $wp_error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $failed_cb);

        // Send mail relying on system configuration (managed by Embold, WP Haven Safety Net, or WP Defaults)
        $sent = wp_mail($to, $subject, $body);

        remove_action('wp_mail_failed', $failed_cb);

        if ($sent) {
            $message = rawurlencode('Sent to ' . $to);
            $redirect = add_query_arg(['page' => 'wphaven-connect', 'wphaven_connect_test' => 'success', 'wphaven_connect_message' => $message], admin_url('options-general.php'));
        } else {
            $error_message = $mail_failed_msg ?: 'wp_mail returned false (Mail likely blocked by Safety Net or Embold settings)';
            $message = rawurlencode($error_message);
            $redirect = add_query_arg(['page' => 'wphaven-connect', 'wphaven_connect_test' => 'error', 'wphaven_connect_message' => $message], admin_url('options-general.php'));
        }

        wp_safe_redirect($redirect);
        exit;
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
        echo '<p class="description">' . esc_html__('Base URL for the WP Haven API. Defaults to https://wphaven.app/api if empty.', 'wphaven-connect') . '</p>';
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
        // Reuse similar logic to Embold for constant checking
        $is_const = defined('ELEVATED_EMAILS') || defined('WP_ELEVATED_EMAILS');

        $value = $is_const ? '' : (is_array($opts['elevated_emails']) ? implode("\n", $opts['elevated_emails']) : '');
        // If const, display constant value nicely
        if ($is_const) {
            $const_name = defined('ELEVATED_EMAILS') ? 'ELEVATED_EMAILS' : 'WP_ELEVATED_EMAILS';
            $const_val = constant($const_name);
            $value = is_array($const_val) ? implode("\n", $const_val) : (string) $const_val;
        }

        $readonly = $is_const ? 'disabled' : '';
        $extra = $is_const ? $this->getConstantOverrideHtml($const_name) : '';

        echo sprintf(
            '<textarea name="%s" rows="4" class="large-text" %s>%s</textarea>',
            esc_attr($name),
            $readonly,
            esc_textarea($value)
        );
        echo '<p class="description">' . esc_html__('Enter one email per line. Users with info@embold.com or info@wphaven.app are automatically elevated.', 'wphaven-connect') . $extra . '</p>';
    }

    public function renderMailModeField()
    {
        // Check if Embold is installed
        if (class_exists('App\EmboldWordpressTweaks')) {
            $embold_url = admin_url('options-general.php?page=embold-wordpress-tweaks');
            echo '<div class="notice notice-info inline"><p>';
            echo sprintf(
                wp_kses_post(__('Mail settings are managed by <strong>Embold WordPress Tweaks</strong>. <a href="%s">Configure in Embold &rarr;</a>', 'wphaven-connect')),
                esc_url($embold_url)
            );
            echo '</p></div>';
            return;
        }

        $opts = $this->getOptions();
        $name = self::OPTION_NAME . '[mail_mode]';
        $current_mode = $opts['mail_mode'];

        $modes = [
            'auto' => __('Auto (Block Non-Production)', 'wphaven-connect'),
            'block_all' => __('Always Block Mail', 'wphaven-connect'),
            'allow_all' => __('Always Allow Mail (Disable Safeguards)', 'wphaven-connect'),
        ];

        echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__('Mail Blocking Mode', 'wphaven-connect') . '</span></legend>';
        foreach ($modes as $value => $label) {
            $checked = checked($value, $current_mode, false);
            echo "<label><input type='radio' name='{$name}' value='{$value}' {$checked}> " . esc_html($label) . "</label><br>";
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Determines if WP Haven should block outgoing emails.', 'wphaven-connect') . '</p>';
    }

    public function renderCheckboxField($args)
    {
        $key = $args['key'];
        $const = $args['const'] ?? null;
        $desc = $args['desc'] ?? '';

        $opts = $this->getOptions();

        // Determine if locked by constant
        $is_locked = false;
        $locked_const_name = null;
        if ($const && defined($const)) {
            $is_locked = true;
            $locked_const_name = $const;
            $is_checked = (bool) constant($const);
        } else {
            $is_checked = !empty($opts[$key]);
        }

        $name = self::OPTION_NAME . "[$key]";
        $disabled_attr = $is_locked ? 'disabled' : '';

        echo '<label>';
        echo sprintf(
            '<input type="checkbox" name="%s" value="1" %s %s> ',
            esc_attr($name),
            checked(1, $is_checked ? 1 : 0, false),
            $disabled_attr
        );

        if ($desc) {
            echo wp_kses_post($desc);
        }
        echo '</label>';

        if ($is_locked && $locked_const_name) {
            echo $this->getConstantOverrideHtml($locked_const_name);
        }
    }
}
