<?php

namespace Lily\Console\Commands;

use Lily\Console\Command;

class MakeControllerCommand extends Command
{
    public function getName(): string
    {
        return 'make:controller';
    }

    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Please provide a controller name.");
            return 1;
        }

        $name = $args[0];
        $path = __DIR__ . '/../../../../app/Controllers/' . $name . '.php';

        if (file_exists($path)) {
            $this->error("Controller already exists.");
            return 1;
        }

        $template = "<?php\n\nnamespace App\Controllers;\n\nuse Lily\Http\Request;\nuse Lily\Http\Response;\n\nclass {$name}\n{\n    public function index(Request \$request): Response\n    {\n        return new Response('Hello from {$name}');\n    }\n}\n";

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $template);
        $this->info("Controller {$name} created successfully.");

        return 0;
    }
}
