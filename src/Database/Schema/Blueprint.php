<?php

namespace Lily\Database\Schema;

/**
 * Class Blueprint
 *
 * Represents the structure of a database table during creation or modification.
 */
class Blueprint
{
    /**
     * @var string The name of the table being constructed.
     */
    private string $table;

    /**
     * @var ColumnDefinition[] Array of column definitions for the table.
     */
    private array $columns = [];

    /**
     * Blueprint constructor.
     *
     * @param string $table The name of the table.
     */
    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * Adds an auto-incrementing integer 'id' primary key column.
     *
     * @return ColumnDefinition The created column definition.
     */
    public function id(): ColumnDefinition
    {
        $column = new ColumnDefinition('id', 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Adds a string (VARCHAR) column to the table.
     *
     * @param string $name The name of the column.
     * @param int $length The maximum length of the string. Defaults to 255.
     * @return ColumnDefinition The created column definition.
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $column = new ColumnDefinition($name, "VARCHAR({$length})");
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Builds and returns the CREATE TABLE SQL statement for this blueprint.
     *
     * @return string The generated SQL statement.
     */
    public function buildCreateSql(): string
    {
        $columnsSql = array_map(fn($col) => $col->toSql(), $this->columns);
        return "CREATE TABLE {$this->table} (" . implode(', ', $columnsSql) . ")";
    }
}
