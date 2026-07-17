<?php

namespace WPHavenConnect\Health;

/**
 * Surfaces recoverable PHP fatals that WordPress silently isolated.
 *
 * Since WP 5.2, a fatal in a plugin/theme trips "white screen protection":
 * WordPress catches it, PAUSES the offending extension (recording it), and keeps
 * the rest of the site loading -- so the site looks fine while a plugin is
 * quietly disabled and nobody notices. Reading the paused list turns that silent
 * state into a signal. (A hard fatal that takes down the whole site -- including
 * this endpoint -- is the complementary case an external uptime monitor catches;
 * this endpoint merely responding proves PHP still executes here.)
 *
 * PHP and WordPress versions are intentionally NOT reported here -- they are
 * already exposed by the /server-info and /php-info endpoints and by WordPress's
 * own Site Health screen, so repeating them would be redundant.
 *
 * Stateless read.
 */
class FatalHealthCollector implements HealthCollector
{
    public function key(): string
    {
        return 'fatals';
    }

    public function label(): string
    {
        return 'PHP fatal errors';
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $paused_plugins = function_exists('wp_paused_plugins')
            ? array_keys((array) wp_paused_plugins()->get_all())
            : [];

        $paused_themes = function_exists('wp_paused_themes')
            ? array_keys((array) wp_paused_themes()->get_all())
            : [];

        return [
            'paused_plugins' => array_values($paused_plugins),
            'paused_themes'  => array_values($paused_themes),
            'paused_count'   => count($paused_plugins) + count($paused_themes),
        ];
    }

    public function isHealthy(array $metrics): bool
    {
        // A paused plugin/theme means a fatal was caught and isolated -- the
        // silent-breakage case worth flagging.
        return (int) $metrics['paused_count'] === 0;
    }
}
