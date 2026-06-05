<?php

namespace Lily\Console\Commands;

use Lily\Console\Command;

class MakeModelCommand extends Command
{
    public function getName(): string
    {
        return 'make:model';
    }

    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Please provide a model name.");
            return 1;
        }

        $name = $args[0];
        $path = __DIR__ . '/../../../../app/Models/' . $name . '.php';

        if (file_exists($path)) {
            $this->error("Model already exists.");
            return 1;
        }

        $template = "<?php\n\nnamespace App\Models;\n\nclass {$name}\n{\n    // Model logic\n}\n";

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $template);
        $this->info("Model {$name} created successfully.");

        return 0;
    }
}
