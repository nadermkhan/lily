<?php

namespace Lily\Http\Middleware;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Services\TelegramNotifier;
use Lily\Support\Env;

class RateLimitMiddleware
{
    private string $storageDir;
    private int $maxHitsPerMinute = 60;
    private int $alertThreshold = 200;

    public function __construct()
    {
        $this->storageDir = rtrim('storage/rate_limit', '/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0777, true);
        }
    }

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
