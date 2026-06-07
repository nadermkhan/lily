<?php

namespace Lily\Console\Commands;

use Lily\Auth\Bolt;

/**
 * Class BoltCompileCommand
 *
 * Command to compile Bolt authentication tokens.
 *
 * @package Lily\Console\Commands
 */
class BoltCompileCommand
{
    /**
     * Execute the command to compile Bolt tokens.
     *
     * @param array $args The arguments passed to the command.
     * @return void
     */
    public function execute(array $args): void
    {
        echo "Compiling Bolt authentication tokens...\n";
        
        $bolt = new Bolt();
        $bolt->compile();
        
        echo "Bolt tokens compiled successfully to storage/auth/tokens.php!\n";
    }
}
