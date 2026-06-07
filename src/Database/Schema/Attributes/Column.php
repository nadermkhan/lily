<?php

namespace Lily\Database\Schema\Attributes;

/**
 * Class Column
 *
 * Attribute used to define column properties on model classes.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * @var string The SQL data type of the column.
     */
    public string $type;

    /**
     * @var bool Whether the column allows NULL values.
     */
    public bool $nullable;

    /**
     * @var bool Whether the column is a primary key.
     */
    public bool $primary;

    /**
     * @var bool Whether the column is auto-incrementing.
     */
    public bool $autoIncrement;

    /**
     * Column constructor.
     *
     * @param string $type The SQL data type of the column.
     * @param bool $nullable Whether the column allows NULL values.
     * @param bool $primary Whether the column is a primary key.
     * @param bool $autoIncrement Whether the column is auto-incrementing.
     */
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
