<?php

namespace Lily\Support;

/**
 * A simple file-based caching engine.
 * 
 * Provides methods to get, set, check, and clear cached items.
 */
class CacheEngine
{
    /**
     * The directory where cache files are stored.
     *
     * @var string
     */
    private string $cacheDir;

    /**
     * Create a new CacheEngine instance.
     *
     * @param string $cacheDir The directory to store cache files.
     * @return void
     */
    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * Determine if caching is currently allowed based on the environment.
     *
     * @return bool
     */
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

    /**
     * Set a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to cache.
     * @param int $ttl The time-to-live in seconds.
     * @return void
     */
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

    /**
     * Get a value from the cache.
     *
     * @param string $key The cache key.
     * @param mixed $default The default value if the key does not exist.
     * @return mixed
     */
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

    /**
     * Determine if a key exists in the cache.
     *
     * @param string $key The cache key.
     * @return bool
     */
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

    /**
     * Remove an item from the cache.
     *
     * @param string $key The cache key.
     * @return void
     */
    public function forget(string $key): void
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Clear all cached files.
     *
     * @return void
     */
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

    /**
     * Get the file path for a given cache key.
     *
     * @param string $key The cache key.
     * @return string
     */
    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
