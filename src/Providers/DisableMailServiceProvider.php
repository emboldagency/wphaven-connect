<?php

namespace WPHavenConnect\Providers;

use PHPMailer\PHPMailer\PHPMailer;

class DisableMailServiceProvider
{
    public function register()
    {
        add_action('plugins_loaded', [$this, 'disableMailIfNeeded']);
    }

    public function disableMailIfNeeded()
    {
        $environmentsToDisableMail = ['development', 'local', 'staging', 'maintenance'];

        if (in_array(wp_get_environment_type(), $environmentsToDisableMail)) {
            add_filter('wp_mail', function($args) {
                // Return an empty email array to safely block outgoing mail
                return array_merge($args, [
                    'to'      => '',
                    'subject' => '',
                    'message' => '',
                    'headers' => '',
                    'attachments' => []
                ]);
            });

            // Kill PHPMailer directly (for rogue plugins bypassing wp_mail)
            add_action('phpmailer_init', function (PHPMailer $phpmailer) {
                $phpmailer->clearAllRecipients();
            });

            // Extra safety: Block wp_mail hook priority in case some plugin tries priority 1
            add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);

            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p>🚨 <strong>All emails are blocked in this environment. In order to send you must update the wp-config.php WP_ENVIRONMENT_TYPE to production.</strong> 🚨</p></div>';
            });
        }
    }
}
