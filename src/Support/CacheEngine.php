<?php

namespace Lily\Support;

class CacheEngine
{
    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $file = $this->getCacheFile($key);
        $data = [
            'expires_at' => time() + $ttl,
            'value' => serialize($value),
        ];
        file_put_contents($file, json_encode($data));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $data = json_decode(file_get_contents($file), true);
        if (time() > $data['expires_at']) {
            unlink($file);
            return $default;
        }

        return unserialize($data['value']);
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
