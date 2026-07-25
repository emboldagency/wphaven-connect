<?php

namespace WPHavenConnect\Compare;

use WPHavenConnect\ContentTransfer\ContentIdentity;
use WPHavenConnect\DatabaseTransfer\TableRepository;
use WPHavenConnect\UploadsSync\UploadsRepository;

/**
 * Gathers the comparison signals for one site (run locally, and on the peer via
 * REST): exact table row counts, an uploads summary, and a per-post content
 * fingerprint. Also the pure diff helpers that turn "this site" + "that site"
 * into the Compare tab's numbers.
 *
 * Posts are matched across environments by their WPHaven content id when one
 * exists (authoritative), otherwise by post ID — which lines up for
 * environments that share a common database origin (clones), the normal case.
 */
class CompareRepository
{
    /**
     * Exact row counts for every transferable table.
     *
     * @return array<int, array{base: string, rows: int}>
     */
    public function tableStats(): array
    {
        $repo = new TableRepository();
        $out  = [];
        foreach ($repo->listTransferableTables() as $table) {
            $full = $repo->resolveFull($table['base']);
            $out[] = [
                'base' => $table['base'],
                'rows' => is_wp_error($full) ? 0 : $repo->rowCount($full),
            ];
        }

        return $out;
    }

    /**
     * File count and total bytes under uploads.
     *
     * @return array{files: int, bytes: int}
     */
    public function uploadsStats(): array
    {
        $files = (new UploadsRepository())->manifest();
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int) $file['size'];
        }

        return ['files' => count($files), 'bytes' => $bytes];
    }

    /**
     * A fingerprint per public post: a match key + post type + a content hash.
     * The hash is computed in SQL to avoid pulling every post's body into PHP.
     *
     * @return array<int, array{key: string, type: string, hash: string}>
     */
    public function contentFingerprints(): array
    {
        global $wpdb;

        $types = array_values(array_diff(get_post_types(['public' => true], 'names'), ['attachment']));
        if (empty($types)) {
            return [];
        }
        $statuses = ['publish', 'future', 'draft', 'pending', 'private'];

        $type_ph   = implode(', ', array_fill(0, count($types), '%s'));
        $status_ph = implode(', ', array_fill(0, count($statuses), '%s'));

        $sql = $wpdb->prepare(
            "SELECT ID, post_type,
                MD5(CONCAT_WS(0x1f, COALESCE(post_title,''), COALESCE(post_content,''), COALESCE(post_excerpt,''), COALESCE(post_status,''))) AS hash
             FROM {$wpdb->posts}
             WHERE post_type IN ({$type_ph}) AND post_status IN ({$status_ph})",
            array_merge($types, $statuses)
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows) || empty($rows)) {
            return [];
        }

        // Pull content ids in one query so transferred posts match authoritatively.
        $ids = array_map('intval', array_column($rows, 'ID'));
        $in  = implode(',', $ids);
        $cids = [];
        $metas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$in})",
                ContentIdentity::META_KEY
            ),
            ARRAY_A
        );
        if (is_array($metas)) {
            foreach ($metas as $meta) {
                if (! empty($meta['meta_value'])) {
                    $cids[(int) $meta['post_id']] = $meta['meta_value'];
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $id  = (int) $row['ID'];
            $key = isset($cids[$id]) ? 'cid:' . $cids[$id] : 'id:' . $id;
            $out[] = ['key' => $key, 'type' => (string) $row['post_type'], 'hash' => (string) $row['hash']];
        }

        return $out;
    }

    // --- Pure diff helpers ----------------------------------------------------

    /**
     * @param array<int, array{base: string, rows: int}> $local
     * @param array<int, array{base: string, rows: int}> $remote
     * @return array<int, array{base: string, here: ?int, there: ?int, delta: int}>
     */
    public static function diffTables(array $local, array $remote): array
    {
        $l = [];
        foreach ($local as $t) {
            $l[$t['base']] = (int) $t['rows'];
        }
        $r = [];
        foreach ($remote as $t) {
            $r[$t['base']] = (int) $t['rows'];
        }

        $bases = array_values(array_unique(array_merge(array_keys($l), array_keys($r))));
        sort($bases);

        $out = [];
        foreach ($bases as $base) {
            $here  = $l[$base] ?? null;
            $there = $r[$base] ?? null;
            $out[] = [
                'base'  => $base,
                'here'  => $here,
                'there' => $there,
                'delta' => ($here ?? 0) - ($there ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Per-post-type divergence between two fingerprint sets.
     *
     * @param array<int, array{key: string, type: string, hash: string}> $local
     * @param array<int, array{key: string, type: string, hash: string}> $remote
     * @return array<int, array{type: string, here: int, there: int, differ: int, only_here: int, only_there: int}>
     */
    public static function diffContent(array $local, array $remote): array
    {
        $lm = [];
        foreach ($local as $f) {
            $lm[$f['key']] = $f;
        }
        $rm = [];
        foreach ($remote as $f) {
            $rm[$f['key']] = $f;
        }

        $types = [];
        $ensure = static function (array &$types, string $type): void {
            if (! isset($types[$type])) {
                $types[$type] = ['type' => $type, 'here' => 0, 'there' => 0, 'differ' => 0, 'only_here' => 0, 'only_there' => 0];
            }
        };

        foreach ($lm as $f) {
            $ensure($types, $f['type']);
            $types[$f['type']]['here']++;
        }
        foreach ($rm as $f) {
            $ensure($types, $f['type']);
            $types[$f['type']]['there']++;
        }

        $keys = array_unique(array_merge(array_keys($lm), array_keys($rm)));
        foreach ($keys as $key) {
            $lf = $lm[$key] ?? null;
            $rf = $rm[$key] ?? null;
            if ($lf && $rf) {
                if ($lf['hash'] !== $rf['hash']) {
                    $ensure($types, $lf['type']);
                    $types[$lf['type']]['differ']++;
                }
            } elseif ($lf) {
                $ensure($types, $lf['type']);
                $types[$lf['type']]['only_here']++;
            } elseif ($rf) {
                $ensure($types, $rf['type']);
                $types[$rf['type']]['only_there']++;
            }
        }

        ksort($types);

        return array_values($types);
    }
}
