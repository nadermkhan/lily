<?php

require_once __DIR__ . '/../autoload.php';

$errors = 0;

function assertEquals($expected, $actual, $message = '') {
    global $errors;
    if ($expected !== $actual) {
        $msg = $message ?: "Expected " . print_r($expected, true) . ", got " . print_r($actual, true);
        echo "❌ FAIL: $msg\n";
        $errors++;
    } else {
        $msg = $message ?: "Assertion passed";
        echo "✅ PASS: $msg\n";
    }
}

function assertTrue($condition, $message = '') {
    global $errors;
    if ($condition !== true) {
        $msg = $message ?: "Expected true, got " . print_r($condition, true);
        echo "❌ FAIL: $msg\n";
        $errors++;
    } else {
        $msg = $message ?: "Assertion passed";
        echo "✅ PASS: $msg\n";
    }
}

function assertFalse($condition, $message = '') {
    global $errors;
    if ($condition !== false) {
        $msg = $message ?: "Expected false, got " . print_r($condition, true);
        echo "❌ FAIL: $msg\n";
        $errors++;
    } else {
        $msg = $message ?: "Assertion passed";
        echo "✅ PASS: $msg\n";
    }
}

function assertThrows(callable $callback, string $exceptionClass = Exception::class, $message = '') {
    global $errors;
    try {
        $callback();
        $msg = $message ?: "Expected exception $exceptionClass to be thrown, but none was thrown.";
        echo "❌ FAIL: $msg\n";
        $errors++;
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            $msg = $message ?: "Expected exception $exceptionClass thrown successfully.";
            echo "✅ PASS: $msg\n";
        } else {
            $msg = $message ?: "Expected exception $exceptionClass, got " . get_class($e);
            echo "❌ FAIL: $msg\n";
            $errors++;
        }
    }
}

try {
    $qaDir = __DIR__ . '/QA';
    if (is_dir($qaDir)) {
        $files = glob($qaDir . '/*Test.php');
        foreach ($files as $file) {
            echo "\n========== Running " . basename($file) . " ==========\n";
            require_once $file;
        }
    } else {
        echo "No QA directory found.\n";
    }
} catch (\Throwable $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $errors++;
}

exit($errors > 0 ? 1 : 0);
