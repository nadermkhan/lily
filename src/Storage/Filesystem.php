<?php

namespace Lily\Storage;

class Filesystem
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function get(string $path): string|false
    {
        return file_get_contents($path);
    }

    public function put(string $path, string $contents): int|false
    {
        return file_put_contents($path, $contents);
    }

    public function delete(string $path): bool
    {
        return unlink($path);
    }

    public function makeDirectory(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        return mkdir($path, $mode, $recursive);
    }
    
    public function moveUploadedFile(string $from, string $to): bool
    {
        return move_uploaded_file($from, $to);
    }
}
