<?php

namespace Lily\Console\Commands;

use Lily\Foundation\Application;

/**
 * Class TinkerCommand
 *
 * Command to run an interactive REPL environment.
 *
 * @package Lily\Console\Commands
 */
class TinkerCommand
{
    /**
     * Execute the Tinker command.
     *
     * @param array $args The arguments passed to the command.
     * @return void
     */
    public function execute(array $args): void
    {
        echo "Lily Tinker REPL\n";
        echo "Type your PHP code and press Enter. Type 'exit' to quit.\n";

        // Get the global application instance context if we want to expose it
        $app = Application::getInstance();

        while (true) {
            $input = readline("lily> ");
            
            if ($input === false || trim($input) === 'exit') {
                echo "Goodbye.\n";
                break;
            }

            if (empty(trim($input))) {
                continue;
            }

            // Keep history
            readline_add_history($input);

            // Add semicolon if missing
            if (substr(trim($input), -1) !== ';') {
                $input .= ';';
            }

            try {
                // Execute code in a closure to catch exceptions safely
                $result = (function() use ($app, $input) {
                    ob_start();
                    try {
                        $res = eval("return " . $input);
                        $out = ob_get_clean();
                        if (!empty($out)) {
                            echo $out . "\n";
                        }
                        return $res;
                    } catch (\Throwable $e) {
                        ob_end_clean();
                        throw $e;
                    }
                })();

                echo "=> " . var_export($result, true) . "\n";
            } catch (\ParseError $e) {
                // If it fails with return, try without return (for loops, classes, etc)
                try {
                    $result = (function() use ($app, $input) {
                        ob_start();
                        eval($input);
                        $out = ob_get_clean();
                        if (!empty($out)) {
                            echo $out . "\n";
                        }
                        return null; // No return value for statements
                    })();
                } catch (\Throwable $e) {
                    echo "Error: " . $e->getMessage() . "\n";
                }
            } catch (\Throwable $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    }
}
