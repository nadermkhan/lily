<?php

namespace Lily\Support;

class Env
{
    private array $env = [];

    public function load(string $path): void
    {
        if (!file_exists($path)) {
            $this->createDefaultEnv($path);
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
                $this->env[$key] = $value;
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    private function createDefaultEnv(string $path): void
    {
        $content = "CACHING_ALLOWED=false\nDISALLOWED_DIRECT_ACCESS=sql,sqlite,db\nSTRICT_TYPE_SAFETY=true\nTELEGRAM_BOT_TOKEN=\nTELEGRAM_CHAT_ID=\n";
        file_put_contents($path, $content);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->env[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($_SERVER, $_ENV, $this->env);
    }

    public function has(string $key): bool
    {
        return isset($this->env[$key]) || isset($_ENV[$key]) || isset($_SERVER[$key]);
    }
}
