<?php

namespace App\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Security\SecurityManager;

class VerifyCsrfToken
{
    private SecurityManager $security;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->getMethod() === 'POST') {
            if (!$this->security->validateCsrfToken($request)) {
                return new Response('CSRF token validation failed.', 403);
            }
        }

        return $next($request);
    }
}
