<?php

namespace WPHavenConnect\Providers;

use PHPMailer\PHPMailer\PHPMailer;
use WP_CLI;
use WP_CLI_Command;

class DisableMailServiceProvider
{
    public function register()
    {
        add_action('plugins_loaded', [$this, 'initMailServices']);

        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCliCommands();
        }
    }

    public function initMailServices(): void
    {
        $mode = $this->getEffectiveMailMode();

        switch ($mode) {
            case 'block_all':
                add_filter('pre_wp_mail', function ($result, array $args) {
                    // Log context
                    error_log('[wphaven-connect] Mail blocked by WPHaven Connect settings (Mode: Block All). Preventing mail send.');

                    // Create the error object
                    $error = new \WP_Error('wphaven_mail_blocked', 'Mail sending is blocked by WPHaven Connect settings.');

                    // CRITICAL: Manually fire the failure action.
                    // Because we are short-circuiting wp_mail by returning false, 
                    // WordPress will not fire this action automatically. 
                    // This allows the listener to capture the error message.
                    do_action('wp_mail_failed', $error);

                    // Return false to indicate to wp_mail that sending failed
                    return false;
                }, 9999, 2);
                break;
            case 'smtp_override':
                // Override wp_mail_from early to prevent WordPress defaults
                add_filter('wp_mail_from', function () {
                    return $this->getSmtpFrom();
                }, 1);

                // Override wp_mail_from_name early to prevent WordPress defaults
                add_filter('wp_mail_from_name', function () {
                    return $this->getSmtpFromName();
                }, 1);

                add_action('phpmailer_init', function (PHPMailer $phpmailer) {
                    error_log('[wphaven-connect] SMTP override active (Mode: SMTP Override); configuring PHPMailer.');

                    $phpmailer->isSMTP();
                    $phpmailer->Host = $this->getSmtpHost();
                    $phpmailer->Port = $this->getSmtpPort();
                    $phpmailer->SMTPAuth = false;
                    $phpmailer->SMTPSecure = '';

                    $from = $this->getSmtpFrom();
                    $fromName = $this->getSmtpFromName();

                    // Only set From if it is a VALID email.
                    // Passing an empty string or invalid email to setFrom causes PHPMailer to throw an exception,
                    // which causes wp_mail to return false (generic "could not be sent" error).
                    if (!empty($from) && is_email($from)) {
                        try {
                            $phpmailer->setFrom($from, $fromName, false);
                        } catch (\Exception $e) {
                            error_log('[wphaven-connect] Failed to set FROM address: ' . $e->getMessage());
                        }
                    } else {
                        error_log('[wphaven-connect] PHPMailer override: Invalid or empty "From" address provided. Not setting From header.');
                    }
                }, 9999);
                break;
            case 'no_override':
            default:
                // We do nothing here; WordPress will use its default PHPMailer configuration.
                break;
        }
    }

    /**
     * Determines the active mail mode based on hierarchy:
     * 1. Constants (wp-config.php)
     * 2. Plugin Settings (DB)
     * 3. Environment Defaults
     * * @return string 'no_override'|'smtp_override'|'block_all'
     */
    public function getEffectiveMailMode(): string
    {
        // Constants take highest priority

        // Check legacy constant first, then new
        if (
            (defined('DISABLE_MAIL') && DISABLE_MAIL) ||
            (defined('WPH_DISABLE_MAIL') && WPH_DISABLE_MAIL)
        ) {
            return 'block_all';
        }

        if (defined('WPH_SMTP_OVERRIDE') && WPH_SMTP_OVERRIDE) {
            return 'smtp_override';
        }

        // Plugin Settings
        $opts = get_option('wphaven_connect_options', []);
        if (isset($opts['mail_transport_mode'])) {
            // Ensure value is valid
            $valid_modes = ['no_override', 'smtp_override', 'block_all'];
            if (in_array($opts['mail_transport_mode'], $valid_modes, true)) {
                return $opts['mail_transport_mode'];
            }
        }

        // Environment Defaults
        $env = wp_get_environment_type();

        // In 'local' environments, default to 'smtp_override' to automatically connect to Mailpit.
        if ('local' === $env) {
            return 'smtp_override';
        }

        // In 'staging' and 'development', block mail by default to prevent accidental sends.
        if (in_array($env, ['development', 'staging'], true)) {
            return 'block_all';
        }

        // For all other environments (like 'production'), use the default WordPress mail handler.
        return 'no_override';
    }

    private function getSmtpHost()
    {
        if (defined('WPH_SMTP_HOST')) {
            return constant('WPH_SMTP_HOST');
        }
        $opts = wp_parse_args(get_option('wphaven_connect_options', []), ['smtp_host' => 'mailpit']);
        return !empty($opts['smtp_host']) ? $opts['smtp_host'] : 'mailpit';
    }

    private function getSmtpPort()
    {
        if (defined('WPH_SMTP_PORT')) {
            return (int) constant('WPH_SMTP_PORT');
        }
        $opts = wp_parse_args(get_option('wphaven_connect_options', []), ['smtp_port' => 1025]);
        $port = !empty($opts['smtp_port']) ? (int) $opts['smtp_port'] : 1025;
        return (int) $port;
    }

    private function getSmtpFrom()
    {
        if (defined('WPH_SMTP_FROM_EMAIL')) {
            return constant('WPH_SMTP_FROM_EMAIL');
        }
        // Default to a valid email that PHPMailer will accept
        $opts = wp_parse_args(get_option('wphaven_connect_options', []), ['smtp_from_email' => 'admin@wordpress.local']);
        return !empty($opts['smtp_from_email']) ? $opts['smtp_from_email'] : 'admin@wordpress.local';
    }

    private function getSmtpFromName()
    {
        if (defined('WPH_SMTP_FROM_NAME')) {
            return constant('WPH_SMTP_FROM_NAME');
        }
        $opts = wp_parse_args(get_option('wphaven_connect_options', []), ['smtp_from_name' => 'WordPress']);
        return !empty($opts['smtp_from_name']) ? $opts['smtp_from_name'] : 'WordPress';
    }

    private function registerCliCommands()
    {
        $instance = $this;
        WP_CLI::add_command('mail test', new class ($instance) extends WP_CLI_Command {
            private $disableMailService;

            public function __construct($service)
            {
                parent::__construct();
                $this->disableMailService = $service;
            }

            public function __invoke($args, $assoc_args)
            {
                $to = $assoc_args['email'] ?? null;

                if (!is_email($to)) {
                    WP_CLI::error('Please provide a valid --email argument.');
                }

                // Check the current mail mode
                $mode = $this->disableMailService->getEffectiveMailMode();

                if ($mode === 'block_all') {
                    WP_CLI::success("Mail successfully blocked (Block All mode is active). No email was sent to {$to}.");
                    return;
                }

                $body = "This is a test email from the WP Haven CLI command to verify mail functionality.";

                // This will now pass through the filters set in initMailServices
                $result = wp_mail($to, 'Test Email from CLI', $body);

                if ($result) {
                    WP_CLI::success("Test email sent successfully to {$to}.");
                } else {
                    WP_CLI::error("Failed to send test email to {$to}.");
                }
            }
        });
    }
}