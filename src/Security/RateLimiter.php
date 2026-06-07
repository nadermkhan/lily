<?php

namespace Lily\Security;

use Lily\Cache\CacheEngine;

/**
 * Class RateLimiter
 *
 * Provides rate limiting functionality to prevent abuse of application resources.
 */
class RateLimiter
{
    /**
     * @var CacheEngine The cache engine used for tracking attempts.
     */
    private CacheEngine $cache;

    /**
     * RateLimiter constructor.
     *
     * @param CacheEngine $cache The cache engine dependency.
     */
    public function __construct(CacheEngine $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Attempts a specific action and increments the attempt counter.
     *
     * @param string $key The unique key representing the action and user/IP.
     * @param int $maxAttempts The maximum number of allowed attempts.
     * @param int $decaySeconds The time to live (decay) for the attempt counter in seconds.
     * @return bool True if the attempt is successful, false if the limit is exceeded.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $attempts = (int) $this->cache->get($key, 0);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->cache->set($key, $attempts + 1, $decaySeconds);
        return true;
    }
    
    /**
     * Clears the recorded attempts for a specific key.
     *
     * @param string $key The unique key to clear.
     * @return void
     */
    public function clear(string $key): void
    {
        $this->cache->delete($key);
    }
}
