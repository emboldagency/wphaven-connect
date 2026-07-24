<?php

namespace WPHavenConnect\ContentTransfer;

/**
 * The modular list of transfer peers (production, staging, maintenance, and any
 * extras like "new"/"old" during a server move). Stored under the
 * `wphaven_connect_options['environments']` key as rows of {label, url}.
 *
 * Labels are always normalised to lowercase so "Production" and "production"
 * can't coexist, and the label `production` is what marks the destination whose
 * overwrites require the typed confirmation phrase.
 */
class Environments
{
    const OPTION = 'wphaven_connect_options';

    const KEY = 'environments';

    const PRODUCTION_LABEL = 'production';

    /**
     * The normalised environment list.
     *
     * @return array<int, array{label: string, url: string, is_production: bool}>
     */
    public static function all(): array
    {
        $opts = get_option(self::OPTION, []);
        $list = is_array($opts) && isset($opts[self::KEY]) && is_array($opts[self::KEY]) ? $opts[self::KEY] : [];

        return self::normalize($list);
    }

    /**
     * @return string[]
     */
    public static function labels(): array
    {
        return array_map(static fn ($e) => $e['label'], self::all());
    }

    public static function urlFor(string $label): ?string
    {
        $label = self::cleanLabel($label);
        foreach (self::all() as $environment) {
            if ($environment['label'] === $label) {
                return $environment['url'];
            }
        }

        return null;
    }

    public static function isProductionLabel(string $label): bool
    {
        return self::cleanLabel($label) === self::PRODUCTION_LABEL;
    }

    /**
     * Normalise a raw list: lowercase labels, coerce URLs, drop incomplete rows,
     * and de-duplicate by label (last one wins). Pure/testable.
     *
     * @param array<int, mixed> $raw
     * @return array<int, array{label: string, url: string, is_production: bool}>
     */
    public static function normalize(array $raw): array
    {
        $by_label = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = self::cleanLabel($row['label'] ?? '');
            $url   = self::cleanUrl($row['url'] ?? '');
            if ($label === '' || $url === '') {
                continue;
            }
            $by_label[$label] = [
                'label'         => $label,
                'url'           => $url,
                'is_production' => $label === self::PRODUCTION_LABEL,
            ];
        }

        return array_values($by_label);
    }

    /**
     * Merge populate results into an existing list: rows whose label already
     * exists are updated in place; other existing rows (e.g. "new"/"old") are
     * kept. Incoming rows may carry `label`/`url` or `stage`/`domain`. Pure.
     *
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $incoming
     * @return array<int, array{label: string, url: string, is_production: bool}>
     */
    public static function merge(array $existing, array $incoming): array
    {
        $map = [];
        foreach (self::normalize($existing) as $environment) {
            $map[$environment['label']] = $environment;
        }

        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = self::cleanLabel($row['label'] ?? ($row['stage'] ?? ''));
            $url   = self::cleanUrl($row['url'] ?? ($row['domain'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $map[$label] = [
                'label'         => $label,
                'url'           => $url,
                'is_production' => $label === self::PRODUCTION_LABEL,
            ];
        }

        return array_values($map);
    }

    public static function cleanLabel(string $label): string
    {
        return strtolower(trim(sanitize_text_field($label)));
    }

    /**
     * Coerce a domain or URL into a normalised https URL (or '' if empty).
     */
    private static function cleanUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return untrailingslashit(esc_url_raw($value));
    }
}
