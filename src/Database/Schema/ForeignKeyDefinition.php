<?php

namespace Lily\Database\Schema;

/**
 * Class ForeignKeyDefinition
 *
 * Represents the definition of a foreign key constraint for a database table.
 */
class ForeignKeyDefinition
{
    /**
     * ForeignKeyDefinition constructor.
     *
     * @param string $column The local column name.
     * @param string $references The referenced column name on the foreign table.
     * @param string $onTable The foreign table name.
     * @param string $onDelete The action to perform on deletion (e.g., 'CASCADE').
     */
    public function __construct(
        private string $column,
        private string $references,
        private string $onTable,
        private string $onDelete = 'CASCADE'
    ) {}

    /**
     * Sets the action to perform on deletion.
     *
     * @param string $action The action to perform (e.g., 'SET NULL', 'CASCADE').
     * @return self Returns the instance for method chaining.
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    /**
     * Converts the foreign key definition into its SQL string representation.
     *
     * @return string The SQL fragment for the foreign key.
     */
    public function toSql(): string
    {
        return "FOREIGN KEY ({$this->column}) REFERENCES {$this->onTable}({$this->references}) ON DELETE {$this->onDelete}";
    }
}
