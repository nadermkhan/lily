<?php

namespace Lily\Security;

/**
 * Class SecurityLayer
 *
 * Provides basic security utilities such as input sanitization and CSRF token management.
 */
class SecurityLayer
{
    /**
     * Sanitizes a given input string to prevent XSS.
     *
     * @param string $input The string to sanitize.
     * @return string The sanitized string.
     */
    public function sanitize(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
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
     * Validates a given CSRF token against the session.
     *
     * @param string $token The CSRF token to validate.
     * @return bool True if valid, false otherwise.
     */
    public function validateCsrfToken(string $token): bool
    {
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}
