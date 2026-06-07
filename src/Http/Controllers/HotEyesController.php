<?php

namespace Lily\Http\Controllers;

use Lily\Tracking\HotEyesAnalyzer;
use Lily\Http\Response;

/**
 * Controller to handle hot eyes tracking payload.
 */
class HotEyesController
{
    /**
     * Handle the incoming request.
     *
     * @return Response
     */
    public function handle(): Response
    {
        $input = file_get_contents('php://input');
        $payload = json_decode($input, true);

        if ($payload) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            // Attempt to resolve User ID (If they sent an Authorization header, or session)
            $userId = null;
            $headers = getallheaders();
            if (isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer ')) {
                $token = substr($headers['Authorization'], 7);
                $hash = hash('sha256', $token);
                // Quick Bolt lookup
                $cacheFile = dirname(__DIR__, 3) . '/storage/auth/tokens.php';
                if (file_exists($cacheFile)) {
                    $tokens = require $cacheFile;
                    if (isset($tokens[$hash])) {
                        $userId = $tokens[$hash];
                    }
                }
            }

            $analyzer = new HotEyesAnalyzer();
            $analyzer->analyzeAndStore($payload, $ip, $userId);
        }

        $response = new Response();
        $response->setStatusCode(204); // No Content
        return $response;
    }
}
