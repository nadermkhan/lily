<?php

namespace Lily\Support;

class Env
{
    private static array $env = [];

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            self::createDefaultEnv($path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                self::$env[$key] = $value;
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    private static function createDefaultEnv(string $path): void
    {
        $content = "APP_ENV=development\nCACHING_ALLOWED=false\nDISALLOWED_DIRECT_ACCESS=sql,sqlite,db\nSTRICT_TYPE_SAFETY=true\nSTORAGE=local\nTELEGRAM_BOT_TOKEN=\nTELEGRAM_CHAT_ID=\nFTP_HOST=\nFTP_PORT=21\nFTP_USER=\nFTP_PASS=\nFTP_ROOT=/\nFTP_SECURE=true\n";
        file_put_contents($path, $content);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$env[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public static function all(): array
    {
        return array_merge($_SERVER, $_ENV, self::$env);
    }

    public static function has(string $key): bool
    {
        return isset(self::$env[$key]) || isset($_ENV[$key]) || isset($_SERVER[$key]);
    }
}
