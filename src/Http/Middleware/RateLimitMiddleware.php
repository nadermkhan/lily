<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Services\TelegramNotifier;
use Lily\Support\Env;

/**
 * Middleware to rate-limit incoming HTTP requests.
 */
class RateLimitMiddleware
{
    /**
     * The directory where rate limit hits are stored.
     *
     * @var string
     */
    private string $storageDir;

    /**
     * The maximum number of allowed hits per minute.
     *
     * @var int
     */
    private int $maxHitsPerMinute = 60;

    /**
     * The threshold at which an alert is sent.
     *
     * @var int
     */
    private int $alertThreshold = 200;

    /**
     * Create a new RateLimitMiddleware instance.
     */
    public function __construct()
    {
        $this->storageDir = rtrim('storage/rate_limit', '/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

    /**
     * Handle the incoming request.
     *
     * @param Request $request The incoming request.
     * @param callable $next The next middleware or handler in the pipeline.
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        $ip = $request->server['REMOTE_ADDR'] ?? '127.0.0.1';
        $timeWindow = date('Y-m-d-H-i'); // minute window
        
        $file = $this->storageDir . '/' . md5($ip . $timeWindow) . '.hits';
        
        $hits = 1;
        if (file_exists($file)) {
            $hits = (int)file_get_contents($file) + 1;
        }
        
        file_put_contents($file, $hits);

        if ($hits > $this->maxHitsPerMinute) {
            
            if ($hits === $this->alertThreshold) {
                $this->alertAdmin($ip);
            }
            
            $response = new Response();
            $response->setStatusCode(429);
            $response->setContent(json_encode(['error' => 'Too Many Requests']));
            $response->headers['Content-Type'] = 'application/json';
            return $response;
        }

        return $next($request);
    }

    /**
     * Send an alert notification to the administrator.
     *
     * @param string $ip The IP address that triggered the alert.
     * @return void
     */
    private function alertAdmin(string $ip): void
    {
        $botToken = Env::get('TELEGRAM_BOT_TOKEN');
        $chatId = Env::get('TELEGRAM_CHAT_ID');
        
        if ($botToken && $chatId) {
            $notifier = new TelegramNotifier($botToken, $chatId);
            $msg = "🚨 *LILY FORTRESS ALERT* 🚨\n\nPotential DDoS or Brute-force detected.\nIP: `{$ip}`\nHits: `{$this->alertThreshold}+ / min`";
            $notifier->sendMessage($msg);
        }
    }
}
