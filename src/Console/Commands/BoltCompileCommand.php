<?php

namespace Lily\Console\Commands;

use Lily\Auth\Bolt;

class BoltCompileCommand
{
    public function execute(array $args): void
    {
        echo "Compiling Bolt authentication tokens...\n";
        
        $bolt = new Bolt();
        $bolt->compile();
        
        echo "Bolt tokens compiled successfully to storage/auth/tokens.php!\n";
    }
}
