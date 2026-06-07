<?php

namespace Lily\Database\Schema;

use Lily\Database\Db;

/**
 * Class Schema
 *
 * Provides operations to create and manipulate database tables.
 */
class Schema
{
    /**
     * @var Db The database connection instance.
     */
    private Db $db;

    /**
     * Schema constructor.
     *
     * @param Db $db The database connection instance.
     */
    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Creates a new database table.
     *
     * @param string $table The name of the table to create.
     * @param callable $callback A callback that receives a Blueprint instance to define columns.
     * @return void
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->buildCreateSql();
        $this->db->query($sql);
    }
    
    /**
     * Drops a database table if it exists.
     *
     * @param string $table The name of the table to drop.
     * @return void
     */
    public function dropIfExists(string $table): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$table}");
    }
}
