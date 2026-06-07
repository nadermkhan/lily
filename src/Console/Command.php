<?php

namespace Lily\Console;

/**
 * Abstract Class Command
 *
 * Base class for all console commands.
 *
 * @package Lily\Console
 */
abstract class Command
{
    /**
     * Get the name of the command.
     *
     * @return string The command name.
     */
    abstract public function getName(): string;
    
    /**
     * Execute the command.
     *
     * @param array $args The arguments passed to the command.
     * @return int The exit status code.
     */
    abstract public function execute(array $args): int;

    /**
     * Output an informational message to the console.
     *
     * @param string $message The message to output.
     * @return void
     */
    protected function info(string $message): void
    {
        echo "\033[32m{$message}\033[0m\n";
    }

    /**
     * Output an error message to the console.
     *
     * @param string $message The error message to output.
     * @return void
     */
    protected function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }
}
