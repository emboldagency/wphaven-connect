<?php

namespace WPHavenConnect\Providers;

use PHPMailer\PHPMailer\PHPMailer;
use WP_CLI;
use WP_CLI_Command;

class DisableMailServiceProvider
{
    public function register()
    {
        add_action('plugins_loaded', [$this, 'disableMailIfNeeded']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('mail test', new class extends WP_CLI_Command {
                /**
                 * Send a test email to verify mail functionality.
                 *
                 * ## OPTIONS
                 *
                 * --email=<email>
                 * : The email address to send the test message to.
                 *
                 * ## EXAMPLES
                 *
                 *     wp mail test --email=xander@example.com
                 */
                public function __invoke($args, $assoc_args)
                {
                    $to = $assoc_args['email'] ?? null;

                    if (!is_email($to)) {
                        WP_CLI::error('Please provide a valid --email argument.');
                    }

                    // Construct the body with extra diagnostic info
                    $body = "This is a test email from the WP Haven CLI command to verify mail functionality.";

                    // Send the email
                    $result = wp_mail($to, 'Test Email from WP Haven', $body);

                    if ($result) {
                        WP_CLI::success("Test email sent to {$to}.");
                    } else {
                        WP_CLI::error("Failed to send test email to {$to}.");
                    }
                }
            });
        }
    }

    public function disableMailIfNeeded()
    {
        $environmentsToDisableMail = ['development', 'local', 'staging', 'maintenance'];

        // Skip disabling if WP_CLI is running and command is 'mail test'
        if (
            defined('WP_CLI') && WP_CLI &&
            isset($GLOBALS['argv'][1], $GLOBALS['argv'][2]) &&
            $GLOBALS['argv'][1] === 'mail' &&
            $GLOBALS['argv'][2] === 'test'
        ) {
            return;
        }

        // Allow Mailgun plugin test email via AJAX
        if (
            defined('DOING_AJAX') && DOING_AJAX &&
            isset($_GET['action']) && $_GET['action'] === 'mailgun-test'
        ) {
            return;
        }

        if (!defined('DISABLE_MAIL') || DISABLE_MAIL !== false) {
            if (in_array(wp_get_environment_type(), $environmentsToDisableMail)) {
                add_filter('wp_mail', function ($args) {
                    return array_merge($args, [
                        'to'          => '',
                        'subject'     => '',
                        'message'     => '',
                        'headers'     => '',
                        'attachments' => []
                    ]);
                });

                add_action('phpmailer_init', function (PHPMailer $phpmailer) {
                    $phpmailer->clearAllRecipients();
                });

                add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);

                add_action('admin_notices', function () {
                    echo '<div class="notice notice-warning"><p>🚨 <strong>All emails are blocked in this environment, except the CLI test and Mailgun configuration test. To send all emails, update WP_ENVIRONMENT_TYPE to "production".</strong> 🚨</p></div>';
                });
            }
        }
    }
}
