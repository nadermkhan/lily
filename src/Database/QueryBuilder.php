<?php

namespace Lily\Database;

/**
 * Class QueryBuilder
 *
 * Provides a fluent interface for building and executing SQL queries.
 */
class QueryBuilder
{
    /**
     * @var Db The database connection instance.
     */
    private Db $db;

    /**
     * @var string The database table to query against.
     */
    private string $table = '';

    /**
     * @var array List of WHERE clause conditions.
     */
    private array $where = [];

    /**
     * @var array Values bound to the query parameters.
     */
    private array $bindings = [];

    /**
     * QueryBuilder constructor.
     *
     * @param Db $db The database connection instance.
     */
    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * Sets the target table for the query.
     *
     * @param string $table The name of the table.
     * @return self Returns the QueryBuilder instance for method chaining.
     */
    public function table(string $table): self
    {
        $this->table = $this->escapeIdentifier($table);
        return $this;
    }

    /**
     * Adds a basic WHERE clause to the query.
     *
     * @param string $column The column to filter by.
     * @param string $operator The comparison operator (e.g., '=', '<', '>', 'LIKE').
     * @param mixed $value The value to compare against the column.
     * @return self Returns the QueryBuilder instance for method chaining.
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper(trim($operator));
        $allowedOperators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];
        if (!in_array($operator, $allowedOperators, true)) {
            throw new \InvalidArgumentException("Invalid SQL operator: $operator");
        }

        $col = $this->escapeIdentifier($column);
        $this->where[] = "{$col} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Executes the query and returns the result set as an array.
     *
     * @return array An array containing all fetched rows.
     */
    public function get(): array
    {
        $sql = "SELECT * FROM {$this->table}";

        if (!empty($this->where)) {
            $sql .= " WHERE " . implode(" AND ", $this->where);
        }

        $stmt = $this->db->query($sql, $this->bindings);
        return $stmt->fetchAll();
    }

    /**
     * Secures identifiers against SQL injection.
     *
     * @param string $identifier The table or column name.
     * @return string
     */
    private function escapeIdentifier(string $identifier): string
    {
        // Strictly allow alphanumeric, underscores, dots, and asterisks.
        return preg_replace('/[^a-zA-Z0-9_.*]/', '', $identifier);
    }
}
