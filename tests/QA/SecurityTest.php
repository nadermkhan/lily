<?php

use Lily\Security\SecurityManager;
use Lily\Security\SecurityLayer;
use Lily\Database\QueryBuilder;
use Lily\Database\Db;
use Lily\Http\Request;

echo "--- Testing SecurityManager IP Resolution ---\n";
$manager = new SecurityManager();
$request1 = new Request([], [], ['REMOTE_ADDR' => '1.2.3.4'], [], []);
assertEquals('1.2.3.4', $manager->resolveIp($request1), "Default to REMOTE_ADDR");

$request2 = new Request([], [], [
    'REMOTE_ADDR' => '1.2.3.4',
    'HTTP_X_FORWARDED_FOR' => '9.9.9.9'
], [], []);
assertEquals('1.2.3.4', $manager->resolveIp($request2), "Ignore X-Forwarded-For if not trusted");

$manager->setTrustedProxies(['1.2.3.4']);
assertEquals('9.9.9.9', $manager->resolveIp($request2), "Use X-Forwarded-For if proxy is trusted");

echo "--- Testing SecurityManager CSRF Validation ---\n";
$_SESSION = [];
$request3 = new Request([], [], [], [], []);
// Empty token and empty session should securely return false without crashing
assertFalse($manager->validateCsrfToken($request3), "Empty tokens should fail validation gracefully");

$_SESSION['csrf_token'] = 'valid_token';
$request4 = new Request([], ['csrf_token' => 'valid_token'], [], [], []);
assertTrue($manager->validateCsrfToken($request4), "Valid token matches");

$request5 = new Request([], ['csrf_token' => 'invalid_token'], [], [], []);
assertFalse($manager->validateCsrfToken($request5), "Invalid token fails");

echo "--- Testing SecurityLayer CSRF Validation ---\n";
$layer = new SecurityLayer();
$_SESSION = [];
assertFalse($layer->validateCsrfToken(''), "Empty tokens in SecurityLayer should fail gracefully");
$_SESSION['csrf_token'] = 'token123';
assertTrue($layer->validateCsrfToken('token123'), "Valid token in SecurityLayer");

echo "--- Testing QueryBuilder SQL Injection Prevention ---\n";
$db = new class extends Db {
    public function __construct() {}
    public function query(string $sql, array $params = []): \PDOStatement|false {
        return false;
    }
};

$qb = new QueryBuilder($db);

// Test table escaping
$qb->table("users` OR 1=1;--");
// Reflection to check property
$reflection = new \ReflectionClass($qb);
$prop = $reflection->getProperty('table');
$tableVal = $prop->getValue($qb);
assertEquals("usersOR11", $tableVal, "Table name should strip malicious characters");

// Test invalid operator
assertThrows(function() use ($qb) {
    $qb->where('id', 'INVALID_OP', 1);
}, \InvalidArgumentException::class, "Invalid SQL operator throws exception");

// Test valid operator and column escaping
$qb->where("id` = 1;--", "=", 1);
$propWhere = $reflection->getProperty('where');
$whereVal = $propWhere->getValue($qb);
assertEquals("id1 =", substr($whereVal[0], 0, 5), "Column name should strip malicious characters");

