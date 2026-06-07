<?php

namespace Lily\Database;

/**
 * Class Factory
 *
 * Abstract factory class for generating and inserting mock data into the database.
 */
abstract class Factory
{
    /**
     * @var Db The database connection instance.
     */
    protected Db $db;

    /**
     * @var string The name of the database table this factory targets.
     */
    protected string $table;

    /**
     * Factory constructor.
     *
     * @param Db $db The database connection instance.
     */
    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Defines the default state and attributes for the generated data.
     *
     * @return array An associative array of column names and their corresponding values.
     */
    abstract public function definition(): array;

    /**
     * Creates and inserts one or more records into the database.
     *
     * @param int $count The number of records to create. Defaults to 1.
     * @return void
     */
    public function create(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $attributes = $this->definition();
            $columns = implode(', ', array_keys($attributes));
            $placeholders = implode(', ', array_fill(0, count($attributes), '?'));
            $values = array_values($attributes);

            $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
            $this->db->query($sql, $values);
        }
    }
}
