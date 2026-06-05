<?php

namespace Lily\Database;

abstract class Factory
{
    protected Db $db;
    protected string $table;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    abstract public function definition(): array;

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
