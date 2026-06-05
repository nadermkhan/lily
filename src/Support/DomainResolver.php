<?php

namespace Lily\Support;

use Lily\Http\Request;

class DomainResolver
{
    public function getHost(Request $request): string
    {
        return $request->server['HTTP_HOST'] ?? 'localhost';
    }

    public function getScheme(Request $request): string
    {
        $isSecure = !empty($request->server['HTTPS']) && $request->server['HTTPS'] !== 'off';
        return $isSecure ? 'https' : 'http';
    }

    public function getBaseUrl(Request $request): string
    {
        $scheme = $this->getScheme($request);
        $host = $this->getHost($request);
        return "{$scheme}://{$host}";
    }

    public static function normaliseHost(string $host): string
    {
        $h = strtolower(trim($host));
        if (str_starts_with($h, 'http://')) $h = substr($h, 7);
        if (str_starts_with($h, 'https://')) $h = substr($h, 8);
        return rtrim($h, '/');
    }

    public static function normaliseHosts(string|array $hosts): array
    {
        $normalized = [];
        foreach ((array) $hosts as $h) {
            $normalized[] = self::normaliseHost($h);
        }
        return $normalized;
    }

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
