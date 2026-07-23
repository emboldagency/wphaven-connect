<?php

namespace WPHavenConnect\UploadsSync;

use WP_Error;

/**
 * Filesystem operations over the WordPress uploads directory for bulk media
 * sync: enumerate files, read/write byte ranges, and (critically) confine every
 * path to the uploads base directory so a crafted relative path can't escape it.
 *
 * Writes are additive — files are created or overwritten, never deleted — so the
 * production-asset deletion guard in AssetUrlServiceProvider is never engaged.
 */
class UploadsRepository
{
    private string $basedir;

    public function __construct()
    {
        $upload_dir    = wp_upload_dir();
        $this->basedir = untrailingslashit($upload_dir['basedir']);
    }

    /**
     * Directory names skipped anywhere in the tree.
     *
     * @return string[]
     */
    private function ignoredDirs(): array
    {
        return apply_filters('wphaven_uploads_sync_ignore', ['cache']);
    }

    /**
     * Enumerate every file under uploads as relative path + size + mtime.
     *
     * @return array<int, array{path: string, size: int, mtime: int}>
     */
    public function manifest(): array
    {
        if (! is_dir($this->basedir)) {
            return [];
        }

        $ignored = $this->ignoredDirs();
        $directory = new \RecursiveDirectoryIterator($this->basedir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, static function ($current) use ($ignored) {
            if ($current->isDir() && in_array($current->getFilename(), $ignored, true)) {
                return false;
            }
            return true;
        });
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($this->basedir)), '/\\'));
            if ($relative === '') {
                continue;
            }
            $files[] = [
                'path'  => $relative,
                'size'  => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
        }

        return $files;
    }

    /**
     * Select which source files need transferring: those the destination lacks,
     * plus (when overwriting) those whose size differs. Pure/testable.
     *
     * @param array<int, array{path: string, size: int, mtime?: int}> $source_files
     * @param array<string, int>                                      $destination_map path => size
     * @return array<int, array{path: string, size: int, mtime: int}>
     */
    public static function diff(array $source_files, array $destination_map, bool $overwrite): array
    {
        $plan = [];
        foreach ($source_files as $file) {
            $exists  = array_key_exists($file['path'], $destination_map);
            $differs = $exists && (int) $destination_map[$file['path']] !== (int) $file['size'];
            if (! $exists || ($overwrite && $differs)) {
                $plan[] = [
                    'path'  => $file['path'],
                    'size'  => (int) $file['size'],
                    'mtime' => (int) ($file['mtime'] ?? 0),
                ];
            }
        }

        return $plan;
    }

    /**
     * Read a byte range from a file.
     *
     * @return array{data: string, eof: bool, size: int}|WP_Error base64 data
     */
    public function readRange(string $relative, int $offset, int $length)
    {
        $full = $this->safePath($relative);
        if (is_wp_error($full)) {
            return $full;
        }
        if (! is_file($full)) {
            return new WP_Error('wphaven_uploads_missing', __('File not found.', 'wphaven-connect'), ['status' => 404]);
        }

        $size   = (int) filesize($full);
        $offset = max(0, $offset);
        $handle = fopen($full, 'rb');
        if (! $handle) {
            return new WP_Error('wphaven_uploads_read', __('Could not open file.', 'wphaven-connect'), ['status' => 500]);
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $bytes = $length > 0 ? (string) fread($handle, $length) : '';
        fclose($handle);

        return [
            'data' => base64_encode($bytes),
            'eof'  => ($offset + strlen($bytes)) >= $size,
            'size' => $size,
        ];
    }

    /**
     * Write a byte range to a file, creating directories as needed. Offset 0
     * truncates (fresh file / overwrite); later offsets append in place.
     *
     * @return true|WP_Error
     */
    public function writeRange(string $relative, int $offset, string $bytes)
    {
        $full = $this->safePath($relative);
        if (is_wp_error($full)) {
            return $full;
        }

        $dir = dirname($full);
        if (! wp_mkdir_p($dir)) {
            return new WP_Error('wphaven_uploads_mkdir', __('Could not create directory.', 'wphaven-connect'), ['status' => 500]);
        }

        // Symlink-escape guard: the created directory must still resolve inside uploads.
        $real_dir = realpath($dir);
        if ($real_dir === false || strpos($real_dir, realpath($this->basedir)) !== 0) {
            return new WP_Error('wphaven_uploads_escape', __('Refusing to write outside the uploads directory.', 'wphaven-connect'), ['status' => 400]);
        }

        $mode   = $offset === 0 ? 'wb' : 'cb';
        $handle = fopen($full, $mode);
        if (! $handle) {
            return new WP_Error('wphaven_uploads_write', __('Could not open file for writing.', 'wphaven-connect'), ['status' => 500]);
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        fwrite($handle, $bytes);
        fclose($handle);

        return true;
    }

    public function setMtime(string $relative, int $mtime): void
    {
        $full = $this->safePath($relative);
        if (! is_wp_error($full) && $mtime > 0 && is_file($full)) {
            @touch($full, $mtime);
        }
    }

    /**
     * Resolve a relative path to an absolute path confined within uploads, or a
     * WP_Error if it is malformed or tries to escape.
     *
     * @return string|WP_Error
     */
    public function safePath(string $relative)
    {
        if ($relative === '' || strpos($relative, "\0") !== false) {
            return new WP_Error('wphaven_uploads_bad_path', __('Invalid path.', 'wphaven-connect'), ['status' => 400]);
        }

        $relative = str_replace('\\', '/', $relative);
        if ($relative[0] === '/') {
            return new WP_Error('wphaven_uploads_bad_path', __('Absolute paths are not allowed.', 'wphaven-connect'), ['status' => 400]);
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return new WP_Error('wphaven_uploads_bad_path', __('Path traversal is not allowed.', 'wphaven-connect'), ['status' => 400]);
            }
            $segments[] = $segment;
        }
        if (empty($segments)) {
            return new WP_Error('wphaven_uploads_bad_path', __('Invalid path.', 'wphaven-connect'), ['status' => 400]);
        }

        return $this->basedir . '/' . implode('/', $segments);
    }
}
