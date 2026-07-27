<?php

namespace WPHavenConnect\DatabaseTransfer;

/**
 * Serialized-data-safe search/replace, used to rewrite the source environment's
 * domain to the destination's after a table transfer.
 *
 * A naive `str_replace` over raw column values corrupts PHP-serialized data
 * because serialized strings encode their byte length (`s:23:"..."`). This
 * recurses through serialized structures — unserialize, replace, re-serialize —
 * so lengths stay correct, mirroring the well-known interconnect/it srdb
 * approach. The plain-text domain swap (including the protocol-relative `//host`
 * form) matches `ContentImporter::rewriteReferences`, but is serialization-aware.
 */
class SearchReplace
{
    /** @var array<int, array{0: string, 1: string}> search/replace pairs */
    private array $pairs = [];

    /** Shield ASSET_URL media from rewriting (URL mode only). */
    private bool $shieldAsset = true;

    /** Running count of individual string replacements in the last table pass. */
    private int $replacements = 0;

    /**
     * @param string|array<int, string> $from One or more source URLs to rewrite.
     */
    public function __construct($from, string $to)
    {
        $to = untrailingslashit($to);
        foreach ((array) $from as $source) {
            $this->addPair((string) $source, $to);
        }
    }

    /**
     * Build a plain literal search/replace (no URL/protocol-relative expansion,
     * no ASSET_URL shielding) — for the general Search & Replace tool.
     */
    public static function literal(string $search, string $replace): self
    {
        $instance = new self([], '');
        $instance->shieldAsset = false;
        if ($search !== '') {
            $instance->pairs = [[$search, $replace]];
        }

        return $instance;
    }

    private function addPair(string $from, string $to): void
    {
        $from = untrailingslashit($from);
        if ($from === '' || $from === $to) {
            return;
        }

        $this->pairs[] = [$from, $to];

        // Protocol-relative form: //source-host → //target-host.
        $from_rel = preg_replace('#^https?:#', '', $from);
        $to_rel   = preg_replace('#^https?:#', '', $to);
        if (is_string($from_rel) && $from_rel !== $from) {
            $this->pairs[] = [$from_rel, $to_rel];
        }
    }

    /**
     * Whether there is any replacement to perform.
     */
    public function hasWork(): bool
    {
        return ! empty($this->pairs);
    }

    /**
     * Recursively replace within a value, preserving serialization.
     *
     * @param mixed $data
     * @return mixed
     */
    public function replace($data)
    {
        if (is_string($data)) {
            if ($data !== '' && is_serialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    return serialize($this->replace($unserialized));
                }
            }
            return $this->replaceString($data);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[$key] = $this->replace($value);
            }
            return $out;
        }

        if (is_object($data)) {
            if ($data instanceof \__PHP_Incomplete_Class) {
                return $data;
            }
            try {
                $out = clone $data;
            } catch (\Throwable $e) {
                return $data;
            }
            foreach (get_object_vars($out) as $key => $value) {
                $out->$key = $this->replace($value);
            }
            return $out;
        }

        return $data;
    }

    private function replaceString(string $value): string
    {
        // Shield ASSET_URL-hosted media (served from production) so its URLs are
        // never rewritten to another environment. URL mode only.
        $token = '%%WPHAVEN_ASSET_URL%%';
        $asset = ($this->shieldAsset && defined('ASSET_URL') && ASSET_URL) ? rtrim(ASSET_URL, '/') : '';
        if ($asset !== '' && strpos($value, $asset) !== false) {
            $value = str_replace($asset, $token, $value);
        }

        foreach ($this->pairs as [$search, $replacement]) {
            if ($search !== '' && strpos($value, $search) !== false) {
                $this->replacements += substr_count($value, $search);
                $value = str_replace($search, $replacement, $value);
            }
        }

        if ($asset !== '') {
            $value = str_replace($token, $asset, $value);
        }

        return $value;
    }

    /**
     * Apply the replacement across every row of a table. Requires a
     * single-column primary key; tables without one are skipped. With
     * $dry_run = true it counts without writing.
     *
     * @param \wpdb $wpdb
     * @return array{rows: int, replacements: int}
     */
    public function replaceInTable($wpdb, string $table, ?string $primary_key, bool $dry_run = false): array
    {
        $this->replacements = 0;
        if (! $this->hasWork() || $primary_key === null) {
            return ['rows' => 0, 'replacements' => 0];
        }

        $changed = 0;
        $offset  = 0;
        $batch   = 500;

        do {
            // Table name is validated by the caller (TableRepository).
            $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch}", ARRAY_A);
            if (! is_array($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $update = [];
                foreach ($row as $column => $value) {
                    if ($column === $primary_key || ! is_string($value) || $value === '') {
                        continue;
                    }
                    $new = $this->replace($value);
                    if ($new !== $value) {
                        $update[$column] = $new;
                    }
                }
                if ($update) {
                    $changed++;
                    if (! $dry_run) {
                        $wpdb->update($table, $update, [$primary_key => $row[$primary_key]]);
                    }
                }
            }

            $count = count($rows);
            $offset += $batch;
        } while ($count === $batch);

        return ['rows' => $changed, 'replacements' => $this->replacements];
    }
}
