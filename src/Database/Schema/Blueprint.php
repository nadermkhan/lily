<?php

namespace Lily\Database\Schema;

class Blueprint
{
    private string $table;
    /** @var ColumnDefinition[] */
    private array $columns = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function id(): ColumnDefinition
    {
        $column = new ColumnDefinition('id', 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $this->columns[] = $column;
        return $column;
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "VARCHAR({$length})");
        $this->columns[] = $column;
        return $column;
    }

    public function buildCreateSql(): string
    {
        $columnsSql = array_map(fn($col) => $col->toSql(), $this->columns);
        return "CREATE TABLE {$this->table} (" . implode(', ', $columnsSql) . ")";
    }
}
