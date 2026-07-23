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

    public function __construct(string $from, string $to)
    {
        $from = untrailingslashit($from);
        $to   = untrailingslashit($to);

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
        foreach ($this->pairs as [$search, $replacement]) {
            if ($search !== '' && strpos($value, $search) !== false) {
                $value = str_replace($search, $replacement, $value);
            }
        }
        return $value;
    }

    /**
     * Apply the replacement across every row of a table, updating only rows that
     * actually changed. Requires a single-column primary key; tables without one
     * are skipped (they hold no URLs in practice).
     *
     * @param \wpdb $wpdb
     * @return int Number of rows updated.
     */
    public function replaceInTable($wpdb, string $table, ?string $primary_key): int
    {
        if (! $this->hasWork() || $primary_key === null) {
            return 0;
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
                    $wpdb->update($table, $update, [$primary_key => $row[$primary_key]]);
                    $changed++;
                }
            }

            $count = count($rows);
            $offset += $batch;
        } while ($count === $batch);

        return $changed;
    }
}
