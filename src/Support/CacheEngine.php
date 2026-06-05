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

    private function isCachingAllowed(): bool
    {
        // Automatically disable caching if debug mode is on
        $debug = $_ENV['DEBUG_MODE'] ?? $_SERVER['DEBUG_MODE'] ?? getenv('DEBUG_MODE');
        if ($debug !== false && $debug !== null) {
            $debugStr = strtolower((string)$debug);
            if ($debugStr === 'true' || $debugStr === '1') {
                return false;
            }
        } elseif (defined('DEBUG_MODE') && DEBUG_MODE === true) {
            return false;
        }

        $allowed = $_ENV['CACHING_ALLOWED'] ?? $_SERVER['CACHING_ALLOWED'] ?? getenv('CACHING_ALLOWED');
        if ($allowed === null || $allowed === false) {
            return false; // Default to disabled
        }
        $allowed = strtolower((string)$allowed);
        return $allowed === 'true' || $allowed === '1';
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if (!$this->isCachingAllowed()) {
            return;
        }

        $file = $this->getCacheFile($key);
        $data = [
            'expires_at' => time() + $ttl,
            'value' => serialize($value),
        ];
        file_put_contents($file, json_encode($data));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->isCachingAllowed()) {
            return $default;
        }

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

    public function has(string $key): bool
    {
        if (!$this->isCachingAllowed()) {
            return false;
        }

        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file), true);
        if (time() > $data['expires_at']) {
            unlink($file);
            return false;
        }

        return true;
    }

    public function forget(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear(): void
    {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
