<?php

namespace Lily\Database\Schema;

class ForeignKeyDefinition
{
    public function __construct(
        private string $column,
        private string $references,
        private string $onTable,
        private string $onDelete = 'CASCADE'
    ) {}

    public function onDelete(string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    public function toSql(): string
    {
        return "FOREIGN KEY ({$this->column}) REFERENCES {$this->onTable}({$this->references}) ON DELETE {$this->onDelete}";
    }
}
