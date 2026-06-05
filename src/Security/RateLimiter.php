<?php

namespace Lily\Security;

use Lily\Cache\CacheEngine;

class RateLimiter
{
    private CacheEngine $cache;

    public function __construct(CacheEngine $cache)
    {
        $this->cache = $cache;
    }

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $attempts = (int) $this->cache->get($key, 0);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->cache->set($key, $attempts + 1, $decaySeconds);
        return true;
    }
    
    public function clear(string $key): void
    {
        $this->cache->delete($key);
    }
}
