<?php

namespace Lily\Tracking;

use Lily\Database\Db;
use Lily\Services\TelegramNotifier;
use Lily\Support\Env;
use Lily\Foundation\Application;

class HotEyesAnalyzer
{
    private Db $db;

    public function __construct()
    {
        $app = Application::getInstance();
        $basePath = $app ? $app->getBasePath() : dirname(__DIR__, 3);
        $dbPath = $basePath . '/database/database.sqlite';
        $this->db = new Db(['dsn' => 'sqlite:' . $dbPath]);
        $this->ensureTableExists();
    }

    private function ensureTableExists(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS hoteyes_footprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                user_id VARCHAR(255) NULL,
                ram INTEGER NULL,
                cpu_cores INTEGER NULL,
                resolution VARCHAR(20) NULL,
                connection_type VARCHAR(20) NULL,
                timezone VARCHAR(100) NULL,
                gpu_renderer VARCHAR(255) NULL,
                hardware_signature VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL
            )
        ");
    }

    public function analyzeAndStore(array $payload, string $ip, ?string $userId = null): void
    {
        // 1. Store footprint
        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO hoteyes_footprints 
            (ip_address, user_id, ram, cpu_cores, resolution, connection_type, timezone, gpu_renderer, hardware_signature, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $ip,
            $userId,
            $payload['ram'] ?? null,
            $payload['cores'] ?? null,
            $payload['resolution'] ?? null,
            $payload['connection'] ?? null,
            $payload['timezone'] ?? null,
            $payload['gpu'] ?? null,
            $payload['signature'] ?? 'unknown',
            date('Y-m-d H:i:s')
        ]);

        // 2. Anomaly Detection: VPN/Proxy Check
        if (!empty($payload['timezone'])) {
            $this->detectVpnProxy($ip, $payload['timezone'], $userId);
        }

        // 3. Anomaly Detection: Hardware-Bound Session Check
        if ($userId) {
            $this->detectSessionHijack($userId, $payload['signature']);
        }
    }

    private function detectVpnProxy(string $ip, string $timezone, ?string $userId): void
    {
        // In a real production scenario, you would query an IP-to-Geo database 
        // (like MaxMind GeoLite2) to get the expected timezone.
        // For demonstration, we'll assume a basic check logic could trigger here.
        
        // Pseudo-logic: If IP GeoTimezone != Browser Timezone
        // $isVpn = (get_geo_timezone($ip) !== $timezone);
        
        // We will simulate the alert trigger if the timezone is suspicious 
        // (e.g. specifically an anon timezone, though browser APIs usually resolve a real one)
        if ($timezone === 'UTC' && $ip !== '127.0.0.1') {
             $this->alertAdmin("Suspicious VPN/Proxy detected!\nIP: {$ip}\nTimezone: {$timezone}\nUser: " . ($userId ?? 'Guest'));
        }
    }

    private function detectSessionHijack(string $userId, string $signature): void
    {
        // Get the historical signature for this user
        $stmt = $this->db->getPdo()->prepare("
            SELECT hardware_signature FROM hoteyes_footprints 
            WHERE user_id = ? AND hardware_signature != ? 
            ORDER BY created_at DESC LIMIT 1
        ");
        
        $stmt->execute([$userId, $signature]);
        $historical = $stmt->fetchColumn();

        // If the user previously logged in with a DIFFERENT signature, flag it!
        // Note: Users can have multiple devices, so an advanced system would check a "known devices" table.
        // For maximum overengineering paranoia, we alert on ANY change.
        if ($historical && $historical !== $signature) {
            $this->alertAdmin("🚨 SESSION HIJACK WARNING 🚨\nUser {$userId} connected with a completely new hardware signature!\nOld: {$historical}\nNew: {$signature}");
            
            // Invalidate the Bolt session (Hardware-Bound Session enforcement)
            // (In a full app, you would revoke the active token here)
        }
    }

    private function alertAdmin(string $message): void
    {
        $botToken = Env::get('TELEGRAM_BOT_TOKEN');
        $chatId = Env::get('TELEGRAM_CHAT_ID');
        
        if ($botToken && $chatId) {
            $notifier = new TelegramNotifier($botToken, $chatId);
            $msg = "👁️ *HOTEYES ALERT* 👁️\n\n" . $message;
            $notifier->sendMessage($msg);
        }
    }
}
