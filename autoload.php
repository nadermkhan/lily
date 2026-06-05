<?php

/**
 * Lily Native Autoloader
 * Zero dependencies. Blazing fast.
 */
spl_autoload_register(function ($class) {
    $prefixes = [
        'Lily\\' => __DIR__ . '/src/',
        'App\\'  => __DIR__ . '/app/',
        'Tests\\' => __DIR__ . '/tests/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
