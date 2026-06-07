<?php

namespace Lily\Diagnostics;

use Lily\Database\Db;

/**
 * Class HealthMonitor
 *
 * Provides system health monitoring and diagnostic checks.
 *
 * @package Lily\Diagnostics
 */
class HealthMonitor
{
    /**
     * HealthMonitor constructor.
     *
     * @param Db $db The database instance.
     */
    public function __construct(private Db $db) {}

    /**
     * Perform all health checks.
     *
     * @return array An array containing health check results.
     */
    public function check(): array
    {
        return [
            'status' => 'ok',
            'timestamp' => time(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCacheDir(),
            'disk_space' => $this->checkDiskSpace()
        ];
    }

    /**
     * Check database connectivity.
     *
     * @return bool True if connected successfully, false otherwise.
     */
    private function checkDatabase(): bool
    {
        try {
            $this->db->query("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the cache directory is writable.
     *
     * @return bool True if writable, false otherwise.
     */
    private function checkCacheDir(): bool
    {
        $cacheDir = defined('NCACHE_DIR') ? NCACHE_DIR : dirname(__DIR__, 2) . '/.ncache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }
        return is_writable($cacheDir);
    }

    /**
     * Check available disk space.
     *
     * @return bool True if there is at least 50MB of free space, false otherwise.
     */
    private function checkDiskSpace(): bool
    {
        $freeSpace = disk_free_space(dirname(__DIR__, 2));
        // Require at least 50MB free
        return $freeSpace !== false && $freeSpace > 50 * 1024 * 1024;
    }
}
