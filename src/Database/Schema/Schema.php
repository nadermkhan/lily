<?php

namespace Lily\Database\Schema;

use Lily\Database\Db;

class Schema
{
    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->buildCreateSql();
        $this->db->query($sql);
    }
    
    public function dropIfExists(string $table): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$table}");
    }
}
