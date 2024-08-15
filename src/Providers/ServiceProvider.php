<?php

namespace WPHavenConnect\Providers;

use WPHavenConnect\ErrorHandler;

class ServiceProvider {

    private $providers = [
        CookieServiceProvider::class,
        WordfenceServiceProvider::class,
        CommandLineServiceProvider::class,
    ];

    public function __construct() {
        $this->register();
    }

    public function register() {
        // Initialize the ErrorHandler first to catch any errors
        new ErrorHandler();

        // Register other service providers
        foreach ($this->providers as $provider) {
            (new $provider())->register();
        }
    }
}
