<?php

namespace WPHavenConnect\Health;

/**
 * Scrubs error strings before they leave the site, whether in the /health JSON
 * or rendered on the Site Health screen.
 *
 * Two jobs. It strips absolute filesystem paths, which disclose the account
 * layout to anyone who can read a response. And it defuses the error signatures
 * that OWASP CRS Phase 4 outbound rules (RESPONSE-950/951/953) match on -- a
 * ModSecurity install in front of the site will otherwise block our own health
 * response as a data leak, taking the whole payload with it.
 *
 * Shared by the collector that records errors and the screen that displays them,
 * for the same reason HealthCollectorRegistry is shared: the two surfaces must
 * not drift, and a subtly wrong regex fixed in one copy but not the other is
 * exactly how that happens.
 */
class ErrorSanitizer
{
    /**
     * Pattern => replacement, applied in order.
     *
     * The path patterns need a literal backslash inside a character class, which
     * is `\\\\` in a single-quoted PHP string ('\\\\' -> regex `\\` -> one
     * backslash). Writing it as '\\' yields regex `\]`, which escapes the
     * closing bracket and fails to compile -- silently returning null from
     * preg_replace and erasing the entire message.
     *
     * The `(?<![\w:/])` lookbehind keeps URLs intact: it refuses to start a
     * match on a slash preceded by a word character, a colon, or another slash,
     * so "https://example.com/wiki" survives while a bare "/var/www/x" does not.
     *
     * @var array<string, string>
     */
    private const RULES = [
        // Absolute paths, longest form first so a .php file keeps its extension.
        '#(?<![\w:/])[/\\\\][\w\-./\\\\]*\.php\b#i' => '[file.php]',
        '#(?<![\w:/])[/\\\\][\w\-./\\\\]{4,}#'      => '[path]',

        // Signatures the CRS outbound rules treat as evidence of a leak.
        '/\b(fatal\s+error|parse\s+error|uncaught\s+exception|warning|notice)\b/i' => 'error',
        '/(\beval\(\)|\bsql\s+syntax\b|\bselect\b.*?\bfrom\b)/i'                   => '[detail]',
    ];

    /**
     * Sanitize a single error string. Returns '' for null/empty input.
     */
    public static function clean(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $cleaned = sanitize_text_field($text);

        foreach (self::RULES as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $cleaned);

            // A pattern that fails to compile returns null. Keep the last good
            // value rather than propagating null and losing the message.
            if ($result !== null) {
                $cleaned = $result;
            }
        }

        return trim($cleaned);
    }
}
