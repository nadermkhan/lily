<?php
require_once __DIR__ . '/../autoload.php';

use Lily\Testing\ExperimentManager;
use Lily\Services\AnalyticsEngine;
use Lily\Diagnostics\HealthMonitor;
use Lily\Database\Db;
use Lily\Database\Factory;
use App\Controllers\HealthController;
use Lily\Http\Request;
use Lily\Http\Middleware\ExperimentTrafficSplitter;

$errors = 0;

function assertTest($condition, $message) {
    global $errors;
    if (!$condition) {
        echo "❌ FAIL: $message\n";
        $errors++;
    } else {
        echo "✅ PASS: $message\n";
    }
}

try {
    // 1. Mock DB
    $db = new class extends Db {
        public int $queryCount = 0;
        public function __construct() {}
        public function query(string $sql, array $params = []): \PDOStatement|false {
            $this->queryCount++;
            if ($sql === 'SELECT 1') return false; // Return false instead of PDOStatement to avoid clone error
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
    assertTest($variant === 'A', "Empty variants falls back to 'A'");

    // Edge case: Custom weights
    $variant = $manager->resolveVariant('test_exp', ['A', 'B'], [100, 0]);
    assertTest($variant === 'A', "100% weight to A always returns A");

    // Edge case: Inverse Custom weights
    $variant = $manager->resolveVariant('test_exp', ['A', 'B'], [0, 100]);
    assertTest($variant === 'B', "0/100 weight always returns B");

    // Edge case: Missing weights
    $variant = $manager->resolveVariant('test_exp', ['A', 'B', 'C'], [10]);
    assertTest(in_array($variant, ['A', 'B', 'C']), "Missing weights doesn't crash");

    $manager->logAssignment('test_exp', 'B');
    assertTest(count($analytics->logs) === 1, "Analytics logEvent called");

    echo "\n--- Testing HealthMonitor ---\n";

    $monitor = new HealthMonitor($db);
    $status = $monitor->check();
    
    assertTest($status['status'] === 'ok', "Health check returns status ok");
    assertTest($status['database'] === true, "Health check database returns true");
    assertTest(is_bool($status['cache']), "Health check cache is bool");
    assertTest(is_bool($status['disk_space']), "Health check disk_space is bool");

    echo "\n--- Testing Factory ---\n";
    
    $factory = new class($db) extends Factory {
        protected string $table = 'users';
        public function definition(): array {
            return ['name' => 'John', 'email' => 'john@test.com'];
        }
    };
    
    $initialQueryCount = $db->queryCount;
    $factory->create(3); // Create 3 records
    assertTest($db->queryCount === $initialQueryCount + 3, "Factory create(3) runs 3 INSERT queries");

    echo "\n--- Testing ExperimentTrafficSplitter ---\n";
    
    $splitter = new ExperimentTrafficSplitter($manager);
    
    // Test 1: No cookie
    $request1 = new Request([], [], [], [], []);
    $next1 = function($req) {
        return "Variant: " . ($req->getAttribute('X_EXPERIMENT_VARIANT') ?? 'null');
    };
    $response1 = $splitter->handle($request1, $next1);
    assertTest(str_starts_with($response1, "Variant: "), "TrafficSplitter assigns new variant to attribute");

    // Test 2: Existing cookie
    $request2 = new Request([], [], [], [], ['lily_exp_homepage_cta_test' => 'Z']);
    $next2 = function($req) {
        return "Variant: " . ($req->getAttribute('X_EXPERIMENT_VARIANT') ?? 'null');
    };
    $response2 = $splitter->handle($request2, $next2);
    assertTest($response2 === "Variant: Z", "TrafficSplitter respects existing cookie variant");

} catch (\Throwable $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $errors++;
}

exit($errors > 0 ? 1 : 0);
