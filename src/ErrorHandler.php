<?php

namespace WPHavenConnect;

use WPHavenConnect\Utilities\Environment;

class ErrorHandler
{

    private $suppress_textdomain_notice = false;


    public function __construct()
    {
        $this->register_error_handlers();
    }

    private function register_error_handlers()
    {
        // Suppress _load_textdomain_just_in_time doing_it_wrong notices if enabled
        // Can be controlled via WPH_SUPPRESS_TEXTDOMAIN_NOTICES constant in wp-config.php
        // Defaults to true in development environment
        $suppress_textdomain = defined('WPH_SUPPRESS_TEXTDOMAIN_NOTICES') ? WPH_SUPPRESS_TEXTDOMAIN_NOTICES : Environment::is_development();


        if ($suppress_textdomain) {
            add_filter('doing_it_wrong_trigger_error', function ($status, $function_name) {
                if ('_load_textdomain_just_in_time' === $function_name) {
                    return false;
                }
                return $status;
            }, 10, 2);
        }

        // Also hook into doing_it_wrong_run to log all "doing it wrong" calls for debugging
        add_action('doing_it_wrong_run', function ($function, $message, $version) {

            // If this is a textdomain-related notice and we should suppress it, note that
            if (strpos($message, '_load_textdomain_just_in_time') !== false || strpos($message, 'textdomain') !== false) {
                $should_suppress = defined('WPH_SUPPRESS_TEXTDOMAIN_NOTICES') ? WPH_SUPPRESS_TEXTDOMAIN_NOTICES : Environment::is_development();
            }
        }, 10, 3);

        set_error_handler([$this, 'handle_error']);
        set_exception_handler([$this, 'handle_exception']);
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    public function handle_error($errno, $errstr, $errfile, $errline)
    {
        $error = compact('errno', 'errstr', 'errfile', 'errline');

        $this->send_slack_notification($error);

        // Return false for fatal errors so PHP shows them
        return !in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR]);
    }

    public function handle_exception($exception)
    {
        $error = [
            'errno' => E_ERROR,
            'errstr' => $exception->getMessage(),
            'errfile' => $exception->getFile(),
            'errline' => $exception->getLine()
        ];

        $this->send_slack_notification($error);

        throw $exception;
    }

    public function handle_shutdown()
    {
        $error = error_get_last();

        // Only consider these "fatal" like WordPress Core does
        $fatal_error_types = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_USER_ERROR,
            E_COMPILE_ERROR,
            E_RECOVERABLE_ERROR
        ];

        if ($error && in_array($error['type'], $fatal_error_types)) {
            $this->send_slack_notification($error);
        }
    }

    private function should_notify_slack(): bool
    {
        if (function_exists('get_transient') && function_exists('set_transient')) {
            $key = 'wphaven_slack_error_sent';

            if (get_transient($key)) {
                return false;
            }

            // Set throttle lock for 5 minutes
            set_transient($key, true, 5 * MINUTE_IN_SECONDS);
        }

        return true;
    }

    private function send_slack_notification($error)
    {
        $error_type = $this->get_error_type($error['type'] ?? $error['errno']);

        // Only send the Slack notification if conditions are met
        if (
            isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) &&
            strpos($error_type, 'Error') !== false &&
            strpos($_SERVER['HTTP_HOST'], 'wphaven.dev') === false &&
            (strpos($_SERVER['HTTP_HOST'], 'haventest') !== false || strpos($_SERVER['HTTP_HOST'], 'embold.net') === false)
        ) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $bot_likelihood = $this->get_bot_likelihood($user_agent);

            $message = [
                'domain' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
                'url' => isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : home_url(),
                'error' => $error['message'] ?? $error['errstr'],
                'file' => $error['file'] ?? $error['errfile'],
                'line' => $error['line'] ?? $error['errline'],
                'type' => $error_type,
                'user_agent' => $user_agent,
                'bot_likelihood' => $bot_likelihood,
                'referrer' => $_SERVER['HTTP_REFERER'] ?? 'None',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'wp_memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB / ' .
                    (defined('WP_MEMORY_LIMIT') ? round(wp_convert_hr_to_bytes(WP_MEMORY_LIMIT) / 1024 / 1024, 2) . 'MB' : 'Unknown'),
                'execution_time' => isset($_SERVER["REQUEST_TIME_FLOAT"])
                    ? round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 4) . 's'
                    : 'Unknown',
            ];

            if ($this->should_notify_slack()) {
                $response = wp_remote_post('https://wphaven.app/api/v1/wordpress/errors', [
                    'method' => 'POST',
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode($message)
                ]);
            }
        }
    }

    private function get_bot_likelihood($user_agent)
    {
        $known_bots = [
            'googlebot',
            'bingbot',
            'yandexbot',
            'duckduckbot',
            'baiduspider',
            'semrushbot',
            'ahrefsbot',
            'facebookexternalhit',
            'twitterbot',
            'slackbot',
            'discordbot',
            'linkedinbot',
            'gptbot',
            'chatgpt',
            'claude',
            'crawler',
            'spider',
            'bot'
        ];

        $likely_bots = [];

        $user_agent_lower = strtolower($user_agent);

        if (preg_match('/(' . implode('|', $known_bots) . ')/i', $user_agent_lower)) {
            return 'Bot';
        }

        return 'Likely Human';
    }

    private function get_error_type($errno)
    {
        $types = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        return $types[$errno] ?? 'Unknown Err Type';
    }
}
