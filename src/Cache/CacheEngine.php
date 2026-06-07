<?php

namespace Lily\Cache;

use Lily\Storage\Filesystem;

/**
 * Class CacheEngine
 *
 * Provides file-based caching functionality.
 *
 * @package Lily\Cache
 */
class CacheEngine
{
    /**
     * @var Filesystem The filesystem instance used for cache storage.
     */
    private Filesystem $filesystem;

    /**
     * @var string The directory where cache files are stored.
     */
    private string $cacheDir;

    /**
     * CacheEngine constructor.
     *
     * @param Filesystem $filesystem The filesystem instance.
     * @param string $cacheDir The directory to store cache files.
     */
    public function __construct(Filesystem $filesystem, string $cacheDir)
    {
        $this->filesystem = $filesystem;
        $this->cacheDir = rtrim($cacheDir, '/');
        
        if (!$this->filesystem->exists($this->cacheDir)) {
            $this->filesystem->makeDirectory($this->cacheDir, 0777, true);
        }
    }

    /**
     * Set a value in the cache.
     *
     * @param string $key The cache key.
     * @param mixed $value The value to cache.
     * @param int $ttl Time to live in seconds (default 3600).
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $file = $this->getCacheFile($key);
        $data = [
            'expires_at' => time() + $ttl,
            'value' => serialize($value),
        ];
        $this->filesystem->put($file, json_encode($data));
    }

    /**
     * Retrieve a value from the cache.
     *
     * @param string $key The cache key.
     * @param mixed $default The default value to return if the key is not found or expired.
     * @return mixed The cached value or the default value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getCacheFile($key);
        
        if (!$this->filesystem->exists($file)) {
            return $default;
        }

        $content = $this->filesystem->get($file);
        $data = json_decode($content, true);
        
        if (time() > $data['expires_at']) {
            $this->filesystem->delete($file);
            return $default;
        }

        return unserialize($data['value']);
    }
    
    /**
     * Delete a value from the cache.
     *
     * @param string $key The cache key.
     * @return void
     */
    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        if ($this->filesystem->exists($file)) {
            $this->filesystem->delete($file);
        }
    }

    /**
     * Get the file path for a given cache key.
     *
     * @param string $key The cache key.
     * @return string The absolute path to the cache file.
     */
    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
