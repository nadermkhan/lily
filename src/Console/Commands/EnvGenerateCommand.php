<?php

namespace Lily\Console\Commands;

/**
 * Class EnvGenerateCommand
 *
 * Command to generate the environment configuration files.
 *
 * @package Lily\Console\Commands
 */
class EnvGenerateCommand
{
    /**
     * Execute the command to generate environment files.
     *
     * @param array $args The arguments passed to the command.
     * @return void
     */
    public function execute(array $args): void
    {
        $basePath = dirname(__DIR__, 3);
        $envPath = $basePath . '/.env';
        $examplePath = $basePath . '/.env.example';

        echo "\n  \033[35m✨ Initializing Lily Environment...\033[0m\n\n";

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
            $currentEnv = file_get_contents($envPath);
            if (strpos($currentEnv, 'APP_KEY=') === false) {
                file_put_contents($envPath, "\nAPP_KEY={$key}\n", FILE_APPEND);
                echo "  \033[32m[✓] Added new APP_KEY to existing .env file.\033[0m\n";
            } else {
                echo "  \033[33m[!] Notice: .env file already exists.\033[0m\n      Delete it first to regenerate completely.\n";
            }
        } else {
            file_put_contents($envPath, $template);
            echo "  \033[32m[✓] Successfully generated new .env file!\033[0m\n";
            echo "  \033[32m[✓] Application key [\033[36m{$key}\033[32m] set successfully.\033[0m\n";
        }

        if (!file_exists($examplePath)) {
            $exampleTemplate = str_replace($key, '', $template);
            file_put_contents($examplePath, $exampleTemplate);
            echo "  \033[32m[✓] Created .env.example template.\033[0m\n";
        }
        
        echo "\n";
    }
}
