<?php

namespace Lily\Storage;

/**
 * Class Filesystem
 *
 * Provides wrapper methods for common filesystem operations.
 *
 * @package Lily\Storage
 */
class Filesystem
{
    /**
     * Check if a file or directory exists.
     *
     * @param string $path The path to check.
     * @return bool True if it exists, false otherwise.
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Get the contents of a file.
     *
     * @param string $path The path to the file.
     * @return string|false The file contents or false on failure.
     */
    public function get(string $path): string|false
    {
        return file_get_contents($path);
    }

    /**
     * Write contents to a file.
     *
     * @param string $path The path to the file.
     * @param string $contents The contents to write.
     * @return int|false The number of bytes written, or false on failure.
     */
    public function put(string $path, string $contents): int|false
    {
        return file_put_contents($path, $contents);
    }

    /**
     * Delete a file.
     *
     * @param string $path The path to the file.
     * @return bool True on success, false on failure.
     */
    public function delete(string $path): bool
    {
        return unlink($path);
    }

    /**
     * Create a directory.
     *
     * @param string $path The path to the directory.
     * @param int $mode The permissions mode (default 0777).
     * @param bool $recursive Whether to create parent directories.
     * @return bool True on success, false on failure.
     */
    public function makeDirectory(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        return mkdir($path, $mode, $recursive);
    }
    
    /**
     * Move an uploaded file to a new location.
     *
     * @param string $from The path to the uploaded file.
     * @param string $to The destination path.
     * @return bool True on success, false on failure.
     */
    public function moveUploadedFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }
}
