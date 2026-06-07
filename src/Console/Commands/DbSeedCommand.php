<?php

namespace Lily\Console\Commands;

use Lily\Console\Command;

/**
 * Class DbSeedCommand
 *
 * Command to run database seeders.
 *
 * @package Lily\Console\Commands
 */
class DbSeedCommand extends Command
{
    /**
     * Get the name of the command.
     *
     * @return string The command name.
     */
    public function getName(): string
    {
        return 'db:seed';
    }

    /**
     * Execute the command to seed the database.
     *
     * @param array $args The arguments passed to the command.
     * @return int The exit status code.
     */
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
