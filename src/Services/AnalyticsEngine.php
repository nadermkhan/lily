<?php

namespace Lily\Services;

use Lily\Http\Request;
use Lily\Database\Db;

/**
 * Class AnalyticsEngine
 *
 * Handles recording analytics data such as page visits and custom events.
 *
 * @package Lily\Services
 */
class AnalyticsEngine
{
    /**
     * @var Db The database instance used for storing analytics.
     */
    private Db $db;

    /**
     * AnalyticsEngine constructor.
     *
     * @param Db $db The database instance.
     */
    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Track a page visit using the current HTTP request.
     *
     * @param Request $request The incoming HTTP request.
     * @return void
     */
    public function trackVisit(Request $request): void
    {
        $ip = $request->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $uri = $request->getUri();
        $userAgent = $request->server['HTTP_USER_AGENT'] ?? '';
        
        $sql = "INSERT INTO page_views (ip, uri, user_agent, created_at) VALUES (?, ?, ?, ?)";
        $this->db->query($sql, [$ip, $uri, $userAgent, date('Y-m-d H:i:s')]);
    }

    /**
     * Log a custom analytics event.
     *
     * @param string $eventName The name of the event.
     * @param array $payload Additional data payload for the event.
     * @return void
     */
    public function logEvent(string $eventName, array $payload = []): void
    {
        $sql = "INSERT INTO analytics_events (event_name, payload, created_at) VALUES (?, ?, ?)";
        $this->db->query($sql, [$eventName, json_encode($payload), date('Y-m-d H:i:s')]);
    }
}
