<?php

namespace Lily\Services;

use Lily\Http\Request;
use Lily\Database\Db;

class AnalyticsEngine
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function trackVisit(Request $request): void
    {
        $ip = $request->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $uri = $request->getUri();
        $userAgent = $request->server['HTTP_USER_AGENT'] ?? '';
        
        $sql = "INSERT INTO page_views (ip, uri, user_agent, created_at) VALUES (?, ?, ?, ?)";
        $this->db->query($sql, [$ip, $uri, $userAgent, date('Y-m-d H:i:s')]);
    }

    public function logEvent(string $eventName, array $payload = []): void
    {
        $sql = "INSERT INTO analytics_events (event_name, payload, created_at) VALUES (?, ?, ?)";
        $this->db->query($sql, [$eventName, json_encode($payload), date('Y-m-d H:i:s')]);
    }
}
