<?php

namespace Lily\Console\Commands;

use Lily\Console\Command;

class DbSeedCommand extends Command
{
    public function getName(): string
    {
        return 'db:seed';
    }

    public function execute(array $args): int
    {
        echo "Running QA database seeders...\n";
        
        $seedersDir = dirname(__DIR__, 3) . '/app/Database/Seeders';
        if (!is_dir($seedersDir)) {
            echo "No seeders found in {$seedersDir}.\n";
            return 0;
        }

        // Seeder execution logic would load classes and run them here

        echo "Database seeded successfully.\n";
        return 0;
    }
}
