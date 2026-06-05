<?php

namespace Lily\Cache;

use Lily\Storage\Filesystem;

class CacheEngine
{
    private Filesystem $filesystem;
    private string $cacheDir;

    public function __construct(Filesystem $filesystem, string $cacheDir)
    {
        $this->filesystem = $filesystem;
        $this->cacheDir = rtrim($cacheDir, '/');
        
        if (!$this->filesystem->exists($this->cacheDir)) {
            $this->filesystem->makeDirectory($this->cacheDir, 0777, true);
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $file = $this->getCacheFile($key);
        $data = [
            'expires_at' => time() + $ttl,
            'value' => serialize($value),
        ];
        $this->filesystem->put($file, json_encode($data));
    }

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
    
    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);
        if ($this->filesystem->exists($file)) {
            $this->filesystem->delete($file);
        }
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
