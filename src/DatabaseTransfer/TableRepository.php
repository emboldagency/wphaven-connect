<?php

namespace WPHavenConnect\DatabaseTransfer;

use WP_Error;

/**
 * Local database operations for table transfer, all keyed by a table's "base"
 * name (the part after `$wpdb->prefix`) so environments with different prefixes
 * still map to each other.
 *
 * The staging + atomic-rename dance lives here: rows are imported into a stage
 * table, then the live table is renamed aside as a backup and the stage renamed
 * into place — so the live table is never empty mid-transfer and a failure
 * leaves the original intact.
 *
 * SQL-injection guard: table names cannot be bound parameters, so every base is
 * validated against `[a-z0-9_]` AND confirmed to exist before it is ever
 * interpolated into a query.
 */
class TableRepository
{
    const STAGE_PREFIX = 'wphstage_';

    const BACKUP_PREFIX = 'wphbak_';

    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * List transferable tables (those carrying this site's prefix, excluding our
     * own stage/backup scratch tables).
     *
     * @return array<int, array{base: string, rows: int, size: int}>
     */
    public function listTransferableTables(): array
    {
        $prefix = $this->wpdb->prefix;
        $status = $this->wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (! is_array($status)) {
            return [];
        }

        $tables = [];
        foreach ($status as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ($prefix === '' || strpos($name, $prefix) !== 0) {
                continue;
            }
            $base = substr($name, strlen($prefix));
            if ($base === '' || $this->isScratchBase($base)) {
                continue;
            }

            $tables[] = [
                'base' => $base,
                'rows' => (int) ($row['Rows'] ?? 0),
                'size' => (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0),
            ];
        }

        usort($tables, static fn ($a, $b) => strcmp($a['base'], $b['base']));

        return $tables;
    }

    /**
     * Resolve a base name to its validated full table name, or a WP_Error if the
     * base is malformed or the table does not exist.
     *
     * @return string|WP_Error
     */
    public function resolveFull(string $base)
    {
        if (! preg_match('/^[a-z0-9_]+$/i', $base) || $this->isScratchBase($base)) {
            return new WP_Error('wphaven_db_bad_table', __('Invalid table name.', 'wphaven-connect'), ['status' => 400]);
        }

        $full = $this->wpdb->prefix . $base;
        if (! $this->tableExists($full)) {
            return new WP_Error('wphaven_db_no_table', __('Table does not exist on this site.', 'wphaven-connect'), ['status' => 404]);
        }

        return $full;
    }

    public function stageName(string $base): string
    {
        return $this->wpdb->prefix . self::STAGE_PREFIX . $base;
    }

    public function backupName(string $base): string
    {
        return $this->wpdb->prefix . self::BACKUP_PREFIX . $base;
    }

    /**
     * Exact row count for a validated full table name.
     */
    public function rowCount(string $full): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$full}`");
    }

    /**
     * Read a chunk of rows as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readChunk(string $full, int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, $limit);
        $rows   = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM `{$full}` LIMIT %d, %d", $offset, $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Create a fresh stage table cloned from the live table's structure.
     */
    public function createStageLike(string $base, string $full): void
    {
        $stage = $this->stageName($base);
        $this->wpdb->query("DROP TABLE IF EXISTS `{$stage}`");
        $this->wpdb->query("CREATE TABLE `{$stage}` LIKE `{$full}`");
    }

    /**
     * Insert a batch of rows into a table using a single prepared multi-row
     * INSERT. NULLs are preserved (emitted as literal NULL rather than '').
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function insertRows(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columns      = array_keys($rows[0]);
        $column_sql   = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '``', $c) . '`', $columns));
        $row_clauses  = [];
        $args         = [];

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    $placeholders[] = 'NULL';
                } else {
                    $placeholders[] = '%s';
                    $args[]         = $value;
                }
            }
            $row_clauses[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = "INSERT INTO `{$table}` ({$column_sql}) VALUES " . implode(', ', $row_clauses);

        // Only bind when there is at least one non-NULL placeholder.
        if (! empty($args)) {
            $sql = $this->wpdb->prepare($sql, $args);
        }

        $this->wpdb->query($sql);
    }

    /**
     * The single-column primary key of a table, or null when there is none or it
     * is composite.
     */
    public function primaryKey(string $full): ?string
    {
        $keys = $this->wpdb->get_results("SHOW KEYS FROM `{$full}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (! is_array($keys) || count($keys) !== 1) {
            return null;
        }

        return (string) ($keys[0]['Column_name'] ?? '') ?: null;
    }

    /**
     * Atomically swap the fully-imported stage table into place: the live table
     * becomes the backup, the stage becomes live. Any stale backup is dropped
     * first so the rename cannot collide.
     */
    public function atomicSwap(string $base, string $full): void
    {
        $stage  = $this->stageName($base);
        $backup = $this->backupName($base);

        $this->wpdb->query("DROP TABLE IF EXISTS `{$backup}`");
        $this->wpdb->query("RENAME TABLE `{$full}` TO `{$backup}`, `{$stage}` TO `{$full}`");
    }

    public function dropBackup(string $base): void
    {
        $backup = $this->backupName($base);
        $this->wpdb->query("DROP TABLE IF EXISTS `{$backup}`");
    }

    public function dropStage(string $base): void
    {
        $stage = $this->stageName($base);
        $this->wpdb->query("DROP TABLE IF EXISTS `{$stage}`");
    }

    public function wpdb(): \wpdb
    {
        return $this->wpdb;
    }

    private function tableExists(string $full): bool
    {
        $found = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $full)
        );

        return $found === $full;
    }

    private function isScratchBase(string $base): bool
    {
        return strpos($base, self::STAGE_PREFIX) === 0 || strpos($base, self::BACKUP_PREFIX) === 0;
    }
}
