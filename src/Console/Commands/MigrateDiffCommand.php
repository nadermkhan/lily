<?php

namespace Lily\Console\Commands;

use Lily\Database\Db;
use Lily\Database\Schema\Attributes\Column;
use ReflectionClass;

class MigrateDiffCommand
{
    public function execute(array $args): void
    {
        echo "Analyzing Models for schema differences...\n";

        $dbPath = __DIR__ . '/../../../../database/database.sqlite';
        if (!file_exists($dbPath)) {
            @mkdir(dirname($dbPath), 0777, true);
            touch($dbPath);
        }

        $db = new Db(['dsn' => 'sqlite:' . $dbPath]);
        $pdo = $db->getPdo();

        $modelsDir = __DIR__ . '/../../../../app/Models';
        if (!is_dir($modelsDir)) {
            echo "No Models directory found.\n";
            return;
        }

        $files = glob($modelsDir . '/*.php');
        $diffsFound = false;

        foreach ($files as $file) {
            $className = 'App\\Models\\' . basename($file, '.php');
            if (!class_exists($className)) {
                require_once $file;
            }

            if (!class_exists($className)) continue;

            $reflection = new ReflectionClass($className);
            $tableName = strtolower(basename($file, '.php')) . 's';

            $expectedColumns = [];
            foreach ($reflection->getProperties() as $property) {
                $attributes = $property->getAttributes(Column::class);
                if (!empty($attributes)) {
                    $attr = $attributes[0]->newInstance();
                    $expectedColumns[$property->getName()] = $attr;
                }
            }

            if (empty($expectedColumns)) continue;

            // Check if table exists
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$tableName]);
            $tableExists = $stmt->fetch();

            $missingColumns = [];

            if (!$tableExists) {
                $missingColumns = $expectedColumns;
            } else {
                $stmt = $pdo->query("PRAGMA table_info($tableName)");
                $existingColumns = [];
                while ($row = $stmt->fetch()) {
                    $existingColumns[$row['name']] = $row;
                }

                foreach ($expectedColumns as $name => $col) {
                    if (!isset($existingColumns[$name])) {
                        $missingColumns[$name] = $col;
                    }
                }
            }

            if (!empty($missingColumns)) {
                $diffsFound = true;
                $this->generateMigration($tableName, $missingColumns, !$tableExists);
            }
        }

        if (!$diffsFound) {
            echo "No schema differences found. Database is up to date.\n";
        }
    }

    private function generateMigration(string $table, array $columns, bool $isNewTable): void
    {
        $timestamp = date('Y_m_d_His');
        $action = $isNewTable ? "create_{$table}_table" : "update_{$table}_table";
        $filename = "{$timestamp}_{$action}.php";
        $path = __DIR__ . '/../../../../database/migrations/' . $filename;

        $upCode = $isNewTable 
            ? "        \$schema->create('{$table}', function (Blueprint \$table) {\n" 
            : "        \$schema->table('{$table}', function (Blueprint \$table) {\n";

        $downCode = $isNewTable 
            ? "        \$schema->dropIfExists('{$table}');\n" 
            : "        \$schema->table('{$table}', function (Blueprint \$table) {\n";

        foreach ($columns as $name => $col) {
            $type = $col->type;
            if ($name === 'id' || $col->primary) {
                $upCode .= "            \$table->id('{$name}');\n";
            } else {
                $upCode .= "            \$table->{$type}('{$name}');\n";
            }

            if (!$isNewTable) {
                $downCode .= "            \$table->dropColumn('{$name}');\n";
            }
        }

        $upCode .= "        });";
        if (!$isNewTable) {
            $downCode .= "        });";
        }

        $template = <<<PHP
<?php

use Lily\Database\Schema\Schema;
use Lily\Database\Schema\Blueprint;

return new class {
    public function up(Schema \$schema): void
    {
$upCode
    }

    public function down(Schema \$schema): void
    {
$downCode
    }
};

PHP;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $template);
        echo "Generated migration: $filename\n";
    }
}
