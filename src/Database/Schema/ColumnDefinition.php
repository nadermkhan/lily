<?php

namespace Lily\Database\Schema;

/**
 * Class ColumnDefinition
 *
 * Represents the definition of a single database table column.
 */
class ColumnDefinition
{
    /**
     * ColumnDefinition constructor.
     *
     * @param string $name The name of the column.
     * @param string $type The SQL data type of the column.
     * @param bool $nullable Whether the column allows NULL values.
     */
    public function __construct(
        private string $name,
        private string $type,
        private bool $nullable = false
    ) {}

    /**
     * Sets the column to allow NULL values.
     *
     * @return self Returns the instance for method chaining.
     */
    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    /**
     * Converts the column definition into its SQL string representation.
     *
     * @return string The SQL fragment for the column.
     */
    public function toSql(): string
    {
        $sql = "{$this->name} {$this->type}";
        if (!$this->nullable) {
            $sql .= " NOT NULL";
        }
        return $sql;
    }
}
