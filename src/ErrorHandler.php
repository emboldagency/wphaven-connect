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
        // $this->send_slack_notification($error);

        // Optionally rethrow the error to be caught by shutdown function
        if ($errno === E_ERROR) {
            return false;
        }
        return true;
    }

    public function handle_exception($exception) {
        $error = [
            'errno' => E_ERROR,
            'errstr' => $exception->getMessage(),
            'errfile' => $exception->getFile(),
            'errline' => $exception->getLine()
        ];
        // $this->send_slack_notification($error);
    }

    public function handle_shutdown() {
        $error = error_get_last();

        if (isset($_SERVER['HTTP_HOST']) && $error) {
            // This will catch syntax errors, fatal errors, etc.
            // $this->send_slack_notification($error);
        }
    }

    private function send_slack_notification($error) {
        // Only send the Slack notification if HTTP_HOST and REQUEST_URI are defined
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $message = [
                'domain' => $_SERVER['HTTP_HOST'],
                'url' => home_url($_SERVER['REQUEST_URI']),
                'error' => $error['message'] ?? $error['errstr'],
                'file' => $error['file'] ?? $error['errfile'],
                'line' => $error['line'] ?? $error['errline'],
                'type' => $this->get_error_type($error['type'] ?? $error['errno'])
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
            E_WARNING           => 'Warning',
            E_PARSE             => 'Parse Error',
            E_NOTICE            => 'Notice',
            E_CORE_ERROR        => 'Core Error',
            E_CORE_WARNING      => 'Core Warning',
            E_COMPILE_ERROR     => 'Compile Error',
            E_COMPILE_WARNING   => 'Compile Warning',
            E_USER_ERROR        => 'User Error',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_STRICT            => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
        ];

        return $types[$errno] ?? 'Unknown Error';
    }
}
