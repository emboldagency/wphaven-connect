<?php

namespace WPHavenConnect;

class ErrorHandler {

    public function __construct() {
        // Set global error and exception handlers
        set_error_handler([$this, 'handle_error']);
        set_exception_handler([$this, 'handle_exception']);
        register_shutdown_function([$this, 'handle_shutdown']);
    }

    public function handle_error($errno, $errstr, $errfile, $errline) {
        $error = compact('errno', 'errstr', 'errfile', 'errline');

        $this->send_slack_notification($error);

        // Return false for fatal errors so PHP shows them
        return !in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR]);
    }

    public function handle_exception($exception) {
        $error = [
            'errno' => E_ERROR,
            'errstr' => $exception->getMessage(),
            'errfile' => $exception->getFile(),
            'errline' => $exception->getLine()
        ];

        $this->send_slack_notification($error);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (php_sapi_name() !== 'cli') {
                $error_type = $this->get_error_type($error['type'] ?? $error['errno']);

                $message = $exception->getMessage();

                echo "<p><strong>{$error_type}</strong>: {$message}</p>";
                echo "<pre>{$exception->getTraceAsString()}</pre>";
            }
        }
    }

    public function handle_shutdown() {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
            $this->send_slack_notification($error);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (php_sapi_name() !== 'cli') {
                $error_type = $this->get_error_type($error['type'] ?? $error['errno']);

                $message = $error['message'];

                echo "<p><strong>{$error_type}</strong>: {$message}</p>";
            }
        } else {
            if (function_exists('wp_die')) {
                // Correctly trigger the standard WordPress error page
                wp_die(
                    __('There has been a critical error on this website.'),
                    __('Critical Error'),
                    ['response' => 500]
                );
            }
        }
    }

    private function send_slack_notification($error) {
        $error_type = $this->get_error_type($error['type'] ?? $error['errno']);

        // Only send the Slack notification if conditions are met
        if (
            isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) &&
            strpos($error_type, 'Error') !== false &&
            strpos($_SERVER['HTTP_HOST'], 'wphaven.dev') === false &&
            strpos($_SERVER['HTTP_HOST'], 'embold.net') === false
        ) {
            $message = [
                'domain' => $_SERVER['HTTP_HOST'],
                'url' => home_url($_SERVER['REQUEST_URI']),
                'error' => $error['message'] ?? $error['errstr'],
                'file' => $error['file'] ?? $error['errfile'],
                'line' => $error['line'] ?? $error['errline'],
                'type' => $error_type
            ];

            $response = wp_remote_post('https://wphaven.app/api/v1/wordpress/errors', [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($message)
            ]);
        }
    }

    private function get_error_type($errno) {
        $types = [
            E_ERROR             => 'Fatal Error',
            E_CORE_ERROR        => 'Core Error',
            E_COMPILE_ERROR     => 'Compile Error',
            E_USER_ERROR        => 'User Error',
            E_RECOVERABLE_ERROR => 'Recoverable Error'
        ];

        return $types[$errno] ?? 'Unknown Error';
    }
}
