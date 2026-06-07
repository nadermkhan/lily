<?php

namespace Lily\Console\Commands;

use Lily\Console\Command;

/**
 * Class MakeMigrationCommand
 *
 * Command to create a new database migration.
 *
 * @package Lily\Console\Commands
 */
class MakeMigrationCommand extends Command
{
    /**
     * Get the name of the command.
     *
     * @return string The command name.
     */
    public function getName(): string
    {
        return 'make:migration';
    }

    /**
     * Execute the command to make a migration.
     *
     * @param array $args The arguments passed to the command.
     * @return int The exit status code.
     */
    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Please provide a migration name.");
            return 1;
        }

        $name = $args[0];
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $path = __DIR__ . '/../../../../database/migrations/' . $filename;

        $template = "<?php\n\nuse Lily\Database\Schema\Schema;\nuse Lily\Database\Schema\Blueprint;\n\nreturn new class {\n    public function up(Schema \$schema): void\n    {\n        // \$schema->create('table_name', function (Blueprint \$table) {\n        //     \$table->id();\n        //     \$table->string('name');\n        // });\n    }\n\n    public function down(Schema \$schema): void\n    {\n        // \$schema->dropIfExists('table_name');\n    }\n};\n";

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $template);
        $this->info("Migration {$filename} created successfully.");

        return 0;
    }
}
