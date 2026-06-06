<?php

namespace Lily\Database\Schema\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    public string $type;
    public bool $nullable;
    public bool $primary;
    public bool $autoIncrement;

    public function __construct(
        string $type = 'string',
        bool $nullable = false,
        bool $primary = false,
        bool $autoIncrement = false
    ) {
        $this->type = $type;
        $this->nullable = $nullable;
        $this->primary = $primary;
        $this->autoIncrement = $autoIncrement;
    }
}
