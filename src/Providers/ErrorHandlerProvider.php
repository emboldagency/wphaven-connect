<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ErrorHandler;

class ErrorHandlerProvider
{
    public function register()
    {
        // Instantiate the error handler as early as possible - before plugins_loaded
        // to catch textdomain notices from plugins and themes
        add_action('muplugins_loaded', function () {
            if (class_exists('WPHavenConnect\\ErrorHandler')) {
                new ErrorHandler();
            }
        }, 0);
    }
}
