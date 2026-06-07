<?php

namespace Lily\Security;

use Lily\Http\Request;
use Lily\Http\Response;

/**
 * Class SecurityManager
 *
 * Manages various security features including CSRF tokens, IP resolution, and HTTP headers.
 */
class SecurityManager
{
    /**
     * @var array List of trusted proxy IPs.
     */
    private array $trustedProxies = [];

    /**
     * Set the trusted proxies.
     *
     * @param array $proxies
     * @return self
     */
    public function setTrustedProxies(array $proxies): self
    {
        $this->trustedProxies = $proxies;
        return $this;
    }
    /**
     * Generates a new CSRF token if one does not exist and returns it.
     *
     * @return string The generated or existing CSRF token.
     */
    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validates the CSRF token from the given HTTP request.
     *
     * @param Request $request The HTTP request object.
     * @return bool True if valid, false otherwise.
     */
    public function validateCsrfToken(Request $request): bool
    {
        $token = $request->post['csrf_token'] ?? $request->server['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        if (empty($token) || empty($sessionToken)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Resolves the real IP address of the client from the HTTP request.
     *
     * @param Request $request The HTTP request object.
     * @return string The resolved IP address.
     */
    public function resolveIp(Request $request): string
    {
        $remoteAddr = $request->server['REMOTE_ADDR'] ?? '0.0.0.0';

        if (in_array($remoteAddr, $this->trustedProxies, true) && !empty($request->server['HTTP_X_FORWARDED_FOR'])) {
            $proxies = explode(',', $request->server['HTTP_X_FORWARDED_FOR']);
            return trim($proxies[0]);
        }

        return $remoteAddr;
    }

    /**
     * Applies default security-related HTTP headers to the response.
     *
     * @param Response $response The HTTP response object.
     * @return Response The modified HTTP response object.
     */
    public function applySecurityHeaders(Response $response): Response
    {
        return $response->setHeader('X-Frame-Options', 'DENY')
                        ->setHeader('X-XSS-Protection', '1; mode=block')
                        ->setHeader('X-Content-Type-Options', 'nosniff')
                        ->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
