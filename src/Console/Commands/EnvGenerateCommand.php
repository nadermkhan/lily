<?php

namespace Lily\Console\Commands;

class EnvGenerateCommand
{
    public function execute(array $args): void
    {
        $basePath = dirname(__DIR__, 3);
        $envPath = $basePath . '/.env';
        $examplePath = $basePath . '/.env.example';

        echo "Initializing Lily Environment...\n";

        // Generate a secure 256-bit base64 encoded key
        $key = 'base64:' . base64_encode(random_bytes(32));
        
        $dbPath = $basePath . '/database/database.sqlite';
        $sqliteConfig = file_exists($dbPath) 
            ? "DB_CONNECTION=sqlite\nDB_DATABASE=" . realpath($dbPath) 
            : "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite";

        $template = <<<ENV
APP_ENV=development
APP_KEY={$key}
APP_DEBUG=true
APP_URL=http://localhost:8000

{$sqliteConfig}

# Storage Settings
STORAGE_DRIVER=local

# Telegram Notifications
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=

# FTP Sync Settings (Only used in development)
FTP_HOST=
FTP_USER=
FTP_PASS=
FTP_PORT=21
FTP_SSL=true
ENV;

        if (file_exists($envPath)) {
            // If .env already exists, just update the APP_KEY if it's missing or prompt
            $currentEnv = file_get_contents($envPath);
            if (strpos($currentEnv, 'APP_KEY=') === false) {
                file_put_contents($envPath, "\nAPP_KEY={$key}\n", FILE_APPEND);
                echo "Added new APP_KEY to existing .env file.\n";
            } else {
                echo "Notice: .env file already exists. If you want to regenerate, please delete it first.\n";
            }
        } else {
            file_put_contents($envPath, $template);
            echo "Successfully generated new .env file!\n";
            echo "Application key [{$key}] set successfully.\n";
        }

        // Create .env.example if it doesn't exist to ensure repo consistency
        if (!file_exists($examplePath)) {
            $exampleTemplate = str_replace($key, '', $template);
            file_put_contents($examplePath, $exampleTemplate);
            echo "Created .env.example template.\n";
        }
    }
}
