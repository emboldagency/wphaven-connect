<?php

namespace WPHavenConnect\Providers;

use WP_CLI;
use WP_CLI_Command;
use WPHavenConnect\Utilities\Environment;

class DisableMailServiceProvider
{
    public function register()
    {
        add_action('plugins_loaded', [$this, 'initMailServices']);

        // Ensure only this plugin is controlling mail
        add_action('admin_init', [$this, 'disableConflictingMailPlugins']);

        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCliCommands();
        }
    }

    public function initMailServices(): void
    {
        // Check if Embold WordPress Tweaks is active.
        // If it is, we defer ALL mail control to it.
        if (class_exists('App\EmboldWordpressTweaks')) {
            return;
        }

        // Dev-Safe: If Embold is missing, we enforce a hard block on non-production.
        if ($this->shouldBlockMail()) {
            $this->blockMail();
        }
    }

    /**
     * Determine if we should block mail as a safety net.
     */
    private function shouldBlockMail(): bool
    {
        // Allow explicit disable via constant
        if (defined('DISABLE_MAIL') && constant('DISABLE_MAIL')) {
            return true;
        }

        // Check options if constant not set
        $opts = get_option('wphaven_connect_options', []);
        $mode = $opts['mail_mode'] ?? 'auto';

        if ($mode === 'allow_all') {
            return false;
        }

        if ($mode === 'block_all') {
            return true;
        }

        // Default Auto: Check environment
        $is_prod = function_exists('wp_get_environment_type')
            ? wp_get_environment_type() === 'production'
            : Environment::is_production();

        return !$is_prod;
    }

    private function blockMail(): void
    {
        add_filter('pre_wp_mail', function ($result, $args = []) {
            // Log context
            error_log('[wphaven-connect] Mail disabled: Mail blocked because environment is non-production and Embold Tweaks is not active.');

            $error = new \WP_Error('wphaven_mail_blocked', 'Mail sending is blocked by WPHaven Connect safety net.');
            do_action('wp_mail_failed', $error);

            return false;
        }, 9999, 2);
    }

    /**
     * Deactivates known mail plugins that might bypass our block logic.
     * Only runs if we are actively blocking mail.
     */
    public function disableConflictingMailPlugins()
    {
        // If Embold is active, let it handle plugins (or not)
        if (class_exists('App\EmboldWordpressTweaks')) {
            return;
        }

        // If we aren't blocking mail, no need to disable plugins
        if (!$this->shouldBlockMail()) {
            return;
        }

        if (!current_user_can('activate_plugins')) {
            return;
        }

        $plugins_to_disable = [
            'mailgun/mailgun.php',
            'sparkpost/sparkpost.php',
            'wp-mail-smtp/wp_mail_smtp.php',
            'easy-wp-smtp/easy-wp-smtp.php',
        ];

        $active_conflicts = array_filter($plugins_to_disable, 'is_plugin_active');

        if (!empty($active_conflicts)) {
            deactivate_plugins($active_conflicts);

            // Add an admin notice
            add_action('admin_notices', function () use ($active_conflicts) {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>WPHaven Connect notice:</strong> Mail plugins were disabled because mail blocking is active on this non-production environment: ' . implode(', ', $active_conflicts);
                echo '</p></div>';
            });
        }
    }

    private function registerCliCommands()
    {
        // Kept a simple test command for connectivity checks
        WP_CLI::add_command('wphaven test-mail', function ($args, $assoc_args) {
            $to = $assoc_args['email'] ?? null;

            if (!is_email($to)) {
                WP_CLI::error('Please provide a valid --email argument.');
            }

            if (class_exists('App\EmboldWordpressTweaks')) {
                WP_CLI::log('Notice: Embold WordPress Tweaks is managing mail delivery.');
            } elseif ($this->shouldBlockMail()) {
                WP_CLI::warning('Environment Safeguards Active: Mail is currently blocked by WP Haven Connect.');
                return;
            }

            $body = "This is a test email from the WP Haven CLI command.";
            $result = wp_mail($to, 'Test Email from CLI', $body);

            if ($result) {
                WP_CLI::success("Test email sent successfully to {$to}.");
            } else {
                WP_CLI::error("Failed to send test email to {$to}. Check debug.log for details.");
            }
        });
    }
}