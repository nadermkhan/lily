<?php

namespace App\Controllers;

use Lily\Http\Request;
use Lily\Http\Response;
use Lily\Diagnostics\HealthMonitor;

class HealthController
{
    public function __construct(private HealthMonitor $monitor) {}

    public function check(Request $request): Response
    {
        $status = $this->monitor->check();
        
        $httpCode = $status['database'] && $status['cache'] ? 200 : 503;

        return new Response(json_encode($status), $httpCode, ['Content-Type' => 'application/json']);
    }
}
