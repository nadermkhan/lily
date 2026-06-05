<?php

namespace Lily\Security;

use Lily\Http\Request;
use Lily\Http\Response;

class SecurityManager
{
    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function validateCsrfToken(Request $request): bool
    {
        $token = $request->post['csrf_token'] ?? $request->server['HTTP_X_CSRF_TOKEN'] ?? '';
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    public function resolveIp(Request $request): string
    {
        return $request->server['HTTP_X_FORWARDED_FOR'] ?? $request->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function applySecurityHeaders(Response $response): Response
    {
        return $response->setHeader('X-Frame-Options', 'DENY')
                        ->setHeader('X-XSS-Protection', '1; mode=block')
                        ->setHeader('X-Content-Type-Options', 'nosniff')
                        ->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
