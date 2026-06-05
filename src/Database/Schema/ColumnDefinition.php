<?php

namespace Lily\Database\Schema;

class ColumnDefinition
{
    public function __construct(
        private string $name,
        private string $type,
        private bool $nullable = false
    ) {}

    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    public function toSql(): string
    {
        $sql = "{$this->name} {$this->type}";
        if (!$this->nullable) {
            $sql .= " NOT NULL";
        }
        return $sql;
    }
}
