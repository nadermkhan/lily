<?php

namespace Lily\Support;

use Lily\Http\Request;

/**
 * Provides utility methods to resolve and match domain names.
 */
class DomainResolver
{
    /**
     * Get the host from the request.
     *
     * @param \Lily\Http\Request $request The incoming HTTP request.
     * @return string
     */
    public function getHost(Request $request): string
    {
        return $request->server['HTTP_HOST'] ?? 'localhost';
    }

    /**
     * Get the scheme (http or https) from the request.
     *
     * @param \Lily\Http\Request $request The incoming HTTP request.
     * @return string
     */
    public function getScheme(Request $request): string
    {
        $isSecure = !empty($request->server['HTTPS']) && $request->server['HTTPS'] !== 'off';
        return $isSecure ? 'https' : 'http';
    }

    /**
     * Get the base URL from the request.
     *
     * @param \Lily\Http\Request $request The incoming HTTP request.
     * @return string
     */
    public function getBaseUrl(Request $request): string
    {
        $scheme = $this->getScheme($request);
        $host = $this->getHost($request);
        return "{$scheme}://{$host}";
    }

    /**
     * Normalize a host string by removing scheme and trailing slash.
     *
     * @param string $host The host string to normalize.
     * @return string
     */
    public static function normaliseHost(string $host): string
    {
        $h = strtolower(trim($host));
        if (str_starts_with($h, 'http://')) $h = substr($h, 7);
        if (str_starts_with($h, 'https://')) $h = substr($h, 8);
        return rtrim($h, '/');
    }

    /**
     * Normalize an array or a single string of hosts.
     *
     * @param string|array $hosts The hosts to normalize.
     * @return array
     */
    public static function normaliseHosts(string|array $hosts): array
    {
        $normalized = [];
        foreach ((array) $hosts as $h) {
            $normalized[] = self::normaliseHost($h);
        }
        return $normalized;
    }

    /**
     * Determine if a host matches any of the given patterns.
     *
     * @param array $patterns The array of allowed host patterns.
     * @param string $host The host to check.
     * @return bool
     */
    public static function hostMatches(array $patterns, string $host): bool
    {
        if (empty($patterns)) return true;
        foreach ($patterns as $pattern) {
            $p = self::normaliseHost($pattern);
            if ($p === '' || $p === '*') return true;
            if ($p === $host) return true;

            if (str_starts_with($p, '*.')) {
                $suffix = substr($p, 1); // e.g. ".example.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }
        return false;
    }
}
