<?php

namespace WPHavenConnect\Providers;

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
        foreach ($this->providers as $provider) {
            (new $provider())->register();
        }
    }
}
