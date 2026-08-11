<?php

namespace WPHavenConnect\Providers;

/**
 * "Enable automatic minor core updates": lets WordPress install minor core
 * releases (security/maintenance patches) on its own, while keeping major and
 * development releases manual.
 *
 * WordPress refuses to auto-update a site it detects as a VCS checkout. Our
 * sites keep core out of git (it's gitignored) while wp-content is tracked, so
 * that detection is a false positive — the checkout it finds never contains the
 * files an update would touch. We tell core it isn't a checkout so security
 * patches land as soon as they ship.
 *
 * Controlled by the `auto_core_minor_updates` option (on by default), or the
 * WPH_AUTO_CORE_MINOR_UPDATES constant.
 */
class CoreAutoUpdatesProvider
{
    const OPTION_KEY = 'auto_core_minor_updates';

    const CONSTANT_NAME = 'WPH_AUTO_CORE_MINOR_UPDATES';

    public function register()
    {
        if (! self::enabled()) {
            return;
        }

        // Core is gitignored, so the repo it finds is not the code being updated.
        add_filter('automatic_updates_is_vcs_checkout', '__return_false');

        // These run after WP_AUTO_UPDATE_CORE is resolved, so they win over it.
        add_filter('allow_minor_auto_core_updates', '__return_true');
        add_filter('allow_major_auto_core_updates', '__return_false');
        add_filter('allow_dev_auto_core_updates', '__return_false');
    }

    /**
     * Constant wins, then the option, defaulting to on when never saved.
     */
    public static function enabled(): bool
    {
        if (defined(self::CONSTANT_NAME)) {
            return (bool) constant(self::CONSTANT_NAME);
        }

        $opts = get_option('wphaven_connect_options', []);

        if (! is_array($opts) || ! array_key_exists(self::OPTION_KEY, $opts)) {
            return true;
        }

        return ! empty($opts[self::OPTION_KEY]);
    }

    /**
     * Constants that switch the updater off wholesale, before our filters ever
     * run. Returned so the settings page can say the toggle won't take effect.
     *
     * @return string[]
     */
    public static function blockingConstants(): array
    {
        $blocking = [];

        if (defined('AUTOMATIC_UPDATER_DISABLED') && constant('AUTOMATIC_UPDATER_DISABLED')) {
            $blocking[] = 'AUTOMATIC_UPDATER_DISABLED';
        }

        if (defined('DISALLOW_FILE_MODS') && constant('DISALLOW_FILE_MODS')) {
            $blocking[] = 'DISALLOW_FILE_MODS';
        }

        return $blocking;
    }
}
