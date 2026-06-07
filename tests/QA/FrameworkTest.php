<?php

use Lily\Testing\ExperimentManager;
use Lily\Services\AnalyticsEngine;
use Lily\Diagnostics\HealthMonitor;
use Lily\Database\Db;
use Lily\Database\Factory;
use Lily\Http\Request;
use Lily\Http\Middleware\ExperimentTrafficSplitter;

// Mock DB
$db = new class extends Db {
    public int $queryCount = 0;
    public function __construct() {}
    public function query(string $sql, array $params = []): \PDOStatement|false {
        $this->queryCount++;
        if ($sql === 'SELECT 1') return false;
        if (str_starts_with($sql, 'INSERT INTO analytics_events')) return false;
        if (str_starts_with($sql, 'INSERT INTO users')) return false;
        throw new \Exception("DB Error");
    }
};

echo "--- Testing ExperimentManager ---\n";
$analytics = new class($db) extends AnalyticsEngine {
    public array $logs = [];
    public function logEvent(string $event, array $data = []): void {
        $this->logs[] = ['event' => $event, 'data' => $data];
        parent::logEvent($event, $data);
    }
};

$manager = new ExperimentManager($analytics);

// Edge case: Empty variants
$variant = $manager->resolveVariant('test_exp', [], []);
assertEquals('A', $variant, "Empty variants falls back to 'A'");

// Edge case: Custom weights
$variant = $manager->resolveVariant('test_exp', ['A', 'B'], [100, 0]);
assertEquals('A', $variant, "100% weight to A always returns A");

// Edge case: Inverse Custom weights
$variant = $manager->resolveVariant('test_exp', ['A', 'B'], [0, 100]);
assertEquals('B', $variant, "0/100 weight always returns B");

// Edge case: Missing weights
$variant = $manager->resolveVariant('test_exp', ['A', 'B', 'C'], [10]);
assertTrue(in_array($variant, ['A', 'B', 'C']), "Missing weights doesn't crash");

$manager->logAssignment('test_exp', 'B');
assertEquals(1, count($analytics->logs), "Analytics logEvent called");

echo "\n--- Testing HealthMonitor ---\n";

$monitor = new HealthMonitor($db);
$status = $monitor->check();

assertEquals('ok', $status['status'], "Health check returns status ok");
assertTrue($status['database'], "Health check database returns true");
assertTrue(is_bool($status['cache']), "Health check cache is bool");
assertTrue(is_bool($status['disk_space']), "Health check disk_space is bool");

echo "\n--- Testing Factory ---\n";

$factory = new class($db) extends Factory {
    protected string $table = 'users';
    public function definition(): array {
        return ['name' => 'John', 'email' => 'john@test.com'];
    }
};

$initialQueryCount = $db->queryCount;
$factory->create(3); // Create 3 records
assertEquals($initialQueryCount + 3, $db->queryCount, "Factory create(3) runs 3 INSERT queries");

echo "\n--- Testing ExperimentTrafficSplitter ---\n";

$splitter = new ExperimentTrafficSplitter($manager);

// Test 1: No cookie
$request1 = new Request([], [], [], [], []);
$next1 = function($req) {
    return "Variant: " . ($req->getAttribute('X_EXPERIMENT_VARIANT') ?? 'null');
};
$response1 = $splitter->handle($request1, $next1);
assertTrue(str_starts_with($response1, "Variant: "), "TrafficSplitter assigns new variant to attribute");

// Test 2: Existing cookie
$request2 = new Request([], [], [], [], ['lily_exp_homepage_cta_test' => 'Z']);
$next2 = function($req) {
    return "Variant: " . ($req->getAttribute('X_EXPERIMENT_VARIANT') ?? 'null');
};
$response2 = $splitter->handle($request2, $next2);
assertEquals("Variant: Z", $response2, "TrafficSplitter respects existing cookie variant");
