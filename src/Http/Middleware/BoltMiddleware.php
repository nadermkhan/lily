<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Foundation\Application;

class BoltMiddleware
{
    private string $cacheFile;

    public function __construct()
    {
        $app = Application::getInstance();
        $basePath = $app ? $app->getBasePath() : dirname(__DIR__, 3);
        $this->cacheFile = $basePath . '/storage/auth/tokens.php';
    }

    public function handle(Request $request, callable $next): Response
    {
        $header = $request->server['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($header) || !str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }

        $plainTextToken = substr($header, 7);
        $hash = hash('sha256', $plainTextToken);

        if (!file_exists($this->cacheFile)) {
            return $this->unauthorized();
        }

        // Extremely fast OPcache load
        $tokens = require $this->cacheFile;

        if (!isset($tokens[$hash])) {
            return $this->unauthorized();
        }

        // Attach user_id to the request
        $request->user_id = $tokens[$hash];

        return $next($request);
    }

    private function unauthorized(): Response
    {
        $response = new Response();
        $response->setStatusCode(401);
        $response->setContent(json_encode(['error' => 'Unauthenticated.']));
        $response->headers['Content-Type'] = 'application/json';
        return $response;
    }
}
