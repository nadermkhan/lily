<?php

namespace Lily\Support;

class Env
{
    private array $env = [];

    public function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $this->env[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->env[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
